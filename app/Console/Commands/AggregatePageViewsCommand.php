<?php

namespace App\Console\Commands;

use App\Support\PageViews;
use Illuminate\Console\Command;

/**
 * Visit stats (backlog item 103): folds the past days of page_views into page_view_daily and deletes the single
 * views older than 30 days. Run nightly by the scheduler; safe to run again, a day is folded anew each time.
 */
class AggregatePageViewsCommand extends Command
{
    protected $signature = 'fahares:aggregate-page-views';

    protected $description = 'Fold the past days of page views into daily counts and prune the old single views';

    public function handle(): int
    {
        // Views of yesterday's last minutes may still wait in the buffer
        $loaded = PageViews::flush(true);
        $days = PageViews::aggregate();
        $pruned = PageViews::prune();

        $this->info(sprintf('%d views loaded, %d days folded (%s), %d old views deleted.',
            $loaded, count($days), $days ? reset($days) . ' … ' . end($days) : '-', $pruned));

        return self::SUCCESS;
    }
}
