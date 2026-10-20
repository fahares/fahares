<?php

namespace App\Console\Commands;

use App\Support\Insights;
use Illuminate\Console\Command;

/**
 * Builds the figures of the statistical insights page (/insights) and keeps them in the cache.
 * Run daily by the scheduler; run it by hand after an import or a bulk edit to show new numbers.
 */
class BuildInsightsCommand extends Command
{
    protected $signature = 'fahares:build-insights';

    protected $description = 'Build and cache the figures of the insights page';

    public function handle(): int
    {
        $start = microtime(true);
        $data = Insights::refresh();

        $this->info(sprintf(
            'Insights built in %.1fs: %d dated copies (%s%%), %d copies in %d cities (%s%%), %d top works.',
            microtime(true) - $start,
            $data['timeline']['shown'],
            $data['timeline']['coverage'],
            $data['places']['mapped'],
            count($data['places']['cities']),
            $data['places']['coverage'],
            count($data['top_works']['items']),
        ));

        return self::SUCCESS;
    }
}
