<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a copy's date range, certainty and hedge word (copy_date_from, copy_date_to, copy_date_certainty,
 * copy_date_hedge_raw) from its copy_date_raw, for the copies saved before these columns existed (backlog item 96).
 * Saving a copy keeps them in step from then on (Manuscript::booted). The columns are derived, so a run only
 * rewrites what differs and can be repeated; the date text itself is not touched. Without --apply it only counts.
 */
class FillCopyDateRangesCommand extends Command
{
    protected $signature = 'fahares:fill-copy-date-ranges
        {--apply : Write the columns; without it the command only counts}
        {--show=20 : How many dates with no range to list}';

    protected $description = 'Fill copy_date_from/to, certainty and hedge word from copy_date_raw';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['dated' => 0, 'ranged' => 0, 'one_year' => 0, 'span' => 0, 'open' => 0, 'hedged' => 0, 'changed' => 0];
        $unranged = [];

        DB::table('manuscripts')->whereNotNull('copy_date_raw')->where('copy_date_raw', '<>', '')
            ->select(['id', 'copy_date_raw', 'copy_date_from', 'copy_date_to', 'copy_date_certainty', 'copy_date_hedge_raw'])
            ->orderBy('id')->chunkById(2000, function ($rows) use ($apply, &$counts, &$unranged) {
                $updates = [];
                foreach ($rows as $row) {
                    $counts['dated']++;
                    $cols = Manuscript::copyDateColumns($row->copy_date_raw);
                    if ($cols['copy_date_from'] === null && $cols['copy_date_to'] === null) {
                        $pattern = preg_replace('/[0-9۰-۹]+/u', 'N', mb_substr($row->copy_date_raw, 0, 40));
                        $unranged[$pattern] = ($unranged[$pattern] ?? 0) + 1;
                    } else {
                        $counts['ranged']++;
                        $from = $cols['copy_date_from'];
                        $to = $cols['copy_date_to'];
                        $counts[$from === null || $to === null ? 'open' : ($from === $to ? 'one_year' : 'span')]++;
                    }
                    if ($cols['copy_date_hedge_raw']) {
                        $counts['hedged']++;
                    }
                    $current = ['copy_date_from' => $row->copy_date_from === null ? null : (int) $row->copy_date_from,
                        'copy_date_to' => $row->copy_date_to === null ? null : (int) $row->copy_date_to,
                        'copy_date_certainty' => $row->copy_date_certainty, 'copy_date_hedge_raw' => $row->copy_date_hedge_raw];
                    if ($current !== $cols) {
                        $counts['changed']++;
                        $updates[$row->id] = $cols;
                    }
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $id => $cols) {
                            DB::table('manuscripts')->where('id', $id)->update($cols);
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
