<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Suggestions\ProtectedColumns;
use App\Support\DerivedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills each copy's bound volume (collection_key; backlog item 97) from its shelfmark: «3852/4» is the fourth
 * treatise of volume 3852 of its library, «1068/2-طباطبائی» of volume 1068 of the Tabatabai collection
 * (Manuscript::collectionKey). A plain number («4825») names no volume: copies sharing one are seldom bound together.
 * The decision is DerivedData::collectionChange, so the corpus commands and fahares:refresh-derived write the same, and a
 * key a reviewed correction set is kept. Nothing searchable changes, so nothing is reindexed. Safe to run again.
 */
class FillCollectionKeysCommand extends Command
{
    protected $signature = 'fahares:fill-collection-keys
        {--apply : Write; without it the command only counts}
        {--show=12 : How many examples to list per kind}';

    protected $description = 'Fill the bound volume of each treatise from its shelfmark';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Manuscript::class);
        $counts = ['copies' => 0, 'with_key' => 0, 'changed' => 0, 'kept:protected' => 0];
        $examples = [];
        $volumes = [];

        DB::table('manuscripts')->select(['id', 'library_id', 'library', 'shelfmark', 'collection_key'])->orderBy('id')
            ->chunkById(5000, function ($rows) use ($apply, $guard, &$counts, &$examples, &$volumes) {
                $writes = [];
                foreach ($rows as $row) {
                    $counts['copies']++;
                    $key = Manuscript::collectionKeyOf($row->shelfmark);
                    if ($key !== null) {
                        $counts['with_key']++;
                        $volume = ($row->library_id ?? 'n:' . $row->library) . '|' . $key;
                        $volumes[$volume] = ($volumes[$volume] ?? 0) + 1;
                    }
                    if ($key === $row->collection_key) {
                        continue;
                    }
                    if (! DerivedData::collectionChange($row, $guard)) {
                        $counts['kept:protected']++;

                        continue;
                    }
                    $counts['changed']++;
                    $writes[$row->id] = $key;
                    if (count($examples) < (int) $this->option('show')) {
                        $examples[] = "#{$row->id} «{$row->shelfmark}»: " . ($row->collection_key ?? '—') . ' → ' . ($key ?? '—');
                    }
                }
                if (! $apply || ! $writes) {
                    return;
                }
                DB::transaction(function () use ($writes) {
                    foreach ($writes as $id => $key) {
                        DB::table('manuscripts')->where('id', $id)->update(['collection_key' => $key]);
                    }
                });
            });

        $sizes = array_count_values(array_map(fn ($n) => $n >= 20 ? '20+' : ($n >= 5 ? '5-19' : (string) $n), $volumes));
        $counts['volumes'] = count($volumes);
        $counts['volumes_of_2+'] = count(array_filter($volumes, fn ($n) => $n > 1));
        $counts['copies_in_volumes_of_2+'] = array_sum(array_filter($volumes, fn ($n) => $n > 1));

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-26s %8d', $kind, $n));
        }
        $this->line('  copies per volume: ' . implode(', ', array_map(fn ($k) => "{$k}: {$sizes[$k]}", array_intersect(['1', '2', '3', '4', '5-19', '20+'], array_keys($sizes)))));
        if ($examples) {
            $this->line('-- changed');
            foreach ($examples as $line) {
                $this->line("   {$line}");
            }
        }

        return self::SUCCESS;
    }
}
