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
                            {--out= : Report path (default: storage/app/duplicate_people.json)}';

    protected $description = 'Report person records that are probably the same person written differently';

    public static function key(string $name): string
    {
        return DuplicatePeopleFinder::key($name);
    }

    public function handle(): int
    {
        ['stats' => $stats, 'groups' => $report] = DuplicatePeopleFinder::groups();

        $path = $this->option('out') ?: storage_path('app/duplicate_people.json');
        file_put_contents($path, json_encode([
            'generated_at' => now()->toDateTimeString(),
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
