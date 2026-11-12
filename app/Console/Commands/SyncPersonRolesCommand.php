<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;

/**
 * Reports, and with --apply mends, the people whose stored counts and author or scribe flag differ from their rows
 * of work_people and manuscript_people (step 14.153). The rows' hooks keep them in step as records are edited; a
 * command that writes the rows with DB::table runs no hook, so this runs each night (routes/console.php). The
 * search tabs of authors and of scribes are divided by these flags. Each person is written through
 * Person::recountContributions, the one write path, which also sends the person to the search index.
 */
class SyncPersonRolesCommand extends Command
{
    protected $signature = 'fahares:sync-person-roles
        {--apply : Write what differs; without it the command only reports}
        {--show=8 : How many examples to list}';

    protected $description = 'Report people whose counts and author/scribe flags differ from their works and copies, and with --apply bring them in step';

    public function handle(): int
    {
        $drift = Person::roleDrift();
        $kinds = [
            'author flag to set' => fn ($p) => ! $p->is_author && $p->works > 0,
            'author flag to clear' => fn ($p) => $p->is_author && $p->works == 0,
            'scribe flag to set' => fn ($p) => ! $p->is_scribe && $p->manuscripts > 0,
            'scribe flag to clear' => fn ($p) => $p->is_scribe && $p->manuscripts == 0,
            'works count' => fn ($p) => (int) $p->works_count !== (int) $p->works,
            'manuscripts count' => fn ($p) => (int) $p->manuscripts_count !== (int) $p->manuscripts,
        ];
        $this->table(['differs', 'people'], collect($kinds)->map(fn ($test, $kind) => [$kind, $drift->filter($test)->count()])->values()->all());
        foreach ($drift->take((int) $this->option('show')) as $p) {
            $this->line("  #{$p->id} {$p->name}: works {$p->works_count} → {$p->works}, copies {$p->manuscripts_count} → {$p->manuscripts}, "
                . 'author ' . (int) $p->is_author . ' → ' . (int) ($p->works > 0) . ', scribe ' . (int) $p->is_scribe . ' → ' . (int) ($p->manuscripts > 0));
        }
        $this->info($drift->count() . ' people differ.');
        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        foreach ($drift->pluck('id')->chunk(500) as $ids) {
            Person::recountMany($ids);
        }
        $this->info('Written. ' . Person::roleDrift()->count() . ' people still differ.');

        return self::SUCCESS;
    }
}
