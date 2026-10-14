<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Merges a duplicate person into the record that is kept: moves every author and scribe link,
 * keeps the source name as a parallel name, logs a rollback-ready audit entry and leaves a 301
 * redirect. Used by the admin merge tool and by data-repair commands.
 */
class PersonMerger
{
    public function merge(Person $source, Person $target, ?int $userId = null, ?string $ip = null, array $extraNewValues = []): AuditLog
    {
        return DB::transaction(function () use ($source, $target, $userId, $ip, $extraNewValues) {
            // Capture snapshot of source person before changes
            $sourceSnapshot = $source->getAttributes();
            foreach ($sourceSnapshot as $k => $v) {
                if (is_array($v)) {
                    $sourceSnapshot[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }

            // Identify exact works and manuscripts to transfer (every link, primary or not)
            $transferredWorkIds = DB::table('work_author')->where('person_id', $source->id)->pluck('work_id')->all();
            $transferredManuscriptIds = DB::table('manuscript_scribe')->where('person_id', $source->id)->pluck('manuscript_id')->all();
            // Records already linked to the target too; their source row is dropped instead of moved
            $collapsedWorkIds = DB::table('work_author')->where('person_id', $target->id)->whereIn('work_id', $transferredWorkIds)->pluck('work_id')->all();
            $collapsedManuscriptIds = DB::table('manuscript_scribe')->where('person_id', $target->id)->whereIn('manuscript_id', $transferredManuscriptIds)->pluck('manuscript_id')->all();

            $primaryWorkIds = Work::where('author_id', $source->id)->pluck('id')->all();
            $primaryManuscriptIds = Manuscript::where('scribe_id', $source->id)->pluck('id')->all();

            // 1. Transfer Works (as author); the printed name changes only on single-author works
            DB::table('work_author')->where('person_id', $source->id)->whereIn('work_id', $collapsedWorkIds)->delete();
            DB::table('work_author')->where('person_id', $source->id)->update(['person_id' => $target->id]);
            Work::where('author_id', $source->id)->update(['author_id' => $target->id]);
            Work::whereIn('id', $transferredWorkIds)->where('has_other_authors', false)
                ->whereRaw('(SELECT COUNT(*) FROM work_author wa WHERE wa.work_id = works.id) = 1')
                ->update(['author_name' => $target->name]);

            // 2. Transfer Manuscripts (as scribe); likewise for the scribe name
            // Other roles in manuscripts (owner, endower…) follow the person; a rollback moves them back
            $transferredRoleIds = DB::table('manuscript_people')->where('person_id', $source->id)->pluck('id')->all();
            DB::table('manuscript_people')->whereIn('id', $transferredRoleIds)->update(['person_id' => $target->id]);

            DB::table('manuscript_scribe')->where('person_id', $source->id)->whereIn('manuscript_id', $collapsedManuscriptIds)->delete();
            DB::table('manuscript_scribe')->where('person_id', $source->id)->update(['person_id' => $target->id]);
            Manuscript::where('scribe_id', $source->id)->update(['scribe_id' => $target->id]);
            Manuscript::whereIn('id', $transferredManuscriptIds)->where('has_other_scribes', false)
                ->whereRaw('(SELECT COUNT(*) FROM manuscript_scribe ms WHERE ms.manuscript_id = manuscripts.id) = 1')
                ->update(['scribe_name' => $target->name]);

            Work::normalizeContributorPositions($transferredWorkIds);
            Manuscript::normalizeContributorPositions($transferredManuscriptIds);

            // 3. Recount works and manuscripts for target
            $newWorksCount = $target->authoredWorks()->count();
            $newManuscriptsCount = $target->scribedManuscripts()->count();

            // 3.1 Keep source name and its aliases as parallel names of the target
            $addedAliases = $this->collectNewAliases($source, $target);

            $target->update([
                'works_count' => $newWorksCount,
                'manuscripts_count' => $newManuscriptsCount,
                'is_author' => $target->is_author || ($newWorksCount > 0),
                'is_scribe' => $target->is_scribe || ($newManuscriptsCount > 0),
                'aliases' => array_values(array_merge((array) ($target->aliases ?? []), $addedAliases)),
            ]);

            if (method_exists($target, 'searchable')) {
                $target->searchable();
            }
            // Their author or scribe name and aliases are part of the search index
            Work::whereIn('id', $transferredWorkIds)->searchable();
            Manuscript::whereIn('id', $transferredManuscriptIds)->searchable();

            // 4. Audit Log with full snapshot & exact transferred IDs
            $log = AuditLog::create([
                'user_id' => $userId,
                'auditable_type' => Person::class,
                'auditable_id' => $target->id,
                'action' => 'merge',
                'old_values' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_snapshot' => $sourceSnapshot,
                    'transferred_work_ids' => $transferredWorkIds,
                    'transferred_manuscript_ids' => $transferredManuscriptIds,
                    'primary_work_ids' => $primaryWorkIds,
                    'primary_manuscript_ids' => $primaryManuscriptIds,
                    'collapsed_work_ids' => $collapsedWorkIds,
                    'collapsed_manuscript_ids' => $collapsedManuscriptIds,
                    'transferred_role_ids' => $transferredRoleIds,
                    'works_transferred' => count($transferredWorkIds),
                    'manuscripts_transferred' => count($transferredManuscriptIds),
                ],
                'new_values' => [
                    'target_id' => $target->id,
                    'target_name' => $target->name,
                    'new_works_count' => $newWorksCount,
                    'new_manuscripts_count' => $newManuscriptsCount,
                    'added_aliases' => $addedAliases,
                ] + $extraNewValues,
                'ip_address' => $ip,
            ]);

            // 5. Record permanent redirect from source to target
            EntityRedirect::recordRedirect('people', $source->id, $target->id);

            // 6. Delete source person
            $source->delete();

            return $log;
        });
    }

    /**
     * Source name and aliases that the target does not already have (compared in normalized form).
     */
    protected function collectNewAliases(Person $source, Person $target): array
    {
        $known = collect([$target->name])
            ->concat((array) ($target->aliases ?? []))
            ->map(fn($n) => TextNormalizer::normalize($n))
            ->all();

        $added = [];
        foreach (array_merge([$source->name], (array) ($source->aliases ?? [])) as $name) {
            $name = trim((string) $name);
            $key = TextNormalizer::normalize($name);
            if ($key === '' || in_array($key, $known, true)) {
                continue;
            }
            $known[] = $key;
            $added[] = $name;
        }

        return $added;
    }
}
