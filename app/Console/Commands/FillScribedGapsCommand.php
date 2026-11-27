<?php

namespace App\Console\Commands;

use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Services\PersonReviewQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills people.scribed_gap (step 14.430, backlog item 125) for the people already there, in batches of ids, with the
 * value Person::scribeFacts gives, read once and written by id. fahares:sync-person-roles --apply would write the same, one person at a time, which
 * takes most of an hour for every scribe. The people whose «several namesakes» state changes with the new measure (the
 * old one was scribed_to - scribed_from > MIXED_SPAN) go to the search index again, since their estimated years of
 * life are in their documents; then the queue «یک نام، چند کاتب؟» is rebuilt.
 */
class FillScribedGapsCommand extends Command
{
    protected $signature = 'fahares:fill-scribed-gaps
        {--apply : Write the gaps, send the changed people to the index and rebuild the queue; without it only report}
        {--batch=2000 : People ids in one UPDATE}';

    protected $description = 'Fill people.scribed_gap, the years a scribe\'s copies surely lie apart (backlog item 125)';

    public function handle(): int
    {
        $span = PersonReviewQueue::MIXED_SPAN;
        $gaps = DB::table('manuscript_people as mp')->join('manuscripts as m', 'm.id', '=', 'mp.manuscript_id')
            ->where('mp.role', ManuscriptPerson::SCRIBE)->whereNotNull('mp.person_id')
            ->whereBetween('m.copy_date_from', [1, 1500])->whereBetween('m.copy_date_to', [1, 1500])
            ->groupBy('mp.person_id')->selectRaw('mp.person_id, GREATEST(MAX(m.copy_date_from) - MIN(m.copy_date_to), 0) AS g');
        $rows = DB::table('people')->leftJoinSub($gaps, 'y', 'y.person_id', '=', 'people.id')
            ->whereRaw('NOT (people.scribed_gap <=> y.g)')
            ->get(['people.id', 'people.scribed_from', 'people.scribed_to', 'people.scribed_gap', 'y.g']);
        $old = fn ($r) => $r->scribed_from !== null && $r->scribed_to - $r->scribed_from > $span;
        $changed = $rows->filter(fn ($r) => $old($r) !== ($r->g !== null && $r->g > $span))->pluck('id');

        $this->table(['', 'people'], [
            ['gap to write', $rows->count()],
            ['several namesakes, new measure', $rows->filter(fn ($r) => $r->g !== null && $r->g > $span)->count()],
            ['state changes (sent to the index)', $changed->count()],
        ]);
        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        // the values read above, one short UPDATE per batch: the copies' tables are not read again under a lock
        foreach ($rows->sortBy('id')->chunk(max(1, (int) $this->option('batch'))) as $batch) {
            $case = $batch->map(fn ($r) => 'WHEN ' . (int) $r->id . ' THEN ' . ($r->g === null ? 'NULL' : (int) $r->g))->implode(' ');
            DB::table('people')->whereIn('id', $batch->pluck('id')->all())->update(['scribed_gap' => DB::raw("CASE id {$case} END")]);
        }
        foreach ($changed->chunk(500) as $ids) {
            Person::whereIn('id', $ids->all())->get()->searchable();
        }
        $stats = app(PersonReviewQueue::class)->refresh();
        $this->info('Written. Queue: ' . json_encode($stats) . '. ' . Person::roleDrift()->where('years_differ', 1)->count() . ' people still differ in their years.');

        return self::SUCCESS;
    }
}
