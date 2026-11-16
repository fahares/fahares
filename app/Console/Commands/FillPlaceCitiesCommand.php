<?php

namespace App\Console\Commands;

use App\Support\CopyPlaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the city of each place text (manuscripts.copy_city from copy_place, works.composition_city from
 * composition_place; backlog item 139, step 14.209) as App\Support\CopyPlaces reads it, and the country or region it
 * names (copy_region, composition_region; step 14.211). Saving a copy or a work keeps
 * the city in step from then on; this is for the records there are, for the corpus commands, which write the place
 * with DB::table, and for after a change of the city list (config/insights.php copy_places). The place text is never
 * touched. A run only rewrites what differs and can be repeated; without --apply it only counts.
 */
class FillPlaceCitiesCommand extends Command
{
    protected $signature = 'fahares:fill-place-cities
        {--apply : Write the cities; without it the command only counts}
        {--show=15 : How many of the most frequent place texts with neither a city nor a region to list}';

    protected $description = 'Fill the city and the region of copy_place and composition_place';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        foreach ([['manuscripts', 'copy_place', 'copy_city', 'copy_region'], ['works', 'composition_place', 'composition_city', 'composition_region']] as [$table, $place, $city, $region]) {
            $counts = ['texts' => 0, 'records' => 0, 'with_city' => 0, 'with_region' => 0, 'with_either' => 0, 'changed' => 0];
            $none = [];
            $updates = [];
            $texts = [];
            // the text decides the city and the region; the records are written by id, a pair at a time (the place has no index)
            DB::table($table)->where(fn ($q) => $q->whereNotNull($place)->orWhereNotNull($city)->orWhereNotNull($region))
                ->select(['id', "{$place} as place", "{$city} as city", "{$region} as region"])
                ->orderBy('id')->chunkById(5000, function ($rows) use (&$counts, &$none, &$updates, &$texts) {
                    foreach ($rows as $row) {
                        $text = (string) $row->place;
                        // a place that was emptied keeps neither
                        [$nowCity, $nowRegion] = $text === '' ? [null, null] : ($texts[$text] ??= [CopyPlaces::cityOf($text), CopyPlaces::regionOf($text)]);
                        if ($text !== '') {
                            $counts['records']++;
                            $counts['with_city'] += $nowCity !== null ? 1 : 0;
                            $counts['with_region'] += $nowRegion !== null ? 1 : 0;
                            if ($nowCity === null && $nowRegion === null) {
                                $none[$text] = ($none[$text] ?? 0) + 1;
                            } else {
                                $counts['with_either']++;
                            }
                        }
                        if ($nowCity !== $row->city || $nowRegion !== $row->region) {
                            $counts['changed']++;
                            $updates[json_encode([$nowCity, $nowRegion], JSON_UNESCAPED_UNICODE)][] = $row->id;
                        }
                    }
                });
            $counts['texts'] = count($texts);
            if ($apply) {
                DB::transaction(function () use ($updates, $table, $city, $region) {
                    foreach ($updates as $pair => $ids) {
                        [$nowCity, $nowRegion] = json_decode($pair, true);
                        foreach (array_chunk($ids, 1000) as $chunk) {
                            DB::table($table)->whereIn('id', $chunk)->update([$city => $nowCity, $region => $nowRegion]);
                        }
                    }
                });
            }
            $this->info("{$table}.{$city}, {$region}: " . ($apply ? 'written.' : 'dry run, nothing written.'));
            foreach ($counts as $kind => $n) {
                $this->line(sprintf('  %-11s %8d', $kind, $n));
            }
            arsort($none);
            foreach (array_slice($none, 0, (int) $this->option('show'), true) as $text => $n) {
                $this->line(sprintf('  neither %6d  %s', $n, mb_substr($text, 0, 60)));
            }
        }
        if ($apply) {
            // the search filter's list of place texts per city is cached for a day
            CopyPlaces::variantMap(true);
        }

        return self::SUCCESS;
    }
}
