<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkAutographScribesCommand extends Command
{
    protected $signature = 'fahares:link-autograph-scribes {--sync-search : Sync updated records to Meilisearch}';

    protected $description = 'Link autograph manuscripts to their work authors as scribes and update people statistics';

    public function handle(): int
    {
        $this->info('Linking autograph manuscripts to their work authors as scribes...');

        $affected = DB::update("
            UPDATE manuscripts m
            JOIN works w ON m.work_id = w.id
            SET m.scribe_id = w.author_id
            WHERE m.is_autograph = 1
              AND m.scribe_id IS NULL
              AND w.author_id IS NOT NULL
        ");

        $this->info("Updated {$affected} autograph manuscripts with scribe_id.");

        $this->info('Recalculating scribe statistics on people table...');
        DB::statement("
            UPDATE people p
            JOIN (
                SELECT scribe_id, COUNT(*) as cnt
                FROM manuscripts
                WHERE scribe_id IS NOT NULL
                GROUP BY scribe_id
            ) m_counts ON p.id = m_counts.scribe_id
            SET p.is_scribe = 1,
                p.manuscripts_count = m_counts.cnt
        ");

        if ($this->option('sync-search')) {
            $this->info('Syncing updated autograph manuscripts to Meilisearch...');
            Manuscript::where('is_autograph', 1)->searchable();

            $this->info('Syncing scribes to Meilisearch...');
            Person::where('is_scribe', 1)->searchable();
        }

        $this->info('Done! Autograph manuscripts are now linked to their respective authors.');

        return 0;
    }
}
