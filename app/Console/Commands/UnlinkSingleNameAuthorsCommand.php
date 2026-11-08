<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the person link of works whose author Fankha names by one word only («احمد», «یحیی») and that the old
 * parser joined to one person with that name although they are by other people (backlog item 87, decision 1;
 * step 14.111). The judged list is in a decisions file, keyed by work slug and the linked person's name, since local
 * and production ids differ. The author's name stays on the work as text (`author_name`); the person keeps the works
 * that are his. A work whose author link a reviewer verified, or whose link has changed since, is skipped.
 * Each change is logged as `corpus_update` with the old link. Without --apply it only reports.
 */
class UnlinkSingleNameAuthorsCommand extends Command
{
    protected $signature = 'fahares:unlink-single-name-authors
        {--file=database/data/single_name_authors_step14_111.json : The judged list}
        {--apply : Write the changes}';

    protected $description = 'Removes the person link of works joined to a single-word author name they do not belong to';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $doc = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($doc['unlinks'] ?? null)) {
            $this->error("No unlinks in {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Work::class);
        $counts = ['unlinked' => 0, 'missing_work' => 0, 'other_author' => 0, 'protected' => 0, 'already' => 0];
        $people = [];
        $works = [];

        foreach ($doc['unlinks'] as $row) {
            $work = Work::where('slug', $row['work_slug'])->first(['id', 'slug', 'author_id', 'author_name']);
            if (! $work) {
                $counts['missing_work']++;
                $this->line("  missing work: {$row['work_slug']}");

                continue;
            }
            if (! $work->author_id) {
                $counts['already']++;

                continue;
            }
            $person = Person::find($work->author_id, ['id', 'name']);
            if (! $person || TextNormalizer::normalize($person->name) !== TextNormalizer::normalize($row['person_name'])) {
                $counts['other_author']++;
                $this->line("  author changed: {$row['work_slug']} → " . ($person?->name ?? '#' . $work->author_id));

                continue;
            }
            if ($guard->strip($work->id, ['author_id' => null]) === [] || $guard->protectsRows($work->id, 'people')) {
                $counts['protected']++;

                continue;
            }
            $counts['unlinked']++;
            $people[$person->id] = $person->id;
            $works[] = $work->id;
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($work, $person, $row, $doc) {
                $pivot = DB::table('work_people')->where('work_id', $work->id)->where('person_id', $person->id)
                    ->where('role', WorkPerson::AUTHOR)->get()->map(fn ($r) => (array) $r)->all();
                DB::table('work_people')->whereIn('id', array_column($pivot, 'id'))->delete();
                // the name stays as text; a work still naming another author keeps it
                Work::whereKey($work->id)->update(['author_id' => null, 'author_name' => $work->author_name ?: $person->name, 'updated_at' => now()]);
                AuditLog::create([
                    'user_id' => null,
                    'auditable_type' => Work::class,
                    'auditable_id' => $work->id,
                    'action' => 'corpus_update',
                    'old_values' => ['author_id' => $person->id, 'author_name' => $work->author_name, 'work_people' => $pivot],
                    'new_values' => ['author_id' => null, 'corpus_fix' => 'single_name_author', 'step' => $doc['step'] ?? null,
                        'judged_by' => $row['judged_by'] ?? null, 'reason' => $row['reason'] ?? null],
                ]);
            });
        }

        $this->table(array_keys($counts), [array_values($counts)]);
        if (! $apply) {
            $this->info('Dry run; pass --apply to write.');

            return self::SUCCESS;
        }
        foreach (Person::whereIn('id', $people)->get() as $person) {
            $person->recountContributions();
            $person->searchable();
        }
        Work::whereIn('id', $works)->get()->searchable();
        // a person left with nothing is only reported: a redirect needs a record to point at
        $empty = Person::whereIn('id', $people)->where('works_count', 0)->where('manuscripts_count', 0)
            ->whereDoesntHave('workRoles')->whereDoesntHave('manuscriptRoles')->pluck('name', 'id');
        foreach ($empty as $id => $name) {
            $this->warn("  person #{$id} «{$name}» has no record left");
        }
        $this->info("Done: {$counts['unlinked']} works unlinked from " . count($people) . ' people; ' . $empty->count() . ' left empty.');

        return self::SUCCESS;
    }
}
