<?php

namespace App\Console\Commands;

use App\Support\CopyLines;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a copy's line layouts from its Fankha text (CopyLines; item 87, step 13.156): lines_raw, the catalogue's
 * wording («18 و 21 سطر»), and line_counts, each layout as [from, to]. Both are made from raw_text alone, so they are
 * written where they differ from it, without an audit entry, as the date ranges are; lines, which reviewers edit and
 * the search sorts by, is not touched, and nothing is reindexed. DerivedData keeps them in step afterwards. Safe to
 * run again.
 */
class FillLineCountsCommand extends Command
{
    protected $signature = 'fahares:fill-line-counts
        {--apply : Write; without it the command only counts}
        {--show=8 : How many examples to list per kind}';

    protected $description = "Fill a copy's line layouts (lines_raw, line_counts) from its Fankha text";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = [];
        $examples = [];
        $count = function (string $kind, ?string $example = null) use (&$counts, &$examples) {
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($example !== null && count($examples[$kind] ?? []) < (int) $this->option('show')) {
                $examples[$kind][] = $example;
            }
        };

        DB::table('manuscripts')->select(['id', 'lines', 'lines_raw', 'line_counts', 'raw_text'])->orderBy('id')
            ->chunkById(2000, function ($rows) use ($apply, $count) {
                $writes = [];
                foreach ($rows as $row) {
                    $new = CopyLines::fromText($row->raw_text);
                    if ($new !== null) {
                        $layouts = $new['line_counts'] ?? [];
                        $kind = match (true) {
                            ! $layouts => 'wording_only',
                            count($layouts) > 1 => 'several_layouts',
                            $layouts[0][0] !== $layouts[0][1] => 'varying',
                            default => 'one_count',
                        };
                        $count($kind, $kind === 'one_count' ? null : "#{$row->id} «{$new['lines_raw']}» " . json_encode($layouts) . ' (lines ' . ($row->lines ?? '—') . ')');
                        if ($layouts && $row->lines !== null && ! in_array((int) $row->lines, array_merge(...$layouts), true)) {
                            $count('lines_not_among_them', "#{$row->id} lines {$row->lines} ← «{$new['lines_raw']}»");
                        }
                    }
                    if (($cols = CopyLines::columns($row)) !== null) {
                        $count('changed');
                        $writes[$row->id] = CopyLines::forUpdate($cols);
                    }
                }
                if ($apply && $writes) {
                    DB::transaction(function () use ($writes) {
                        foreach ($writes as $id => $cols) {
                            DB::table('manuscripts')->where('id', $id)->update($cols);
                        }
                    });
                }
            });

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        ksort($counts);
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-22s %8d', $kind, $n));
        }
        foreach ($examples as $kind => $lines) {
            $this->line("-- {$kind}");
            foreach ($lines as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }
}
