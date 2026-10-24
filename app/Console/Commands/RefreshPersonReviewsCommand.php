<?php

namespace App\Console\Commands;

use App\Services\PersonReviewQueue;
use Illuminate\Console\Command;

/**
 * Rebuilds the admin queue «بازبینی اشخاص» from the data (see PersonReviewQueue). New groups take
 * their starting suggestion from the seed file of earlier judgements. Safe to re-run.
 */
class RefreshPersonReviewsCommand extends Command
{
    protected $signature = 'fahares:refresh-person-reviews
                            {--seed= : Earlier judgements by group key (default: database/data/duplicate_people_review_suggestions.json)}';

    protected $description = 'Rebuild the admin queue of person records that may be one person or several';

    public function handle(PersonReviewQueue $queue): int
    {
        $path = $this->option('seed') ?: database_path('data/duplicate_people_review_suggestions.json');
        $seed = is_file($path) ? collect(json_decode(file_get_contents($path), true)['groups'] ?? [])->keyBy('key')->all() : [];

        $stats = $queue->refresh($seed);
        $this->info(sprintf('Created %d, updated %d, reopened %d, closed %d as gone.', $stats['created'], $stats['updated'], $stats['reopened'], $stats['gone']));

        return self::SUCCESS;
    }
}
