<?php

namespace App\Sources;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\BibliographyLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The entries a work or manuscript carries in its own columns (step 13.3): its Fankha entry (catalog_id,
 * volume_number, page_start, page_end, raw_text) and a work's bibliography. sources:backfill copies them all;
 * syncRecord() keeps one record's entries in step whenever those columns are saved, so every writer of the
 * record (a suggestion, its rollback, a merge, an import) leaves the two the same.
 */
class SourceEntrySync
{
    /** The columns of a record its entries follow. */
    public const RECORD_COLUMNS = ['catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text'];

    /** Every column of source_entries but the keys, so the rows of one upsert have the same keys. */
    public static function row(array $values, $created = null, $updated = null): array
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

    /**
     * The Fankha entry of a record ($r: a model or a row of its table): its raw_text is the entry's text, a work's
     * the entry, a manuscript's its own line in the work's entry. The text stands where the record does.
     *
     * @param  array  $volumeIds  [source_id][volume_number] => source_volumes.id
     * @param  array  $codes  catalogs.id => code
     */
    public static function primaryValues(object $r, string $class, array $volumeIds, array $codes): array
    {
        $hasText = filled($r->raw_text);
        $volume = $r->volume_number !== null ? (int) $r->volume_number : null;
        $page = $r->page_start !== null ? (int) $r->page_start : null;
        $addenda = config('catalogs.addenda.' . ($codes[$r->catalog_id] ?? ''), []);

        return [
            'citable_type' => $class,
            'citable_id' => $r->id,
            'source_id' => $r->catalog_id,
            'source_volume_id' => $volumeIds[$r->catalog_id][$volume] ?? null,
            'volume_number' => $volume,
            'page_start' => $page,
            'page_end' => $r->page_end,
            'role' => self::inRanges($addenda, $volume, $page) ? SourceEntry::ROLE_ADDENDUM : SourceEntry::ROLE_ENTRY,
            'is_primary' => true,
            ($class === Manuscript::class ? 'copy_text' : 'entry_text') => $hasText ? $r->raw_text : null,
            'text_volume_number' => $hasText ? $volume : null,
            'text_page' => $hasText ? $page : null,
            'text_status' => $hasText ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
            'origin' => SourceEntry::ORIGIN_FANKHA,
            // the entry is Fankha itself: nothing about it awaits review
            'status' => SourceEntry::STATUS_VERIFIED,
            'legacy_type' => $class === Manuscript::class ? 'manuscript' : 'work',
            'legacy_id' => $r->id,
        ];
    }

    /**
     * One entry per string of a work's bibliography, in order, with the places it cites of the sources the site
     * knows: the first is the entry's, all of them in locations when there are more.
     *
     * @param  array  $configSources  config key => sources.id
     * @return list<array>
     */
    public static function bibliographyValues(int $workId, array $strings, array $configSources, array $volumeIds): array
    {
        $rows = [];
        foreach (array_values($strings) as $i => $entry) {
            $places = array_map(fn ($c) => ['source_id' => $configSources[$c['source']] ?? null, 'volume' => $c['volume'], 'page' => $c['page']],
                BibliographyLinks::citations($entry));
            $first = $places[0] ?? null;
            $rows[] = [
                'citable_type' => Work::class,
                'citable_id' => $workId,
                'source_id' => $first['source_id'] ?? null,
                'source_volume_id' => $first ? $volumeIds[$first['source_id']][$first['volume']] ?? null : null,
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
                'legacy_id' => $workId,
                'legacy_index' => $i,
            ];
        }

        return $rows;
    }

    /** The non-empty strings of a bibliography column (a JSON string or the cast array). */
    public static function bibliographyStrings($value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return array_values(array_filter((array) $list, fn ($e) => is_string($e) && trim($e) !== ''));
    }

    /** Brings one record's entries in line with its columns: its Fankha entry and, for a work, its bibliography. */
    public static function syncRecord(Model $record): void
    {
        $class = $record::class;
        // a record made without catalog_id has the column's default, which the model has not read back
        if (! array_key_exists('catalog_id', $record->getAttributes())) {
            $record->setRawAttributes(['catalog_id' => DB::table($record->getTable())->where('id', $record->id)->value('catalog_id')] + $record->getAttributes(), true);
        }
        if ($record->catalog_id) {
            self::ensureCatalogSource((int) $record->catalog_id);
            $volumeIds = self::volumeIds([(int) $record->catalog_id]);
            $codes = DB::table('catalogs')->where('id', $record->catalog_id)->pluck('code', 'id')->all();
            self::upsert([self::row(self::primaryValues($record, $class, $volumeIds, $codes), $record->created_at)]);
        } else {
            DB::table('source_entries')->where('legacy_type', $class === Manuscript::class ? 'manuscript' : 'work')->where('legacy_id', $record->id)->delete();
        }

        if ($record instanceof Work) {
            self::syncBibliography($record);
        }
    }

    public static function syncBibliography(Work $work): void
    {
        $strings = self::bibliographyStrings($work->getAttributes()['bibliography'] ?? null);
        $configSources = self::configSources();
        $rows = array_map(fn ($v) => self::row($v, $work->created_at),
            self::bibliographyValues($work->id, $strings, $configSources, self::volumeIds(array_values(array_filter($configSources)))));
        self::upsert($rows);
        // strings past the new end are gone
        DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->id)
            ->where('legacy_index', '>=', count($strings))->delete();
    }

    /** Inserts or updates rows by the record they come from (legacy_type, legacy_id, legacy_index). */
    public static function upsert(array $rows): void
    {
        $keys = ['legacy_type', 'legacy_id', 'legacy_index'];
        // a thousand works give several thousand bibliography rows, past the 65,535 placeholders of one statement
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('source_entries')->upsert($chunk, $keys, array_diff(array_keys($chunk[0]), [...$keys, 'created_at']));
        }
    }

    /**
     * The source of a catalog, made from its row when the registry does not have it yet (sources.id = catalogs.id),
     * with a volume for each of its volumes; Fankha's page images are the viewer's default folder.
     */
    public static function ensureCatalogSource(int $catalogId): ?int
    {
        if (DB::table('sources')->where('id', $catalogId)->exists()) {
            return $catalogId;
        }
        $c = DB::table('catalogs')->find($catalogId);
        if (! $c) {
            return null;
        }
        DB::table('sources')->insert(['id' => $c->id] + self::catalogSourceValues($c) + ['created_at' => now(), 'updated_at' => now()]);
        self::ensureVolumes($c->id, range(1, max(1, (int) $c->volumes_count)), $c->code === 'fankha' ? 'fankha' : null);

        return $catalogId;
    }

    public static function catalogSourceValues(object $c): array
    {
        return [
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
        ];
    }

    /** Makes the volumes a source lacks; returns how many were made. */
    public static function ensureVolumes(int $sourceId, array $numbers, ?string $facsimile, ?callable $extra = null, bool $dry = false): int
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('volume_number')->map(fn ($n) => (int) $n)->all();
        $rows = [];
        foreach (array_diff($numbers, $have) as $n) {
            $rows[] = ['source_id' => $sourceId, 'volume_number' => $n, 'facsimile_code' => $facsimile, 'created_at' => now(), 'updated_at' => now()]
                + ($extra ? $extra($n) : []);
        }
        $dry || ! $rows || DB::table('source_volumes')->insert($rows);

        return count($rows);
    }

    /** [source_id][volume_number] => the source's first volume of that number. */
    public static function volumeIds(?array $sourceIds = null): array
    {
        $map = [];
        DB::table('source_volumes')->when($sourceIds !== null, fn ($q) => $q->whereIn('source_id', $sourceIds))
            ->orderBy('id')->get(['id', 'source_id', 'volume_number'])
            ->each(function ($v) use (&$map) {
                $map[$v->source_id][$v->volume_number] ??= $v->id;
            });

        return $map;
    }

    /** config('bibliography.sources') key => sources.id, for the reference works the registry has. */
    public static function configSources(): array
    {
        return DB::table('sources')->whereIn('code', array_keys(config('bibliography.sources')))->pluck('id', 'code')->all();
    }

    private static function inRanges(array $ranges, ?int $volume, ?int $page): bool
    {
        foreach ($ranges as $range) {
            if ($volume === $range['volume'] && $page >= $range['from'] && $page <= $range['to']) {
                return true;
            }
        }

        return false;
    }
}
