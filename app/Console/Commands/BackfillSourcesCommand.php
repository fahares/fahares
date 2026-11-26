<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Support\EntryColumns;
use App\Sources\SourceEntrySync as Sync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies the site's sources into the one registry of step 13.1:
 *
 * - registry: catalogs (same ids) and the reference works of config('bibliography.sources') become sources with
 *   their volumes; catalog_volumes become source_volumes (same ids) under one source per library and title, the
 *   volume's title without its last « - …» part (a later printing, edition_of_id, is a source of its own).
 * - references: the library catalogue page Fankha cites for each manuscript (SourceEntrySync::citedValues, from its
 *   metadata and its Fankha entry's text).
 * - bibliography: each work's bibliography entries read again, with the places they cite of the sources the site knows.
 *
 * The part «fankha» went in step 14.407: a record's Fankha entry and bibliography have no column to be copied from.
 *
 * Every row keeps the row it came from (legacy_type, legacy_id, legacy_index), so running it again updates what
 * changed and removes what is gone. The parts «addenda» (source_citations), «evidence» (field_evidence) and the
 * catalogue matches of «references» (manuscript_catalog_references) went with those tables in step 14.382
 * (backlog item 69): since step 13.3 their entries are written in source_entries alone.
 */
class BackfillSourcesCommand extends Command
{
    protected $signature = 'sources:backfill
        {--only=* : registry, references, bibliography (default: all, in this order)}
        {--dry-run : Count what would be written without writing}';

    protected $description = 'Copy catalogs and Fankha\'s catalogue citations into sources and source_entries, and read the bibliographies again';

    public const PARTS = ['registry', 'references', 'bibliography'];

    /** The kind of each reference work of config('bibliography.sources'). */
    public const CONFIG_KINDS = [
        'dharia' => Source::KIND_BIBLIOGRAPHY,
        'kashf' => Source::KIND_BIBLIOGRAPHY,
        'idah' => Source::KIND_BIBLIOGRAPHY,
        'hadiyya' => Source::KIND_BIBLIOGRAPHY,
        'rayhana' => Source::KIND_BIOGRAPHICAL,
        'kahhala' => Source::KIND_BIOGRAPHICAL,
        'zirikli' => Source::KIND_BIOGRAPHICAL,
    ];

    private const CHUNK = 1000;

    private bool $dry;

    /** [source_id][volume_number] => source_volumes.id */
    private array $volumeIds = [];

    /** config key => sources.id */
    private array $configSources = [];

    /** source_volumes.id => sources.id */
    private array $volumeSources = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $parts = $this->option('only') ?: self::PARTS;
        if ($unknown = array_diff($parts, self::PARTS)) {
            $this->error('Unknown part: ' . implode(', ', $unknown));

            return self::FAILURE;
        }

        foreach (self::PARTS as $part) {
            if (! in_array($part, $parts, true)) {
                continue;
            }
            // the other parts look sources and volumes up in the registry
            $part === 'registry' || $this->loadRegistry();
            $stats = $this->{$part}();
            $this->info($part);
            $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
        }
        // «منابع» counts what each source is cited for and keeps it a day: a source made or tied here shows at once
        $this->dry || \Illuminate\Support\Facades\Cache::forget('sources:shelf');
        $this->dry && $this->warn('Dry run: nothing is saved.');

        return self::SUCCESS;
    }

    private function registry(): array
    {
        $stats = ['catalogs' => 0, 'reference works' => 0, 'catalogue sources' => 0, 'volumes copied' => 0, 'volumes made' => 0];
        $now = now();

        foreach (DB::table('catalogs')->orderBy('id')->get() as $c) {
            $this->upsertSource(['id' => $c->id], Sync::catalogSourceValues($c));
            $stats['catalogs']++;
            // Fankha's page images are the viewer's default folder, storage/app/public/catalogs/fankha
            $stats['volumes made'] += Sync::ensureVolumes($c->id, range(1, max(1, (int) $c->volumes_count)), $c->code === 'fankha' ? 'fankha' : null, null, $this->dry);
        }

        foreach (config('bibliography.sources') as $key => $src) {
            $kind = $src['kind'] ?? self::CONFIG_KINDS[$key] ?? Source::KIND_OTHER;
            // the page images the viewer shows, or the page texts of a source the site has as text only
            $pages = $src['viewer'] ?? $src['text'] ?? [];
            $id = $this->upsertSource(['code' => $key], [
                'kind' => $kind,
                'rank' => Source::KIND_RANKS[$kind],
                'title' => $src['label'],
                'short_name' => $pages['name'] ?? $src['label'],
                'volumes_count' => $src['volumes'],
                'unit' => ($src['unit'] ?? null) === 'ستون' ? 'ستون' : 'صفحه',
                'metadata' => json_encode(['config' => $key] + (isset($src['viewer']) || ! isset($src['text']) ? [] : ['images' => false]), JSON_UNESCAPED_UNICODE),
            ] + array_intersect_key($src, array_flip(['compiler', 'publisher', 'edition_label'])));
            $stats['reference works']++;
            $stats['volumes made'] += Sync::ensureVolumes($id, range(1, $src['volumes']), $pages['catalog'] ?? null,
                fn ($v) => ['page_first' => $pages['first_pages'][$v] ?? ($pages ? 1 : null), 'page_last' => $pages['last_pages'][$v] ?? null], $this->dry);
        }

        // catalog_volumes: one source per library and title, and one per later printing
        $volumes = DB::table('catalog_volumes')->orderBy('id')->get();
        $groups = [];
        foreach ($volumes as $v) {
            $groups[Sync::groupKey($v)][] = $v;
        }
        $groupSource = [];
        // a catalogue sources:merge-split folded keeps its title and codes: its groups only point at it, and it counts them
        $folded = [];
        foreach ($groups as $key => $members) {
            $first = $members[0];
            $code = Sync::groupCode($key, $first);
            if ($into = Source::where('code', $code)->whereNotNull('metadata->merged_codes')->first() ?? Source::mergedInto($code)) {
                $groupSource[$key] = $into->id;
                $folded[$into->id] = ($folded[$into->id] ?? 0) + count($members);
                $stats['catalogue sources']++;

                continue;
            }
            // a source whose volumes moved to another library keeps the code it was made with, and is found by its group
            $found = DB::table('sources')->where('code', $code)->value('id') ?? DB::table('sources')->where('metadata->group', $key)->value('id');
            $groupSource[$key] = $this->upsertSource($found ? ['id' => $found] : ['code' => $code], [
                'kind' => Source::KIND_LIBRARY_CATALOGUE,
                'rank' => Source::KIND_RANKS[Source::KIND_LIBRARY_CATALOGUE],
                'title' => Sync::stem($first->title),
                'library_id' => $first->library_id,
                'edition_label' => $first->edition_label,
                'publisher' => $first->publisher,
                'volumes_count' => count($members),
                'metadata' => json_encode(['group' => $key], JSON_UNESCAPED_UNICODE),
            ]);
            $stats['catalogue sources']++;
        }
        foreach ($folded as $id => $count) {
            $this->dry || DB::table('sources')->where('id', $id)->update(['volumes_count' => $count]);
        }
        // a later printing's source is a printing of the source its first volume reprints
        $byId = $volumes->keyBy('id');
        foreach ($groups as $key => $members) {
            $of = $members[0]->edition_of_id ? $byId[$members[0]->edition_of_id] ?? null : null;
            if ($of && ! $this->dry) {
                DB::table('sources')->where('id', $groupSource[$key])->update(['edition_of_id' => $groupSource[Sync::groupKey($of)]]);
            }
        }

        $rows = [];
        foreach ($volumes as $v) {
            $rows[] = ['id' => $v->id, 'source_id' => $groupSource[Sync::groupKey($v)]] + Sync::volumeValues($v)
                + ['created_at' => $v->created_at ?? $now, 'updated_at' => $v->updated_at ?? $now];
        }
        // edition_of_id points at rows of the same table, so it goes in once every volume is there
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->dry || DB::table('source_volumes')->upsert($chunk, ['id'], array_diff(array_keys($chunk[0]), ['id', 'created_at']));
        }
        foreach ($volumes as $v) {
            $this->dry || DB::table('source_volumes')->where('id', $v->id)->update(['edition_of_id' => $v->edition_of_id]);
        }
        $stats['volumes copied'] = count($rows);

        return $stats;
    }

    private function references(): array
    {
        $stats = [SourceEntry::ORIGIN_FANKHA_CITATION => 0];
        // Fankha's citation is the manuscript's own (one per copy)
        EntryColumns::query('manuscripts', ['id', 'catalog_citation', 'raw_text', 'catalog_volume_id'])->chunkById(self::CHUNK, function ($rows) use (&$stats) {
            if ($this->dry) {
                $stats[SourceEntry::ORIGIN_FANKHA_CITATION] += $rows->filter(fn ($m) => Sync::citedValues($m, $this->volumeSources))->count();

                return;
            }
            $stats[SourceEntry::ORIGIN_FANKHA_CITATION] += Sync::syncCited($rows)[0];
        }, 'manuscripts.id', 'id');
        $this->prune('fankha_citation', 'manuscripts');
        $this->prune('fankha_citation_more', 'manuscripts');

        return $stats;
    }

    /** Each work's bibliography entries read again, so the places they cite follow the sources the site knows now. */
    private function bibliography(): array
    {
        $stats = ['works' => 0, 'citations' => 0, 'of a known source' => 0, 'places' => 0];
        DB::table('source_entries')->where('legacy_type', 'work_bibliography')->distinct()->orderBy('legacy_id')->pluck('legacy_id')->chunk(self::CHUNK)
            ->each(function ($ids) use (&$stats) {
                $stats['works'] += $ids->count();
                $this->dry || \App\Jobs\SyncWorkBibliographies::run($ids->all());
            });
        $entries = DB::table('source_entries')->where('legacy_type', 'work_bibliography');
        $stats['citations'] = (clone $entries)->count();
        $stats['of a known source'] = (clone $entries)->whereNotNull('source_id')->count();
        $stats['places'] = (clone $entries)->where(fn ($q) => $q->whereNotNull('locations')->orWhereNotNull('page_start'))->count();

        return $stats;
    }

    private function write(array $rows): void
    {
        if ($this->dry || ! $rows) {
            return;
        }
        Sync::upsert($rows);
    }

    /** Removes the copies of rows that are no longer in their table (a record deleted or merged). */
    private function prune(string $legacy, string $table): void
    {
        $this->dry || DB::table('source_entries')->where('legacy_type', $legacy)
            ->whereNotExists(fn ($q) => $q->from($table)->whereColumn("{$table}.id", 'source_entries.legacy_id'))
            ->delete();
    }

    /** Inserts or updates one source found by $key and returns its id. */
    private function upsertSource(array $key, array $values): int
    {
        $existing = DB::table('sources')->where($key)->value('id');
        if ($this->dry) {
            return $existing ?? ($key['id'] ?? 0);
        }
        if ($existing) {
            DB::table('sources')->where('id', $existing)->update($values + ['updated_at' => now()]);

            return $existing;
        }

        return DB::table('sources')->insertGetId($key + $values + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function loadRegistry(): void
    {
        if ($this->volumeIds) {
            return;
        }
        // a source's first volume of a number is the one a bare «volume/page» means
        $this->volumeIds = Sync::volumeIds();
        $this->volumeSources = DB::table('source_volumes')->pluck('source_id', 'id')->all();
        $this->configSources = Sync::configSources();
    }
}
