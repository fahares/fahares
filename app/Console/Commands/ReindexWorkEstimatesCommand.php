<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Support\DateEstimates\EstimateText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reindexes the works whose search document has the fields of step 14.197: those with a year of composition
 * (composition_sort_year) and those of no date with a placed estimate (era_estimate_from, era_estimate_to and
 * composition_sort_year). The other works' documents need nothing: the fields are null there. Run once after the
 * fields are added and scout:sync-index-settings made them filterable and sortable; from then on
 * fahares:estimate-dates refreshes the works whose estimate changed. Safe to run again, and to resume with --from.
 */
class ReindexWorkEstimatesCommand extends Command
{
    protected $signature = 'fahares:reindex-work-estimates
        {--chunk=250 : Works per batch}
        {--from=0 : Resume after an interrupted run: the first work id to reindex}';

    protected $description = 'Reindex the works that have a year of composition or a placed estimate of it';

    public function handle(): int
    {
        $from = (int) $this->option('from');
        $ids = DB::table('works')->whereNotNull('composition_year_hijri')->where('id', '>=', $from)->pluck('id')
            ->merge(EstimateText::placedCompositions(DB::query())->where('date_estimates.subject_id', '>=', $from)->pluck('date_estimates.subject_id'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $done = 0;
        foreach ($ids->chunk(max(1, (int) $this->option('chunk'))) as $chunk) {
            // The same eager loading as scout:import
            (new Work)->makeAllSearchableUsing(Work::query())->whereIn('works.id', $chunk->all())->get()->searchable();
            $done += $chunk->count();
            $this->line("Reindexed {$done} of {$ids->count()} works (up to #{$chunk->last()})");
        }

        return self::SUCCESS;
    }
}
