<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\EstimateText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reindexes the records whose search document has the estimate fields: the works with a year of composition or a
 * placed estimate of it (era_estimate_from, era_estimate_to, composition_sort_year; step 14.197), or with --people the
 * people with a death year, a century or a year they were last seen alive (alive_century, death_sort_year; step
 * 14.198; era_year, step 14.213). The other documents need nothing: the fields are null there. --all takes every
 * person, for a change that emptied such a field (a death year that was no Hijri year, step 14.214). Run once after scout:sync-index-settings made the fields
 * filterable and sortable; from then on fahares:estimate-dates refreshes what changed.
 *
 * The first run on production (step 14.201) sent 35 batches in ten minutes while Meilisearch was rebuilding the index
 * for the new settings, and the site slowed to a crawl. So the command paces itself: it sends a batch only when
 * Meilisearch has at most --max-queued tasks waiting, and rests --sleep seconds after each. Safe to run again, and to
 * resume with --from (and to cut into parts with --to).
 */
class ReindexWorkEstimatesCommand extends Command
{
    protected $signature = 'fahares:reindex-work-estimates
        {--people : Reindex the people instead of the works}
        {--all : With --people, every person, whatever the document holds}
        {--chunk=100 : Records per batch}
        {--from=0 : The first id to reindex (to resume an interrupted run)}
        {--to=0 : The last id to reindex; 0 for no end}
        {--sleep=2 : Seconds to rest after each batch}
        {--max-queued=1 : Wait while Meilisearch has more tasks than this waiting or in work}';

    protected $description = 'Reindex the works (or the people) whose search document carries the estimate fields, at a pace the site can bear';

    public function handle(): int
    {
        [$from, $to] = [(int) $this->option('from'), (int) $this->option('to')];
        $people = (bool) $this->option('people');
        $within = fn ($query, string $column) => $query->where($column, '>=', $from)->when($to > 0, fn ($q) => $q->where($column, '<=', $to));

        $ids = $people
            ? $within(DB::table('people'), 'id')->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q->whereNotNull('death_year_hijri')
                ->orWhereNotNull('century_hijri')->orWhereRaw(EstimateText::ALIVE_YEAR_SQL . ' IS NOT NULL')))->pluck('id')
            : $within(DB::table('works'), 'id')->whereNotNull('composition_year_hijri')->pluck('id')
                ->merge($within(EstimateText::placedCompositions(DB::query()), 'date_estimates.subject_id')->pluck('date_estimates.subject_id'));
        $ids = $ids->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $noun = $people ? 'people' : 'works';
        $model = $people ? new Person : new Work;
        $done = 0;
        foreach ($ids->chunk(max(1, (int) $this->option('chunk'))) as $chunk) {
            $this->waitForEngine();
            // The same loading as scout:import
            $model->makeAllSearchableUsing($model->newQuery())->whereIn($model->getTable() . '.id', $chunk->all())->get()->searchable();
            $done += $chunk->count();
            $this->line("Reindexed {$done} of {$ids->count()} {$noun} (up to #{$chunk->last()})");
            sleep(max(0, (int) $this->option('sleep')));
        }

        return self::SUCCESS;
    }

    /** Holds the next batch back while Meilisearch still has earlier ones to work through. */
    private function waitForEngine(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }
        $limit = max(0, (int) $this->option('max-queued'));
        $client = app(\Meilisearch\Client::class);
        $waited = 0;
        while (($pending = $client->getTasks((new \Meilisearch\Contracts\TasksQuery)->setStatuses(['enqueued', 'processing'])->setLimit(1))->getTotal()) > $limit) {
            if ($waited % 60 === 0) {
                $this->line("  waiting: Meilisearch has {$pending} tasks to work through");
            }
            sleep(5);
            $waited += 5;
        }
    }
}
