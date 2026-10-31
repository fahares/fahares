<?php

namespace App\Console\Commands;

use App\Support\PageViews;
use Illuminate\Console\Command;

/**
 * Visit stats (backlog item 103): loads the page views noted in the last minutes into page_views. Run every
 * minute by the scheduler.
 */
class FlushPageViewsCommand extends Command
{
    protected $signature = 'fahares:flush-page-views {--all : Also the files of the last two minutes, which may still be written to}';

    protected $description = 'Load the noted page views into page_views';

    public function handle(): int
    {
        $loaded = PageViews::flush((bool) $this->option('all'));
        if ($loaded || $this->getOutput()->isVerbose()) {
            $this->info("{$loaded} page views loaded.");
        }

        return self::SUCCESS;
    }
}
