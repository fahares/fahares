<?php

namespace App\Console\Commands;

use App\Services\DuplicatePeopleFinder;
use Illuminate\Console\Command;

/**
 * Writes the report of person records that are probably the same person written differently
 * (see DuplicatePeopleFinder), for fahares:merge-duplicate-people and the admin review queue.
 */
class FindDuplicatePeopleCommand extends Command
{
    protected $signature = 'fahares:find-duplicate-people
                            {--out= : Report path (default: storage/app/duplicate_people.json)}
                            {--loose : Report the clear groups the exact key leaves apart: names differing by titles only, or a record named as another one\'s other name (step 14.121)}';

    protected $description = 'Report person records that are probably the same person written differently';

    public static function key(string $name): string
    {
        return DuplicatePeopleFinder::key($name);
    }

    public function handle(): int
    {
        ['stats' => $stats, 'groups' => $report] = $this->option('loose') ? DuplicatePeopleFinder::looseGroups() : DuplicatePeopleFinder::groups();

        $path = $this->option('out') ?: storage_path('app/duplicate_people.json');
        file_put_contents($path, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'loose' => (bool) $this->option('loose'),
            'stats' => $stats,
            'groups' => $report,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

        foreach ($stats as $tier => $n) {
            $this->line(sprintf('%-8s %d groups', $tier, $n));
        }
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}
