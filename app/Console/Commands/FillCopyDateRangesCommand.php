<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Work;
use App\Support\HijriYear;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a copy's date range, certainty and hedge word (copy_date_from, copy_date_to, copy_date_certainty,
 * copy_date_hedge_raw) from its copy_date_raw, for the copies saved before these columns existed (backlog item 96);
 * with --works, the same for a work's date of composition (composition_date_*). Saving a copy or a work keeps them
 * in step from then on (Manuscript::booted, Work::booted). The columns are derived, so a run only
 * rewrites what differs and can be repeated; the date text itself is not touched. Without --apply it only counts.
 *
 * Where the range changes and the sortable year (copy_date_hijri_year, composition_year_hijri) is a number the date
 * text no longer gives as a year («996 یزدجرد … 1036ق» sorted under 996), the year is read again too (step 14.186).
 * A year that the text still gives, or that goes with an unchanged range, is left as it is.
 */
class FillCopyDateRangesCommand extends Command
{
    protected $signature = 'fahares:fill-copy-date-ranges
        {--works : The dates of composition of works instead of the dates of copies}
        {--apply : Write the columns; without it the command only counts}
        {--show=20 : How many dates with no range to list}';

    protected $description = 'Fill copy_date_from/to, certainty and hedge word from copy_date_raw';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['dated' => 0, 'ranged' => 0, 'one_year' => 0, 'span' => 0, 'open' => 0, 'hedged' => 0, 'changed' => 0, 'sort_year' => 0];
        $unranged = [];

        // A copy's date, or with --works a work's date of composition: the table, its date text and how its columns are made
        [$table, $prefix, $columns] = $this->option('works')
            ? ['works', 'composition_date', fn ($raw, $death, $floor) => Work::compositionDateColumns($raw)]
            : ['manuscripts', 'copy_date', fn ($raw, $death, $floor) => Manuscript::copyDateColumns($raw, $death, $floor)];
        $raw = $table === 'works' ? 'composition_date_raw' : 'copy_date_raw';
        DB::table($table)->whereNotNull($raw)->where($raw, '<>', '')
            ->select(['id', $table === 'works' ? 'id as work_id' : 'work_id', "{$raw} as raw", ($table === 'works' ? 'composition_year_hijri' : 'copy_date_hijri_year') . ' as sort_year', "{$prefix}_from as from", "{$prefix}_to as to", "{$prefix}_certainty as certainty", "{$prefix}_hedge_raw as hedge"])
            ->orderBy('id')->chunkById(2000, function ($rows) use ($apply, $table, $prefix, $columns, &$counts, &$unranged) {
                $updates = [];
                // «نزدیک عصر مؤلف»: a copy date that names the author's time is ranged from the author's death
                $deaths = $table === 'works' ? [] : Manuscript::authorDeaths($rows->pluck('work_id'));
                // «سال 125؟» far before its work is read again from the work's floor (item 128)
                $floors = $table === 'works' ? [] : Manuscript::copyFloors($rows->pluck('work_id'));
                foreach ($rows as $row) {
                    $counts['dated']++;
                    $cols = $columns($row->raw, $deaths[$row->work_id] ?? null, $floors[$row->work_id] ?? null);
                    if ($cols["{$prefix}_from"] === null && $cols["{$prefix}_to"] === null) {
                        $pattern = preg_replace('/[0-9۰-۹]+/u', 'N', mb_substr($row->raw, 0, 40));
                        $unranged[$pattern] = ($unranged[$pattern] ?? 0) + 1;
                    } else {
                        $counts['ranged']++;
                        $from = $cols["{$prefix}_from"];
                        $to = $cols["{$prefix}_to"];
                        $counts[$from === null || $to === null ? 'open' : ($from === $to ? 'one_year' : 'span')]++;
                    }
                    if ($cols["{$prefix}_hedge_raw"]) {
                        $counts['hedged']++;
                    }
                    $current = ["{$prefix}_from" => $row->from === null ? null : (int) $row->from,
                        "{$prefix}_to" => $row->to === null ? null : (int) $row->to,
                        "{$prefix}_certainty" => $row->certainty, "{$prefix}_hedge_raw" => $row->hedge];
                    if ($current !== $cols) {
                        $counts['changed']++;
                        $updates[$row->id] = $cols + ($table === 'works' ? [] : Manuscript::rereadSortYear($cols));
                        if ($row->sort_year !== null && ! in_array((int) $row->sort_year, HijriYear::all($row->raw), true)
                            && ($cols["{$prefix}_from"] !== null || $cols["{$prefix}_to"] !== null || HijriYear::parse($row->raw) === null)) {
                            $counts['sort_year']++;
                            $updates[$row->id] += [$table === 'works' ? 'composition_year_hijri' : 'copy_date_hijri_year' => HijriYear::parse($row->raw)];
                        }
                    }
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates, $table) {
                        foreach ($updates as $id => $cols) {
                            DB::table($table)->where('id', $id)->update($cols);
                        }
                    });
                }
            });

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-12s %8d', $kind, $n));
        }
        arsort($unranged);
        $this->line('-- dates with no range (pattern, copies)');
        foreach (array_slice($unranged, 0, (int) $this->option('show'), true) as $pattern => $n) {
            $this->line(sprintf('  %6d  %s', $n, $pattern));
        }

        return self::SUCCESS;
    }
}
