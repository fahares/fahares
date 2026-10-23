<?php

namespace App\Console\Commands;

use App\Models\FieldEvidence;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\BibliographyLinks;
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
            $this->upsertSource(['id' => $c->id], [
                'code' => $c->code,
                'kind' => Source::KIND_UNION_CATALOGUE,
                'rank' => Source::KIND_RANKS[Source::KIND_UNION_CATALOGUE],
                'title' => $c->name,
                'short_name' => $c->short_name,
                'compiler' => $c->compiler,
                'publisher' => $c->publisher,
                'volumes_count' => $c->volumes_count,
                'description' => $c->description,
                'citation_format' => $c->citation_format,
            ]);
            $stats['catalogs']++;
            // Fankha's page images are the viewer's default folder, storage/app/public/catalogs/fankha
            $stats['volumes made'] += $this->ensureVolumes($c->id, range(1, max(1, (int) $c->volumes_count)), $c->code === 'fankha' ? 'fankha' : null);
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
            $stats['volumes made'] += $this->ensureVolumes($id, range(1, $src['volumes']), $viewer['catalog'] ?? null,
                fn ($v) => ['page_first' => $viewer['first_pages'][$v] ?? ($viewer ? 1 : null), 'page_last' => $viewer['last_pages'][$v] ?? null]);
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
            'works' => $this->copyRecords('works', Work::class, 'work', 'entry_text'),
            'manuscripts' => $this->copyRecords('manuscripts', Manuscript::class, 'manuscript', 'copy_text'),
        ];
    }

    /** The Fankha entry of each record of a table, its raw_text in $textColumn. */
    private function copyRecords(string $table, string $class, string $legacy, string $textColumn): int
    {
        $addenda = config('catalogs.addenda', []);
        $codes = DB::table('catalogs')->pluck('code', 'id');
        $count = 0;
        DB::table($table)->whereNotNull('catalog_id')->select(['id', 'catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text', 'created_at'])
            ->chunkById(self::CHUNK, function ($records) use ($class, $legacy, $textColumn, $addenda, $codes, &$count) {
                $rows = [];
                foreach ($records as $r) {
                    $hasText = filled($r->raw_text);
                    $volume = $r->volume_number !== null ? (int) $r->volume_number : null;
                    $page = $r->page_start !== null ? (int) $r->page_start : null;
                    $rows[] = $this->entryRow([
                        'citable_type' => $class,
                        'citable_id' => $r->id,
                        'source_id' => $r->catalog_id,
                        'source_volume_id' => $this->volumeIds[$r->catalog_id][$volume] ?? null,
                        'volume_number' => $volume,
                        'page_start' => $page,
                        'page_end' => $r->page_end,
                        'role' => self::inAddenda($addenda[$codes[$r->catalog_id] ?? ''] ?? [], $volume, $page) ? SourceEntry::ROLE_ADDENDUM : SourceEntry::ROLE_ENTRY,
                        'is_primary' => true,
                        $textColumn => $hasText ? $r->raw_text : null,
                        'text_volume_number' => $hasText ? $volume : null,
                        'text_page' => $hasText ? $page : null,
                        'text_status' => $hasText ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
                        'origin' => SourceEntry::ORIGIN_FANKHA,
                        // the entry is Fankha itself: nothing about it awaits review
                        'status' => SourceEntry::STATUS_VERIFIED,
                        'legacy_type' => $legacy,
                        'legacy_id' => $r->id,
                    ], $r->created_at);
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
            $rows[] = $this->entryRow([
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
                $rows[] = $this->entryRow([
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
                    $entries = array_values(array_filter((array) json_decode($w->bibliography, true), fn ($e) => is_string($e) && trim($e) !== ''));
                    if (! $entries) {
                        continue;
                    }
                    $stats['works']++;
                    foreach ($entries as $i => $entry) {
                        $places = array_map(fn ($c) => [
                            'source_id' => $this->configSources[$c['source']] ?? null,
                            'volume' => $c['volume'],
                            'page' => $c['page'],
                        ], BibliographyLinks::citations($entry));
                        $first = $places[0] ?? null;
                        $stats['citations']++;
                        $first && $stats['of a known source']++;
                        $stats['places'] += count($places);
                        $rows[] = $this->entryRow([
                            'citable_type' => Work::class,
                            'citable_id' => $w->id,
                            'source_id' => $first['source_id'] ?? null,
                            'source_volume_id' => $first ? $this->volumeIds[$first['source_id']][$first['volume']] ?? null : null,
                            'volume_number' => $first['volume'] ?? null,
                            'page_start' => $first['page'] ?? null,
                            'role' => SourceEntry::ROLE_CITATION,
                            'citation_text' => $entry,
                            'locations' => count($places) > 1 ? json_encode($places) : null,
                            'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
                            // Fankha's own words: nothing about them awaits review
                            'status' => SourceEntry::STATUS_VERIFIED,
                            'sort_order' => $i,
                            'legacy_type' => 'work_bibliography',
                            'legacy_id' => $w->id,
                            'legacy_index' => $i,
                        ], $w->created_at);
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

    /** Every column of source_entries, so the rows of one upsert have the same keys. */
    private function entryRow(array $values, $created = null, $updated = null): array
    {
        $now = now();

        return $values + [
            'source_id' => null, 'source_volume_id' => null, 'volume_number' => null, 'page_start' => null, 'page_end' => null,
            'entry_number' => null, 'shelfmark_page' => null, 'role' => SourceEntry::ROLE_ENTRY, 'is_primary' => false,
            'citation_text' => null, 'locations' => null, 'entry_text' => null, 'copy_text' => null,
            'text_volume_number' => null, 'text_page' => null, 'text_status' => SourceEntry::TEXT_NONE,
            'match_method' => null, 'confidence' => null, 'status' => SourceEntry::STATUS_UNVERIFIED,
            'reviewed_by' => null, 'reviewed_at' => null, 'notes' => null, 'sort_order' => 0, 'legacy_index' => 0,
            'created_at' => $created ?? $now, 'updated_at' => $updated ?? $created ?? $now,
        ];
    }

    private function write(array $rows): void
    {
        if ($this->dry || ! $rows) {
            return;
        }
        $keys = ['legacy_type', 'legacy_id', 'legacy_index'];
        // a thousand works give several thousand bibliography rows, past the 65,535 placeholders of one statement
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('source_entries')->upsert($chunk, $keys, array_diff(array_keys($chunk[0]), [...$keys, 'created_at']));
        }
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

    /** Makes the volumes a source lacks; returns how many were made. */
    private function ensureVolumes(int $sourceId, array $numbers, ?string $facsimile, ?callable $extra = null): int
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('volume_number')->map(fn ($n) => (int) $n)->all();
        $rows = [];
        foreach (array_diff($numbers, $have) as $n) {
            $rows[] = ['source_id' => $sourceId, 'volume_number' => $n, 'facsimile_code' => $facsimile, 'created_at' => now(), 'updated_at' => now()]
                + ($extra ? $extra($n) : []);
        }
        $this->dry || ! $rows || DB::table('source_volumes')->insert($rows);

        return count($rows);
    }

    private function loadRegistry(): void
    {
        if ($this->volumeIds) {
            return;
        }
        foreach (DB::table('source_volumes')->orderBy('id')->get(['id', 'source_id', 'volume_number']) as $v) {
            $this->volumeSources[$v->id] = $v->source_id;
            // a source's first volume of a number is the one a bare «volume/page» means
            $this->volumeIds[$v->source_id][$v->volume_number] ??= $v->id;
        }
        $this->configSources = DB::table('sources')->whereIn('code', array_keys(config('bibliography.sources')))->pluck('id', 'code')->all();
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

    private static function inAddenda(array $ranges, ?int $volume, ?int $page): bool
    {
        foreach ($ranges as $range) {
            if ($volume === $range['volume'] && $page >= $range['from'] && $page <= $range['to']) {
                return true;
            }
        }

        return false;
    }
}
