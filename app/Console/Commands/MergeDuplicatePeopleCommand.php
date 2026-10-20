<?php

namespace App\Console\Commands;

use App\Models\EntityRedirect;
use App\Models\Person;
use App\Services\PersonMerger;
use Illuminate\Console\Command;

/**
 * Merges the duplicate-person groups that fahares:find-duplicate-people reported: every group of
 * the auto tier («likely»), and any other group a reviewer decided to merge (decisions file,
 * keyed by the group key). Merges go through PersonMerger with the printed scribe and author
 * names left as the catalog gives them; each one is logged and can be rolled back from the panel.
 * Safe to re-run: merged records are gone.
 */
class MergeDuplicatePeopleCommand extends Command
{
    protected $signature = 'fahares:merge-duplicate-people
                            {--report= : Report of fahares:find-duplicate-people (default: storage/app/duplicate_people.json)}
                            {--decisions= : Reviewed decisions for non-auto groups (default: database/data/duplicate_people_decisions.json)}
                            {--tier=likely : Tier merged without review}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Merge person records reported as the same person written differently';

    public function handle(): int
    {
        $reportPath = $this->option('report') ?: storage_path('app/duplicate_people.json');
        if (! is_file($reportPath)) {
            $this->error("Report not found: {$reportPath}");
            return self::FAILURE;
        }
        $groups = json_decode(file_get_contents($reportPath), true)['groups'] ?? [];

        $decisionsPath = $this->option('decisions') ?: database_path('data/duplicate_people_decisions.json');
        $decisions = is_file($decisionsPath)
            ? collect(json_decode(file_get_contents($decisionsPath), true)['groups'] ?? [])->keyBy('key')->all()
            : [];

        $dry = (bool) $this->option('dry-run');
        $merger = app(PersonMerger::class);
        $stats = ['groups' => 0, 'merges' => 0, 'skipped' => 0];

        foreach ($groups as $group) {
            $decision = $decisions[$group['key']] ?? null;
            if ($group['tier'] === $this->option('tier')) {
                $ids = array_column($group['members'], 'id');
                $targetId = $group['target_id'];
            } elseif (($decision['decision'] ?? null) === 'merge') {
                $ids = $decision['merge_ids'];
                $targetId = $decision['target_id'];
            } else {
                continue;
            }

            $target = $this->current($targetId, $group['key']);
            if (! $target) {
                $this->warn("group «{$group['key']}»: record #{$targetId} missing or renamed; skipped.");
                $stats['skipped']++;
                continue;
            }

            $merged = 0;
            foreach (array_diff($ids, [$targetId]) as $sourceId) {
                $source = $this->current($sourceId, $group['key']);
                if (! $source || $source->id === $target->id) {
                    continue;
                }
                if (! $dry) {
                    $merger->merge($source, $target, null, null, ['reason' => 'fahares:merge-duplicate-people', 'group' => $group['key']], keepPrintedNames: true);
                }
                $merged++;
            }

            if ($merged) {
                $this->line(sprintf('#%d «%s» ← %d record%s', $target->id, $target->name, $merged, $merged > 1 ? 's' : ''));
                $stats['groups']++;
                $stats['merges'] += $merged;
            }
        }

        $this->info(($dry ? '[dry run] ' : '') . sprintf('Merged %d records into %d people; %d groups skipped.', $stats['merges'], $stats['groups'], $stats['skipped']));

        return self::SUCCESS;
    }

    /**
     * The record as it is now, following merge redirects, and only if its name still belongs to the group.
     */
    protected function current(int $id, string $key): ?Person
    {
        $person = Person::find($id) ?? Person::find(EntityRedirect::resolveTargetId('people', $id));

        return $person && FindDuplicatePeopleCommand::key($person->name) === $key ? $person : null;
    }
}
