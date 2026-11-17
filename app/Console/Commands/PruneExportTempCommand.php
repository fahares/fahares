<?php

namespace App\Console\Commands;

use App\Support\SearchExport;
use Illuminate\Console\Command;

/**
 * Removes the temporary folders of xlsx exports whose process was killed before it could remove them
 * (SearchExport::pruneTempFolders, step 14.243). Run hourly by the scheduler; a leftover in the container's /tmp
 * blocks the release push.
 */
class PruneExportTempCommand extends Command
{
    protected $signature = 'fahares:prune-export-temp
        {--minutes=' . SearchExport::TEMP_MAX_AGE_MINUTES . ' : Only leftovers unchanged for this many minutes}';

    protected $description = 'Remove leftover temporary folders of search exports';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $removed = SearchExport::pruneTempFolders(minutes: $minutes);
        foreach ($removed as $path) {
            $this->line("removed {$path}");
        }
        if ($removed || $this->getOutput()->isVerbose()) {
            $this->info(count($removed) . ' export leftovers removed.');
        }

        return self::SUCCESS;
    }
}
