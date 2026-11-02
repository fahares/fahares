<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\PersonReview;
use App\Models\Work;
use Illuminate\Console\Command;

/**
 * Puts works whose author is a name only, each with the one person it may be, into the review queue «پیوند پدیدآور»
 * (step 14.17; AuthorLinkQueue, PersonReview type author_link). A work is found by slug, a person by slug or else by
 * the one person of exactly that name; an item is made only while the work still has no linked author and still has
 * that author name, and only once per work. Safe to run again.
 */
class QueueAuthorLinksCommand extends Command
{
    protected $signature = 'fahares:queue-author-links
        {--file= : Candidates file (database/data/author_link_candidates_20261103.json)}
        {--apply : Write; without it the command only counts}';

    protected $description = 'Queue text-only work authors with the one person they may be, for review';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/author_link_candidates_20261103.json');
        $items = json_decode((string) @file_get_contents($path), true)['items'] ?? null;
        if ($items === null) {
            $this->error("Candidates file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $counts = [];
        foreach ($items as $c) {
            $outcome = $this->queue($c, (bool) $this->option('apply'));
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        ksort($counts);
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-28s %5d', $kind, $n));
        }

        return self::SUCCESS;
    }

    private function queue(array $c, bool $apply): string
    {
        $work = Work::where('slug', $c['work_slug'])->first();
        if (! $work) {
            return 'work not found';
        }
        if ($work->author_id || trim((string) $work->author_name) !== $c['author_name']) {
            return 'work author changed';
        }
        $person = Person::where('slug', $c['person_slug'])->first();
        if (! $person || $person->name !== $c['person_name']) {
            $same = Person::where('name', $c['person_name'])->limit(2)->get();
            $person = $same->count() === 1 ? $same->first() : null;
        }
        if (! $person) {
            return 'person not found';
        }
        $key = 'author_link:work:' . $work->id;
        if (PersonReview::where('type', 'author_link')->where('key', $key)->exists()) {
            return 'already queued';
        }
        if ($apply) {
            PersonReview::create(['type' => 'author_link', 'key' => $key, 'person_ids' => [$person->id], 'status' => 'open',
                'reason' => mb_substr($c['evidence'] ?? '', 0, 500) ?: null,
                'suggestion' => ['work_id' => $work->id, 'translit' => $c['translit'] ?? null]]);
        }

        return $apply ? 'queued' : 'would queue';
    }
}
