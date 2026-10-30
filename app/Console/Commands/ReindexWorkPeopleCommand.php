<?php

namespace App\Console\Commands;

use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reindexes only the works that have people in other roles (work_people), for the related_people field of their
 * search document (step 13.76). A full scout:import of the 71k works is killed by any deploy that replaces the
 * container; the others' documents need nothing, since their field is empty. Safe to run again.
 */
class ReindexWorkPeopleCommand extends Command
{
    protected $signature = 'fahares:reindex-work-people {--chunk=250 : Works per batch}';

    protected $description = 'Reindex the works that have translators, dedicatees, commissioners… (work_people)';

    public function handle(): int
    {
        $ids = DB::table('work_people')->distinct()->orderBy('work_id')->pluck('work_id');
        $done = 0;
        foreach ($ids->chunk(max(1, (int) $this->option('chunk'))) as $chunk) {
            // The same eager loading as scout:import
            (new Work)->makeAllSearchableUsing(Work::whereIn('id', $chunk->all()))->get()->searchable();
            $done += $chunk->count();
            $this->line("Reindexed {$done} of {$ids->count()} works");
        }

        return self::SUCCESS;
    }
}
