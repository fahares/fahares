<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\PersonPeriod;
use App\Support\LifePeriodExtractor;
use App\Support\PersonOrigins;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads the biographies tied to people in the sources (source_entries of a person with the entry's text) for the
 * periods of their lives, and files what it finds as proposals for the reviewers (backlog item 139, step 14.220):
 * person_periods rows with status «proposed» and origin «machine», shown to reviewers on the person's page and at
 * admin/period-proposals, never to readers. Nothing is proposed twice: a person who has a period of the same kind in
 * the same city, approved, proposed or rejected, gets no other. Without --apply it only counts. Beside the periods
 * (LifePeriodExtractor), the cities of birth, of death and of origin are proposed (PersonOrigins, step 14.291), and
 * where a biography names no city for one of those three but a country or a region («من أهل خوارزم», «ولد بالهند»),
 * that is proposed (step 14.380); not for a person who already has a city of that kind, of any status.
 *
 * With --prune (step 14.227) the proposals a tighter reading no longer gives are taken back: a row that is still
 * «proposed» and of origin «machine» is deleted when the biographies no longer yield its kind and city for the person,
 * and the years and the quoted clause of one they still yield are read again. An approved or a rejected period is a
 * reviewer's word and is never touched.
 */
class ProposeLifePeriodsCommand extends Command
{
    protected $signature = 'fahares:propose-life-periods
        {--apply : File the proposals; without it the command only counts}
        {--person= : Only this person}
        {--prune : Take back the waiting proposals the reader no longer gives, and read the others again}
        {--show=10 : How many proposals to list}';

    protected $description = 'Propose periods of people\'s lives from the biographies in the sources';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['entries' => 0, 'read' => 0, 'new' => 0, 'known' => 0, 'people' => 0, 'with_years' => 0, 'regions' => 0, 'beside_a_place' => 0];
        // every reading of this run: "person|kind|city (or country or region)" => the columns a proposal of it holds
        $yielded = [];
        $kinds = [];
        $shown = [];
        $people = [];
        // what this run has proposed so far: a dry run writes nothing, and a person's entries may lie in two chunks
        $proposed = [];
        DB::table('source_entries as se')->join('source_items as si', 'si.id', '=', 'se.source_item_id')->join('sources as s', 's.id', '=', 'se.source_id')
            // an entry may still name a person that was merged away or removed
            ->join('people as p', 'p.id', '=', 'se.citable_id')
            ->where('se.citable_type', Person::class)->whereNotNull('si.text')
            ->when($this->option('person'), fn ($q, $id) => $q->where('se.citable_id', (int) $id))
            ->select(['se.id', 'se.citable_id as person_id', 's.title as source', 'se.volume_number as volume', 'se.page_start as page', 'si.text'])
            ->orderBy('se.id')->chunkById(300, function ($entries) use ($apply, &$counts, &$kinds, &$shown, &$people, &$proposed, &$yielded) {
                // what each person of the chunk has already, of any status
                $have = $proposed;
                // … and the kinds a person has a city for: no country or region is proposed beside it
                $placed = [];
                foreach (array_keys($proposed) as $key) {
                    $placed[implode('|', array_slice(explode('|', $key), 0, 2))] = true;
                }
                foreach (DB::table('person_periods')->whereIn('person_id', $entries->pluck('person_id')->unique())->get(['person_id', 'kind', 'city', 'region']) as $row) {
                    $have["{$row->person_id}|{$row->kind}|" . ($row->city ?: $row->region)] = true;
                    $placed["{$row->person_id}|{$row->kind}"] = true;
                }
                foreach ($entries as $entry) {
                    $counts['entries']++;
                    // The periods of the life, and the cities of birth, death and origin the entry names (step 14.291)
                    $readings = array_merge(LifePeriodExtractor::extract($entry->text), array_map(
                        fn (array $hit) => ['kind' => $hit['kind'], 'city' => $hit['city'], 'years' => [], 'snippet' => $hit['text'], 'region' => $hit['region'] ?? false],
                        PersonOrigins::fromBiography($entry->text, true)));
                    foreach ($readings as $found) {
                        $counts['read']++;
                        $key = "{$entry->person_id}|{$found['kind']}|{$found['city']}";
                        $source = trim($entry->source . ($entry->volume ? ' ' . $entry->volume . ':' : '') . ($entry->page ? ' ' . $entry->page : ''));
                        $yielded[$key] ??= self::columns($found, $source, (int) $entry->id);
                        if (isset($have[$key])) {
                            $counts['known']++;
                            continue;
                        }
                        // A country or a region says less than the place of that kind the person has already
                        if (($found['region'] ?? false) && isset($placed["{$entry->person_id}|{$found['kind']}"])) {
                            $counts['beside_a_place']++;
                            continue;
                        }
                        $have[$key] = $proposed[$key] = $placed["{$entry->person_id}|{$found['kind']}"] = true;
                        $counts['regions'] += ($found['region'] ?? false) ? 1 : 0;
                        $counts['new']++;
                        $counts['with_years'] += $found['years'] ? 1 : 0;
                        $kinds[$found['kind']] = ($kinds[$found['kind']] ?? 0) + 1;
                        $people[$entry->person_id] = true;
                        if (count($shown) < (int) $this->option('show')) {
                            $shown[] = sprintf('#%d %s · %s · %s · «%s» (%s)', $entry->person_id, PersonPeriod::KINDS[$found['kind']], $found['city'],
                                implode('، ', $found['years']) ?: '—', mb_substr($found['snippet'], 0, 90), $source);
                        }
                        if ($apply) {
                            PersonPeriod::create(['person_id' => $entry->person_id, 'kind' => $found['kind'], 'status' => PersonPeriod::PROPOSED, 'origin' => 'machine']
                                + $yielded[$key]);
                        }
                    }
                }
            }, 'se.id', 'id');
        $counts['people'] = count($people);
        if ($this->option('prune')) {
            $counts += ['pruned' => 0, 'reread' => 0];
            $dateColumns = array_keys((new \App\Support\DateSpan(null, null))->columns('period'));
            $waiting = PersonPeriod::where('status', PersonPeriod::PROPOSED)->where('origin', 'machine')
                ->when($this->option('person'), fn ($q, $id) => $q->where('person_id', (int) $id))->orderBy('id')->get();
            foreach ($waiting as $row) {
                $now = $yielded["{$row->person_id}|{$row->kind}|" . ($row->city ?: $row->region)] ?? null;
                if ($now === null) {
                    $counts['pruned']++;
                    if ($apply) {
                        $row->delete();
                    }
                    continue;
                }
                // a reading with no year clears the years of the earlier one
                $now += array_fill_keys($dateColumns, null);
                if (array_intersect_key($row->getAttributes(), $now) != $now) {
                    $counts['reread']++;
                    if ($apply) {
                        $row->update($now);
                    }
                }
            }
        }

        $this->info($apply ? 'Proposals filed.' : 'Dry run: nothing written.');
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-11s %6d', $kind, $n));
        }
        foreach ($kinds as $kind => $n) {
            $this->line(sprintf('  %-11s %6d', PersonPeriod::KINDS[$kind], $n));
        }
        foreach ($shown as $line) {
            $this->line('  ' . $line);
        }

        return self::SUCCESS;
    }

    /**
     * The columns of a proposal: the city as its place, the clause as its note, the source, and the years: one year is
     * that year, several are «some time between the first and the last», for the reviewer to settle.
     */
    private static function columns(array $found, string $source, int $entryId): array
    {
        $years = $found['years'];
        $date = $years === [] ? [] : PersonPeriod::dateColumns(count($years) === 1
            ? ['mode' => 'point', 'start' => $years[0]] : ['mode' => 'between', 'start' => min($years), 'end' => max($years)]);

        return ['place' => $found['city'], 'note' => mb_substr($found['snippet'], 0, 500), 'source_text' => mb_substr($source, 0, 500),
            'source_entry_id' => $entryId] + (isset($date['error']) ? [] : $date);
    }
}
