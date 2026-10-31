<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Suggestions\ProtectedColumns;
use App\Support\CopyExtent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a copy's extent from its Fankha text (CopyExtent; backlog item 97): the page count, the catalogue's wording
 * and the range of leaves or pages the copy takes in a volume. The leaf count (folios) changes only where the text
 * has one count: a page count sitting in folios moves to pages («43ص» was 43 leaves), a count the site took from
 * elsewhere («5 برگ ابتدایی …» for «29گ») is put right, and a missing one is filled. A folios fixed by a reviewed
 * correction is kept (ProtectedColumns), and so is any folios where the text has no count; a copy whose text has two
 * counts of one kind («63گ، 21گ») is left as it is. A changed folios is
 * logged as corpus_update; the copy is reindexed (the search filters and sorts on folios). Safe to run again.
 */
class FillExtentsCommand extends Command
{
    protected $signature = 'fahares:fill-extents
        {--apply : Write; without it the command only counts}
        {--show=12 : How many examples to list per kind}';

    protected $description = 'Fill pages, extent wording and range from the Fankha text, and put folios right';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Manuscript::class);
        $counts = [];
        $examples = [];
        $count = function (string $kind, ?string $example = null) use (&$counts, &$examples) {
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($example !== null && count($examples[$kind] ?? []) < (int) $this->option('show')) {
                $examples[$kind][] = $example;
            }
        };

        DB::table('manuscripts')->select(['id', 'folios', 'pages', 'extent_raw', 'range_from', 'range_to', 'range_from_number', 'range_to_number', 'raw_text'])
            ->orderBy('id')->chunkById(2000, function ($rows) use ($apply, $guard, $count) {
                $writes = [];
                $logs = [];
                foreach ($rows as $row) {
                    $e = CopyExtent::fromText($row->raw_text);
                    if ($e['raw'] === null) {
                        $count($row->folios === null ? 'no_extent' : 'kept:no_count_in_text');
                        continue;
                    }
                    $site = $row->folios === null ? null : (int) $row->folios;
                    // Two counts of one kind: nothing of the extent is certain, so the copy is left as it is
                    if ($e['counts'] > 1) {
                        $count('kept:two_counts', "#{$row->id} {$site} ← {$e['raw']}");
                        continue;
                    }
                    $folios = $site;
                    if ($e['counts'] === 1 && $e['folios'] !== $site) {
                        $kind = match (true) {
                            $site === null => 'folios_filled',
                            $e['folios'] === null && ($e['pages'] === $site || intdiv((int) $e['pages'] + 1, 2) === $site) => 'pages_out_of_folios',
                            default => 'folios_corrected',
                        };
                        if ($guard->strip($row->id, ['folios' => 1]) === []) {
                            $count('kept:protected', "#{$row->id} {$site} ← {$e['raw']}");
                        } else {
                            $folios = $e['folios'];
                            $count($kind, "#{$row->id} {$site} → " . ($folios ?? '—') . " ({$e['raw']})");
                        }
                    }
                    $new = ['folios' => $folios, 'pages' => $e['pages'], 'extent_raw' => $e['raw'], 'range_from' => $e['range_from'], 'range_to' => $e['range_to'],
                        'range_from_number' => $e['range_from_number'], 'range_to_number' => $e['range_to_number']];
                    $old = ['folios' => $site, 'pages' => $row->pages === null ? null : (int) $row->pages, 'extent_raw' => $row->extent_raw, 'range_from' => $row->range_from,
                        'range_to' => $row->range_to, 'range_from_number' => $row->range_from_number === null ? null : (int) $row->range_from_number,
                        'range_to_number' => $row->range_to_number === null ? null : (int) $row->range_to_number];
                    if ($new['range_from'] !== null) {
                        $count('with_range');
                    }
                    if ($new === $old) {
                        continue;
                    }
                    $count('changed');
                    $writes[$row->id] = $new;
                    if ($folios !== $site) {
                        $logs[$row->id] = ['old' => ['folios' => $site], 'new' => ['folios' => $folios, 'pages' => $e['pages'], 'extent_raw' => $e['raw']]];
                    }
                }
                if (! $apply || ! $writes) {
                    return;
                }
                DB::transaction(function () use ($writes, $logs) {
                    foreach ($writes as $id => $cols) {
                        DB::table('manuscripts')->where('id', $id)->update($cols);
                    }
                    foreach ($logs as $id => $log) {
                        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $id, 'action' => 'corpus_update',
                            'old_values' => $log['old'], 'new_values' => $log['new'] + ['corpus_fix' => 'fill-extents (item 97)']]);
                    }
                });
                // Each chunk is reindexed as it is written, so a run a deploy cuts short leaves no copy behind
                foreach (array_chunk(array_keys($logs), 100) as $ids) {
                    Manuscript::whereIn('id', $ids)->get()->searchable();
                    gc_collect_cycles();
                }
            });

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        ksort($counts);
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-26s %8d', $kind, $n));
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
