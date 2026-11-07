<?php

namespace App\Support;

use App\Console\Commands\FillTextExcerptsCommand;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Keeps what is made from a copy's or a work's source columns in step when a corpus command writes them with
 * DB::table, which runs no model hook (item 87). For a copy: its date range (Manuscript::copyDateColumns), its extent
 * (CopyExtent::columns), its line layouts (CopyLines::columns), its bound volume (Manuscript::collectionKeyOf), its pointers at the work's excerpts (FillTextExcerptsCommand::linkCopy), its search document
 * and those of the works its incipit and explicit are indexed under. For a work: its date of composition's range
 * (Work::compositionDateColumns), its dedicatees and commissioners (fahares:backfill-work-dedications) and its search
 * document. The fillers and fahares:refresh-derived use the same functions, so there is one write path, and only what
 * differs is written.
 */
class DerivedData
{
    public const COPY_COLUMNS = ['id', 'work_id', 'raw_text', 'incipit_text', 'explicit_text', 'copy_date_raw', 'copy_date_from', 'copy_date_to',
        'copy_date_certainty', 'copy_date_hedge_raw', 'folios', 'pages', 'extent_raw', 'range_from', 'range_to', 'range_from_number', 'range_to_number',
        'shelfmark', 'collection_key', 'lines_raw', 'line_counts'];

    public const WORK_COLUMNS = ['id', 'composition_date_raw', 'composition_date_from', 'composition_date_to', 'composition_date_certainty', 'composition_date_hedge_raw'];

    public const PERSON_COLUMNS = ['id', 'life_dates_raw', 'death_year_hijri', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'];

    /**
     * A copy's derived columns that differ from what its source columns give: the date range (cleared when the date
     * is; a date that names the author's time takes it from $authorDeath, Manuscript::authorDeaths) and the extent
     * (left alone when its text has no single count, as fill-extents does) and the volume its shelfmark names (left
     * alone where a reviewed correction set it).
     *
     * @return array{date: ?array, extent: ?array, collection: ?array, lines: ?array}
     */
    public static function copyChanges(object $row, ProtectedColumns $guard, ?int $authorDeath = null): array
    {
        $date = Manuscript::copyDateColumns($row->copy_date_raw, $authorDeath);
        $extent = CopyExtent::columns($row, $guard)['columns'];

        return [
            'date' => $date !== self::range($row, 'copy_date') ? $date : null,
            'extent' => $extent !== null && $extent !== CopyExtent::stored($row) ? $extent : null,
            'collection' => self::collectionChange($row, $guard),
            'lines' => CopyLines::columns($row),
        ];
    }

    /**
     * A copy's collection_key when its shelfmark names another volume and no reviewed correction set it, else null. A
     * key fahares:link-plain-volumes set is kept while it fits the shelfmark (Manuscript::resolvedCollectionKey).
     */
    public static function collectionChange(object $row, ProtectedColumns $guard): ?array
    {
        $key = Manuscript::resolvedCollectionKey($row->shelfmark, $row->raw_text, $row->collection_key);

        return $key !== $row->collection_key && $guard->strip($row->id, ['collection_key' => 1]) !== [] ? ['collection_key' => $key] : null;
    }

    /** A work's composition date columns when they differ from what its date text gives, else null. */
    public static function workChanges(object $row): ?array
    {
        $cols = Work::compositionDateColumns($row->composition_date_raw);

        return $cols !== self::range($row, 'composition_date') ? $cols : null;
    }

    /** A person's death columns when they differ from what their life dates and death year give, else null. */
    public static function personChanges(object $row): ?array
    {
        $cols = Person::deathColumns($row->life_dates_raw, $row->death_year_hijri === null ? null : (int) $row->death_year_hijri);

        return $cols !== self::range($row, 'death') ? $cols : null;
    }

    /**
     * Brings copies the corpus commands wrote or made in step: their date and extent columns, their excerpt pointers
     * (a pointer whose text the rebuild took away is removed) and their search documents, then the works they are
     * indexed under; and their bound volume. A changed leaf count is logged as corpus_update, as fill-extents logs it.
     *
     * @return array<string, int>
     */
    public static function syncCopies(array $ids, ?Command $out = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $guard = ProtectedColumns::for(Manuscript::class);
        $counts = ['copies' => count($ids), 'dates' => 0, 'extents' => 0, 'collections' => 0, 'lines' => 0, 'pointers_linked' => 0, 'pointers_removed' => 0];
        $workIds = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('manuscripts')->whereIn('id', $chunk)->get(self::COPY_COLUMNS);
            $deaths = Manuscript::authorDeaths($rows->pluck('work_id'));
            DB::transaction(function () use ($rows, $guard, $deaths, &$counts) {
                foreach ($rows as $row) {
                    ['date' => $date, 'extent' => $extent, 'collection' => $collection, 'lines' => $lines] = self::copyChanges($row, $guard, $deaths[$row->work_id] ?? null);
                    if (! $date && ! $extent && ! $collection && ! $lines) {
                        continue;
                    }
                    DB::table('manuscripts')->where('id', $row->id)->update(($date ?? []) + ($extent ?? []) + ($collection ?? []) + CopyLines::forUpdate($lines));
                    $counts['lines'] += $lines ? 1 : 0;
                    $counts['dates'] += $date ? 1 : 0;
                    $counts['extents'] += $extent ? 1 : 0;
                    $counts['collections'] += $collection ? 1 : 0;
                    $site = $row->folios === null ? null : (int) $row->folios;
                    if ($extent && $extent['folios'] !== $site) {
                        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $row->id, 'action' => 'corpus_update',
                            'old_values' => ['folios' => $site], 'new_values' => ['folios' => $extent['folios'], 'pages' => $extent['pages'], 'extent_raw' => $extent['extent_raw'], 'corpus_fix' => 'derived data']]);
                    }
                }
                foreach ($rows->groupBy('work_id') as $workId => $copies) {
                    $excerpts = FillTextExcerptsCommand::excerptsOf((int) $workId);
                    foreach ($copies as $copy) {
                        foreach (FillTextExcerptsCommand::linkCopy($copy, $excerpts, true, prune: true) as [$outcome]) {
                            if ($outcome !== 'unmatched') {
                                $counts["pointers_{$outcome}"]++;
                            }
                        }
                    }
                }
            });
            Manuscript::whereIn('id', $chunk)->get()->searchable();
            array_push($workIds, ...$rows->pluck('work_id')->all());
            gc_collect_cycles();
        }
        // The works index carries their copies' incipits and explicits (Work::manuscriptSearchTexts)
        self::reindexWorks($workIds);
        $out?->line('Derived data of copies: ' . http_build_query($counts, '', ', '));

        return $counts;
    }

    /**
     * Brings works the corpus commands wrote or made in step: their composition date columns, the dedicatees and
     * commissioners their dedication gives (fahares:backfill-work-dedications, which only adds) and their search
     * documents.
     *
     * @return array<string, int>
     */
    public static function syncWorks(array $ids, ?Command $out = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $counts = ['works' => count($ids), 'dates' => 0, 'with_dedication' => 0];
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::transaction(function () use ($chunk, &$counts) {
                foreach (DB::table('works')->whereIn('id', $chunk)->get(self::WORK_COLUMNS) as $row) {
                    if ($cols = self::workChanges($row)) {
                        DB::table('works')->where('id', $row->id)->update($cols);
                        $counts['dates']++;
                    }
                }
            });
            $dedicated = DB::table('works')->whereIn('id', $chunk)->whereNotNull('dedication')->where('dedication', '<>', '')->pluck('id')->all();
            $counts['with_dedication'] += count($dedicated);
            if ($dedicated) {
                // An empty --only would run over every work
                $args = ['--only' => implode(',', $dedicated), '--show' => 5];
                $out ? $out->call('fahares:backfill-work-dedications', $args) : Artisan::call('fahares:backfill-work-dedications', $args);
            }
        }
        self::reindexWorks($ids);
        $out?->line('Derived data of works: ' . http_build_query($counts, '', ', '));

        return $counts;
    }

    private static function reindexWorks(array $ids): void
    {
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $ids)))), 100) as $chunk) {
            Work::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
    }

    /** The stored range columns of a row (copy_date, composition_date or death), typed as the column functions give them. */
    private static function range(object $row, string $prefix): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;

        return ["{$prefix}_from" => $int($row->{"{$prefix}_from"}), "{$prefix}_to" => $int($row->{"{$prefix}_to"}),
            "{$prefix}_certainty" => $row->{"{$prefix}_certainty"}, "{$prefix}_hedge_raw" => $row->{"{$prefix}_hedge_raw"}];
    }
}
