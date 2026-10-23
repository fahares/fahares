<?php

namespace App\Console\Commands;

use App\Models\FieldEvidence;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync as Sync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies the site's sources into the one registry of step 13.1, leaving the old tables as they are:
 *
 * - registry: catalogs (same ids) and the reference works of config('bibliography.sources') become sources with
 *   their volumes; catalog_volumes become source_volumes (same ids) under one source per library and title, the
 *   volume's title without its last « - …» part (a later printing, edition_of_id, is a source of its own).
 * - fankha: the Fankha entry of every work and manuscript, its raw_text as the entry's text (a manuscript's
 *   is the copy's own line, so copy_text).
 * - addenda: source_citations (addenda and errata).
 * - references: manuscript_catalog_references, the library catalogue pages Fankha cites and the matched printings.
 * - bibliography: each string of works.bibliography, with the places it cites of the sources the site knows.
 * - evidence: the rationale of suggestions (field_suggestions.rationale_citation, scholarly_annotations).
 *
 * Every row keeps the row it came from (legacy_type, legacy_id, legacy_index), so running it again updates what
 * changed and removes what is gone. It is meant for the time the old tables are still the site's data.
 */
class BackfillSourcesCommand extends Command
{
    protected $signature = 'sources:backfill
        {--only=* : registry, fankha, addenda, references, bibliography, evidence (default: all, in this order)}
        {--dry-run : Count what would be written without writing}';

    protected $description = 'Copy catalogs, catalogue references, Fankha texts and bibliographies into sources and source_entries';

    public const PARTS = ['registry', 'fankha', 'addenda', 'references', 'bibliography', 'evidence'];

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
            $kind = self::CONFIG_KINDS[$key] ?? Source::KIND_OTHER;
            $id = $this->upsertSource(['code' => $key], [
                'kind' => $kind,
                'rank' => Source::KIND_RANKS[$kind],
                'title' => $src['label'],
                'short_name' => $src['viewer']['name'] ?? $src['label'],
                'volumes_count' => $src['volumes'],
                'unit' => ($src['unit'] ?? null) === 'ستون' ? 'ستون' : 'صفحه',
                'metadata' => json_encode(['config' => $key], JSON_UNESCAPED_UNICODE),
            ]);
            $stats['reference works']++;
            $viewer = $src['viewer'] ?? [];
            $stats['volumes made'] += Sync::ensureVolumes($id, range(1, $src['volumes']), $viewer['catalog'] ?? null,
                fn ($v) => ['page_first' => $viewer['first_pages'][$v] ?? ($viewer ? 1 : null), 'page_last' => $viewer['last_pages'][$v] ?? null], $this->dry);
        }

        // catalog_volumes: one source per library and title, and one per later printing
        $volumes = DB::table('catalog_volumes')->orderBy('id')->get();
        $groups = [];
        foreach ($volumes as $v) {
            $groups[self::groupKey($v)][] = $v;
        }
        $groupSource = [];
        foreach ($groups as $key => $members) {
            $first = $members[0];
            $groupSource[$key] = $this->upsertSource(['code' => self::groupCode($key, $first)], [
                'kind' => Source::KIND_LIBRARY_CATALOGUE,
                'rank' => Source::KIND_RANKS[Source::KIND_LIBRARY_CATALOGUE],
                'title' => self::stem($first->title),
                'library_id' => $first->library_id,
                'edition_label' => $first->edition_label,
                'publisher' => $first->publisher,
                'volumes_count' => count($members),
                'metadata' => json_encode(['group' => $key], JSON_UNESCAPED_UNICODE),
            ]);
            $stats['catalogue sources']++;
        }
        // a later printing's source is a printing of the source its first volume reprints
        $byId = $volumes->keyBy('id');
        foreach ($groups as $key => $members) {
            $of = $members[0]->edition_of_id ? $byId[$members[0]->edition_of_id] ?? null : null;
            if ($of && ! $this->dry) {
                DB::table('sources')->where('id', $groupSource[$key])->update(['edition_of_id' => $groupSource[self::groupKey($of)]]);
            }
        }

        $rows = [];
        foreach ($volumes as $v) {
            $rows[] = [
                'id' => $v->id,
                'source_id' => $groupSource[self::groupKey($v)],
                'volume_number' => $v->volume_number,
                'title' => $v->title,
                'edition_label' => $v->edition_label,
                'facsimile_code' => $v->facsimile_code,
                'cover_image_path' => $v->cover_image_path,
                'publisher' => $v->publisher,
                'publication_year' => $v->publication_year,
                'pages_count' => $v->pages_count,
                'page_first' => $v->page_first,
                'page_last' => $v->page_last,
                'manuscripts_range' => $v->manuscripts_range,
                'citation_pattern' => $v->citation_pattern,
                'mtif_entry_id' => $v->mtif_entry_id,
                'source_url' => $v->source_url,
                'metadata' => $v->metadata,
                'created_at' => $v->created_at ?? $now,
                'updated_at' => $v->updated_at ?? $now,
            ];
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

    private function fankha(): array
    {
        return [
            'works' => $this->copyRecords('works', Work::class, 'work'),
            'manuscripts' => $this->copyRecords('manuscripts', Manuscript::class, 'manuscript'),
        ];
    }

    /** The Fankha entry of each record of a table (SourceEntrySync::primaryValues). */
    private function copyRecords(string $table, string $class, string $legacy): int
    {
        $codes = DB::table('catalogs')->pluck('code', 'id')->all();
        $count = 0;
        DB::table($table)->whereNotNull('catalog_id')->select(['id', 'catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text', 'created_at'])
            ->chunkById(self::CHUNK, function ($records) use ($class, $codes, &$count) {
                $rows = [];
                foreach ($records as $r) {
                    $rows[] = Sync::row(Sync::primaryValues($r, $class, $this->volumeIds, $codes), $r->created_at);
                }
                $count += count($rows);
                $this->write($rows);
            });
        $this->prune($legacy, $table);

        return $count;
    }

    private function addenda(): array
    {
        $rows = [];
        foreach (DB::table('source_citations')->orderBy('id')->get() as $c) {
            $text = filled($c->raw_text) ? $c->raw_text : null;
            $volume = $c->volume_number !== null ? (int) $c->volume_number : null;
            $rows[] = Sync::row([
                'citable_type' => $c->citable_type,
                'citable_id' => $c->citable_id,
                'source_id' => $c->catalog_id,
                'source_volume_id' => $this->volumeIds[$c->catalog_id][$volume] ?? null,
                'volume_number' => $volume,
                'page_start' => $c->page_start,
                'page_end' => $c->page_end,
                'role' => $c->role,
                // a manuscript's text is its own line, a work's the entry
                $c->citable_type === Manuscript::class ? 'copy_text' : 'entry_text' => $text,
                'text_volume_number' => $text ? $volume : null,
                'text_page' => $text ? $c->page_start : null,
                'text_status' => $text ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                'origin' => SourceEntry::ORIGIN_FANKHA,
                'status' => SourceEntry::STATUS_VERIFIED,
                'notes' => $c->label,
                'sort_order' => $c->sort_order,
                'legacy_type' => 'source_citation',
                'legacy_id' => $c->id,
            ], $c->created_at, $c->updated_at);
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->write($chunk);
        }
        $this->prune('source_citation', 'source_citations');

        return ['addenda and errata' => count($rows)];
    }

    private function references(): array
    {
        $origins = [Ref::SOURCE_FANKHA => SourceEntry::ORIGIN_FANKHA_CITATION, Ref::SOURCE_AI_MATCH => SourceEntry::ORIGIN_MATCH, Ref::SOURCE_MANUAL => SourceEntry::ORIGIN_MANUAL];
        $stats = array_fill_keys(array_values($origins), 0) + ['with text' => 0, 'no volume' => 0];
        DB::table('manuscript_catalog_references')->chunkById(self::CHUNK, function ($refs) use ($origins, &$stats) {
            $rows = [];
            foreach ($refs as $r) {
                $hasText = filled($r->entry_text) || filled($r->copy_text);
                $origin = $origins[$r->source] ?? SourceEntry::ORIGIN_MANUAL;
                $stats[$origin]++;
                $hasText && $stats['with text']++;
                $r->catalog_volume_id || $stats['no volume']++;
                $rows[] = Sync::row([
                    'citable_type' => Manuscript::class,
                    'citable_id' => $r->manuscript_id,
                    'source_id' => $r->catalog_volume_id ? $this->volumeSources[$r->catalog_volume_id] ?? null : null,
                    'source_volume_id' => $r->catalog_volume_id,
                    'volume_number' => $r->volume_number,
                    'page_start' => $r->page_start,
                    'page_end' => $r->page_end,
                    'entry_number' => $r->entry_number,
                    'shelfmark_page' => $r->shelfmark_page,
                    'citation_text' => $r->raw_text,
                    'entry_text' => $r->entry_text,
                    'copy_text' => $r->copy_text,
                    // texts were taken at the address the reference has (catalogs:match-edition --texts)
                    'text_volume_number' => $hasText ? $r->volume_number : null,
                    'text_page' => $hasText ? $r->page_start : null,
                    'text_status' => $hasText ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                    'origin' => $origin,
                    'match_method' => $r->match_method,
                    'confidence' => $r->confidence,
                    'status' => $r->status,
                    'reviewed_by' => $r->reviewed_by,
                    'reviewed_at' => $r->reviewed_at,
                    'notes' => $r->notes,
                    'legacy_type' => 'catalog_reference',
                    'legacy_id' => $r->id,
                ], $r->created_at, $r->updated_at);
            }
            $this->write($rows);
        });
        $this->prune('catalog_reference', 'manuscript_catalog_references');

        return $stats;
    }

    private function bibliography(): array
    {
        $stats = ['works' => 0, 'citations' => 0, 'of a known source' => 0, 'places' => 0];
        DB::table('works')->whereNotNull('bibliography')->select(['id', 'bibliography', 'created_at'])
            ->chunkById(self::CHUNK, function ($works) use (&$stats) {
                $rows = [];
                foreach ($works as $w) {
                    $values = Sync::bibliographyValues($w->id, Sync::bibliographyStrings($w->bibliography), $this->configSources, $this->volumeIds);
                    if (! $values) {
                        continue;
                    }
                    $stats['works']++;
                    foreach ($values as $v) {
                        $stats['citations']++;
                        $v['source_id'] && $stats['of a known source']++;
                        $stats['places'] += $v['locations'] ? count(json_decode($v['locations'])) : ($v['page_start'] ? 1 : 0);
                        $rows[] = Sync::row($v, $w->created_at);
                    }
                }
                $this->write($rows);
            });

        // a work gone, or a bibliography that has fewer strings than before
        if (! $this->dry) {
            DB::table('source_entries')->where('legacy_type', 'work_bibliography')
                ->whereNotExists(fn ($q) => $q->from('works')->whereColumn('works.id', 'source_entries.legacy_id')
                    ->whereRaw('JSON_LENGTH(works.bibliography) > source_entries.legacy_index'))
                ->delete();
        }

        return $stats;
    }

    private function evidence(): array
    {
        $rows = [];
        $suggestions = DB::table('field_suggestions')->whereNotNull('rationale_citation')->where('rationale_citation', '<>', '')->orderBy('id')->get();
        foreach ($suggestions as $s) {
            if (! $s->suggestable_type || ! $s->suggestable_id) {
                continue;
            }
            $rows[] = $this->evidenceRow($s->suggestable_type, $s->suggestable_id, $s->field_name, $s->id, $s->rationale_citation, $s->user_id, 'field_suggestion', $s->id, $s->created_at);
        }
        $covered = array_column($rows, 'field_suggestion_id');
        $annotations = DB::table('scholarly_annotations')->where('citation_source', '<>', '')->orderBy('id')->get();
        foreach ($annotations as $a) {
            // an annotation made from a suggestion has its grounds already
            if ($a->suggestion_id && in_array($a->suggestion_id, $covered)) {
                continue;
            }
            $rows[] = $this->evidenceRow($a->annotatable_type, $a->annotatable_id, $a->field_name, $a->suggestion_id, $a->citation_source, $a->user_id, 'scholarly_annotation', $a->id, $a->created_at);
        }
        if (! $this->dry && $rows) {
            DB::table('field_evidence')->upsert($rows, ['legacy_type', 'legacy_id'], ['subject_type', 'subject_id', 'field_name', 'field_suggestion_id', 'note', 'user_id']);
        }

        return ['from suggestions' => $suggestions->count(), 'from annotations' => count($rows) - count($covered)];
    }

    /**
     * A contributor's rationale as written: reasoning, quotations and links mixed in one text, so it is kept as the
     * researcher's own note; the form of step 13.1's later phases asks for each ground apart.
     */
    private function evidenceRow(string $type, int $id, ?string $field, ?int $suggestion, string $text, ?int $user, string $legacy, int $legacyId, $created): array
    {
        return [
            'subject_type' => $type,
            'subject_id' => $id,
            'field_name' => $field,
            'field_suggestion_id' => $suggestion,
            'kind' => FieldEvidence::KIND_RESEARCH,
            'note' => $text,
            'user_id' => $user,
            'legacy_type' => $legacy,
            'legacy_id' => $legacyId,
            'created_at' => $created ?? now(),
            'updated_at' => now(),
        ];
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

    /** One source per library and title, and one per later printing (the Masjid-e A'zam catalogue of 1388). */
    public static function groupKey(object $volume): string
    {
        if ($volume->edition_of_id) {
            return 'edition|' . ($volume->facsimile_code ?: $volume->library_id . '|' . self::stem($volume->title));
        }

        return 'catalogue|' . ($volume->library_id ?? '') . '|' . self::stem($volume->title);
    }

    private static function groupCode(string $key, object $first): string
    {
        return $first->edition_of_id && $first->facsimile_code ? $first->facsimile_code : 'catalogue-' . substr(sha1($key), 0, 12);
    }

    /** A volume's title without its volume part: «فهرست … آستان قدس رضوی - جلد دوم: کتب خطی» → «فهرست … آستان قدس رضوی». */
    public static function stem(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $at = mb_strrpos($title, ' - ');

        return $at === false ? $title : trim(mb_substr($title, 0, $at));
    }
}
