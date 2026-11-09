<?php

namespace App\Console\Commands;

use App\Models\EntityRedirect;
use App\Models\Person;
use App\Models\PersonReview;
use App\Services\PersonReviewQueue;
use Illuminate\Console\Command;

/**
 * Puts namesake groups a model review left unsure into the queue «بازبینی اشخاص» (type duplicate), so the
 * researchers of «تصحیح جمعی» answer them in «اشخاص هم‌نام» and a reviewer decides in the panel (step 14.124).
 * A record merged since is followed to the record it went into; a group left with fewer than two records is
 * settled already and skipped, and so is a group the queue holds already (which takes the notes when it has no
 * suggestion). Each item keeps the reviews' notes as its starting suggestion («نامطمئن»). Safe to re-run.
 */
class QueueJudgedPeopleCommand extends Command
{
    protected $signature = 'fahares:queue-judged-people
        {--file= : Groups to queue (default: database/data/duplicate_people_unsure_step14_124.json)}
        {--dry-run : Count what would be queued without writing}';

    protected $description = 'Queue namesake groups a model review left unsure for «تصحیح جمعی»';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/duplicate_people_unsure_step14_124.json');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }
        $groups = json_decode(file_get_contents($path), true)['groups'] ?? [];
        $dry = (bool) $this->option('dry-run');
        $stats = ['queued' => 0, 'already' => 0, 'settled' => 0];

        foreach ($groups as $group) {
            $ids = collect($group['ids'])->map(fn ($id) => $this->current((int) $id))->filter()->unique()->sort()->values()->all();
            if (count($ids) < 2) {
                $stats['settled']++;

                continue;
            }
            $key = PersonReviewQueue::JUDGED . implode(',', $ids);
            $held = PersonReview::where('type', 'duplicate')->where(fn ($q) => $q->where('key', $key)
                ->orWhere('person_ids', json_encode($ids)))->first();
            if ($held) {
                // A group the finder queued already: it gets the reviews' notes when it has no suggestion of its own
                if (! $dry && $held->isOpen() && empty($held->suggestion) && ! empty($group['note'])) {
                    $held->update(['suggestion' => ['decision' => 'unsure', 'comment' => $group['note'], 'source' => 'model review, step 14.123']]);
                }
                $stats['already']++;

                continue;
            }
            if (! $dry) {
                PersonReview::create([
                    'type' => 'duplicate', 'key' => $key, 'person_ids' => $ids, 'tier' => 'review',
                    'reason' => 'model review unsure (step 14.123)', 'status' => 'open',
                    'suggestion' => ['decision' => 'unsure', 'comment' => $group['note'] ?? null, 'source' => 'model review, step 14.123'],
                ]);
            }
            $stats['queued']++;
        }

        $this->info(($dry ? '[dry run] ' : '') . sprintf('Queued %d groups; %d already in the queue; %d settled since.', $stats['queued'], $stats['already'], $stats['settled']));

        return self::SUCCESS;
    }

    /** The record as it is now, following merge redirects; null when it is gone. */
    private function current(int $id): ?int
    {
        if (Person::whereKey($id)->exists()) {
            return $id;
        }
        $target = EntityRedirect::resolveTargetId('people', $id);

        return $target && Person::whereKey($target)->exists() ? (int) $target : null;
    }
}
