<?php

namespace App\Console\Commands;

use App\Support\CopyPlaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the city of each place text (manuscripts.copy_city from copy_place, works.composition_city from
 * composition_place; backlog item 139, step 14.209) as App\Support\CopyPlaces reads it. Saving a copy or a work keeps
 * the city in step from then on; this is for the records there are, for the corpus commands, which write the place
 * with DB::table, and for after a change of the city list (config/insights.php copy_places). The place text is never
 * touched. A run only rewrites what differs and can be repeated; without --apply it only counts.
 */
class FillPlaceCitiesCommand extends Command
{
    protected $signature = 'fahares:fill-place-cities
        {--apply : Write the cities; without it the command only counts}
        {--show=15 : How many of the most frequent place texts with no city to list}';

    protected $description = 'Fill copy_city and composition_city from the place texts';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        foreach ([['manuscripts', 'copy_place', 'copy_city'], ['works', 'composition_place', 'composition_city']] as [$table, $place, $city]) {
            $counts = ['texts' => 0, 'records' => 0, 'with_city' => 0, 'changed' => 0];
            $none = [];
            $updates = [];
            $texts = [];
            // the text decides the city; the records are written by id, a city at a time (the place has no index)
            DB::table($table)->where(fn ($q) => $q->whereNotNull($place)->orWhereNotNull($city))->select(['id', "{$place} as place", "{$city} as city"])
                ->orderBy('id')->chunkById(5000, function ($rows) use (&$counts, &$none, &$updates, &$texts) {
                    foreach ($rows as $row) {
                        $text = (string) $row->place;
                        $now = $text === '' ? null : ($texts[$text] ??= [CopyPlaces::cityOf($text)])[0];
                        if ($text !== '') {
                            $counts['records']++;
                            if ($now === null) {
                                $none[$text] = ($none[$text] ?? 0) + 1;
                            } else {
                                $counts['with_city']++;
                            }
                        }
                        // a place that was emptied keeps no city
                        if ($now !== $row->city) {
                            $counts['changed']++;
                            $updates[$now ?? ''][] = $row->id;
                        }
                    }
                });
            $counts['texts'] = count($texts);
            if ($apply) {
                DB::transaction(function () use ($updates, $table, $city) {
                    foreach ($updates as $now => $ids) {
                        foreach (array_chunk($ids, 1000) as $chunk) {
                            DB::table($table)->whereIn('id', $chunk)->update([$city => $now === '' ? null : $now]);
                        }
                    }
                });
            }
            $this->info("{$table}.{$city}: " . ($apply ? 'written.' : 'dry run, nothing written.'));
            foreach ($counts as $kind => $n) {
                $this->line(sprintf('  %-10s %8d', $kind, $n));
            }
            arsort($none);
            foreach (array_slice($none, 0, (int) $this->option('show'), true) as $text => $n) {
                $this->line(sprintf('  no city %6d  %s', $n, mb_substr($text, 0, 60)));
            }
        }
        if ($apply) {
            // the search filter's list of place texts per city is cached for a day
            CopyPlaces::variantMap(true);
        }

        return self::SUCCESS;
    }
}
