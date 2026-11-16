<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\PersonPeriod;
use App\Support\LifePeriodExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads the biographies tied to people in the sources (source_entries of a person with the entry's text) for the
 * periods of their lives, and files what it finds as proposals for the reviewers (backlog item 139, step 14.220):
 * person_periods rows with status «proposed» and origin «machine», shown to reviewers on the person's page and at
 * admin/period-proposals, never to readers. Nothing is proposed twice: a person who has a period of the same kind in
 * the same city, approved, proposed or rejected, gets no other. Without --apply it only counts.
 */
class ProposeLifePeriodsCommand extends Command
{
    protected $signature = 'fahares:propose-life-periods
        {--apply : File the proposals; without it the command only counts}
        {--person= : Only this person}
        {--show=10 : How many proposals to list}';

    protected $description = 'Propose periods of people\'s lives from the biographies in the sources';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['entries' => 0, 'read' => 0, 'new' => 0, 'known' => 0, 'people' => 0, 'with_years' => 0];
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
            ->orderBy('se.id')->chunkById(300, function ($entries) use ($apply, &$counts, &$kinds, &$shown, &$people, &$proposed) {
                // what each person of the chunk has already, of any status
                $have = $proposed;
                foreach (DB::table('person_periods')->whereIn('person_id', $entries->pluck('person_id')->unique())->get(['person_id', 'kind', 'city']) as $row) {
                    $have["{$row->person_id}|{$row->kind}|{$row->city}"] = true;
                }
                foreach ($entries as $entry) {
                    $counts['entries']++;
                    foreach (LifePeriodExtractor::extract($entry->text) as $found) {
                        $counts['read']++;
                        $key = "{$entry->person_id}|{$found['kind']}|{$found['city']}";
                        if (isset($have[$key])) {
                            $counts['known']++;
                            continue;
                        }
                        $have[$key] = $proposed[$key] = true;
                        $counts['new']++;
                        $counts['with_years'] += $found['years'] ? 1 : 0;
                        $kinds[$found['kind']] = ($kinds[$found['kind']] ?? 0) + 1;
                        $people[$entry->person_id] = true;
                        $source = trim($entry->source . ($entry->volume ? ' ' . $entry->volume . ':' : '') . ($entry->page ? ' ' . $entry->page : ''));
                        if (count($shown) < (int) $this->option('show')) {
                            $shown[] = sprintf('#%d %s · %s · %s · «%s» (%s)', $entry->person_id, PersonPeriod::KINDS[$found['kind']], $found['city'],
                                implode('، ', $found['years']) ?: '—', mb_substr($found['snippet'], 0, 90), $source);
                        }
                        if ($apply) {
                            // one year is that year; several are «some time between the first and the last», for the reviewer to settle
                            $years = $found['years'];
                            $date = $years === [] ? [] : PersonPeriod::dateColumns(count($years) === 1
                                ? ['mode' => 'point', 'start' => $years[0]] : ['mode' => 'between', 'start' => min($years), 'end' => max($years)]);
                            PersonPeriod::create(['person_id' => $entry->person_id, 'kind' => $found['kind'], 'status' => PersonPeriod::PROPOSED, 'origin' => 'machine',
                                'place' => $found['city'], 'note' => mb_substr($found['snippet'], 0, 500), 'source_text' => mb_substr($source, 0, 500),
                                'source_entry_id' => $entry->id] + (isset($date['error']) ? [] : $date));
                        }
                    }
                }
            }, 'se.id', 'id');
        $counts['people'] = count($people);

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
}
