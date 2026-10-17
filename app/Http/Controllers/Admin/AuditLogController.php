<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\TextNormalizer;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\ScholarlyAnnotation;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $action = $request->query('action');
        $type = $request->query('type');

        $query = AuditLog::with('user');

        if ($action) {
            $query->where('action', $action);
        }

        if ($type) {
            $query->where('auditable_type', 'like', "%{$type}%");
        }

        $logs = $query->latest('id')->paginate(25)->withQueryString();

        return view('admin.audit_logs.index', compact('logs', 'action', 'type'));
    }

    public function rollback(AuditLog $log, Request $request)
    {
        if (! auth()->user()->canAccessAdmin()) {
            abort(403, 'دسترسی مجاز نمی‌باشد.');
        }

        if (empty($log->old_values)) {
            return back()->with('error', 'اطلاعات وضعیت پیشین برای این رخداد موجود نیست و امکان بازگردانی وجود ندارد.');
        }

        try {
            DB::transaction(function () use ($log, $request) {
                if ($log->action === 'merge') {
                    $this->rollbackMerge($log, $request);
                } elseif ($log->action === 'verify') {
                    $this->rollbackVerify($log, $request);
                } elseif ($log->action === 'update') {
                    $this->rollbackUpdate($log, $request);
                } else {
                    throw new \Exception("عملیات [{$log->action}] قابلیت بازگردانی خودکار ندارد.");
                }
            });

            return back()->with('success', "تغییرات رخداد #{$log->id} با موفقیت به حالت قبل بازگردانده (Rollback) شد.");
        } catch (\Throwable $e) {
            return back()->with('error', "خطا در فرآیند بازگردانی: " . $e->getMessage());
        }
    }

    protected function rollbackMerge(AuditLog $log, Request $request): void
    {
        $old = $log->old_values;

        // 1. Person Merge Rollback
        if (str_contains($log->auditable_type, 'Person')) {
            $target = Person::find($log->auditable_id);
            if (! $target) {
                throw new \Exception("شخص مقصد با شناسه #{$log->auditable_id} در پایگاه داده یافت نشد.");
            }

            $sourceSnapshot = $old['source_snapshot'] ?? null;
            $sourceId = $old['source_id'] ?? ($sourceSnapshot['id'] ?? null);

            if (! $sourceSnapshot || ! $sourceId) {
                throw new \Exception("اسنپ‌شات اطلاعات شخص مبدأ در این لاگ ممیزی ثبت نشده است و امکان بازگردانی خودکار وجود ندارد.");
            }

            if (Person::find($sourceId)) {
                throw new \Exception("شخص با شناسه #{$sourceId} در حال حاضر در پایگاه داده موجود است.");
            }

            // Clean snapshot for database insert
            foreach ($sourceSnapshot as $k => $v) {
                if (is_array($v)) {
                    $sourceSnapshot[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }

            // Restore source person with exact original ID
            DB::table('people')->insert($sourceSnapshot);

            $sourceName = $sourceSnapshot['name'] ?? 'شخص بازگردانی‌شده';
            $transferredWorkIds = (array) ($old['transferred_work_ids'] ?? []);
            $transferredManuscriptIds = (array) ($old['transferred_manuscript_ids'] ?? []);
            DB::table('manuscript_people')->whereIn('id', (array) ($old['transferred_role_ids'] ?? []))->update(['person_id' => $sourceId]);

            // Logs written before the pivots existed list only primary links
            $primaryWorkIds = (array) ($old['primary_work_ids'] ?? $transferredWorkIds);
            $primaryManuscriptIds = (array) ($old['primary_manuscript_ids'] ?? $transferredManuscriptIds);
            $collapsedWorkIds = (array) ($old['collapsed_work_ids'] ?? []);
            $collapsedManuscriptIds = (array) ($old['collapsed_manuscript_ids'] ?? []);

            // Revert works
            DB::table('work_author')->where('person_id', $target->id)
                ->whereIn('work_id', array_diff($transferredWorkIds, $collapsedWorkIds))
                ->update(['person_id' => $sourceId]);
            foreach ($collapsedWorkIds as $workId) {
                $position = (int) DB::table('work_author')->where('work_id', $workId)->max('position') + 1;
                DB::table('work_author')->insertOrIgnore(['work_id' => $workId, 'person_id' => $sourceId, 'position' => $position]);
            }
            if (! empty($primaryWorkIds)) {
                Work::whereIn('id', $primaryWorkIds)->where('author_id', $target->id)->update(['author_id' => $sourceId]);
            }
            Work::whereIn('id', $transferredWorkIds)->where('has_other_authors', false)
                ->whereRaw('(SELECT COUNT(*) FROM work_author wa WHERE wa.work_id = works.id) = 1')
                ->update(['author_name' => $sourceName]);

            // Revert manuscripts
            DB::table('manuscript_scribe')->where('person_id', $target->id)
                ->whereIn('manuscript_id', array_diff($transferredManuscriptIds, $collapsedManuscriptIds))
                ->update(['person_id' => $sourceId]);
            foreach ($collapsedManuscriptIds as $manuscriptId) {
                $position = (int) DB::table('manuscript_scribe')->where('manuscript_id', $manuscriptId)->max('position') + 1;
                DB::table('manuscript_scribe')->insertOrIgnore(['manuscript_id' => $manuscriptId, 'person_id' => $sourceId, 'position' => $position]);
            }
            if (! empty($primaryManuscriptIds)) {
                Manuscript::whereIn('id', $primaryManuscriptIds)->where('scribe_id', $target->id)->update(['scribe_id' => $sourceId]);
            }
            Manuscript::whereIn('id', $transferredManuscriptIds)->where('has_other_scribes', false)
                ->whereRaw('(SELECT COUNT(*) FROM manuscript_scribe ms WHERE ms.manuscript_id = manuscripts.id) = 1')
                ->update(['scribe_name' => $sourceName]);

            Work::normalizeContributorPositions($transferredWorkIds);
            Manuscript::normalizeContributorPositions($transferredManuscriptIds);

            // Recount works & manuscripts for target, and drop the aliases this merge added
            $targetWorks = $target->authoredWorks()->count();
            $targetManuscripts = $target->scribedManuscripts()->count();
            $addedAliases = $this->aliasesToDropOnRollback($log);
            $target->update([
                'works_count' => $targetWorks,
                'manuscripts_count' => $targetManuscripts,
                'is_author' => $targetWorks > 0,
                'is_scribe' => $targetManuscripts > 0,
                'aliases' => array_values(array_diff((array) ($target->aliases ?? []), $addedAliases)),
            ]);

            // Recount works & manuscripts for restored source
            $sourceWorks = DB::table('work_author')->where('person_id', $sourceId)->count();
            $sourceManuscripts = DB::table('manuscript_scribe')->where('person_id', $sourceId)->count();
            Person::where('id', $sourceId)->update([
                'works_count' => $sourceWorks,
                'manuscripts_count' => $sourceManuscripts,
                'is_author' => $sourceWorks > 0,
                'is_scribe' => $sourceManuscripts > 0,
            ]);

            // Sync Meilisearch
            Work::whereIn('id', $transferredWorkIds)->searchable();
            Manuscript::whereIn('id', $transferredManuscriptIds)->searchable();
            if (method_exists($target, 'searchable')) {
                $target->searchable();
            }
            $restoredPerson = Person::find($sourceId);
            if ($restoredPerson && method_exists($restoredPerson, 'searchable')) {
                $restoredPerson->searchable();
            }

            // Remove entity redirect
            EntityRedirect::removeRedirect('people', (int) $sourceId);

            // A merge approved from a suggestion reopens it
            if ($suggestionId = $log->new_values['suggestion_id'] ?? null) {
                FieldSuggestion::whereKey($suggestionId)->update(['status' => 'pending', 'resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            }

            // Audit log of rollback
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Person::class,
                'auditable_id' => $target->id,
                'action' => 'rollback_merge',
                'old_values' => ['rolled_back_log_id' => $log->id, 'target_id' => $target->id],
                'new_values' => ['restored_person_id' => $sourceId, 'restored_person_name' => $sourceName],
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        // 2. Subject Merge Rollback
        if (str_contains($log->auditable_type, 'Subject')) {
            $target = Subject::find($log->auditable_id);
            if (! $target) {
                throw new \Exception("موضوع مقصد با شناسه #{$log->auditable_id} در پایگاه داده یافت نشد.");
            }

            $sourceSnapshot = $old['source_snapshot'] ?? null;
            $sourceId = $old['source_id'] ?? ($sourceSnapshot['id'] ?? null);

            if (! $sourceSnapshot || ! $sourceId) {
                throw new \Exception("اسنپ‌شات اطلاعات موضوع مبدأ در این لاگ ممیزی ثبت نشده است.");
            }

            if (Subject::find($sourceId)) {
                throw new \Exception("موضوع با شناسه #{$sourceId} در حال حاضر در پایگاه داده موجود است.");
            }

            // Restore source subject
            DB::table('subjects')->insert($sourceSnapshot);

            $transferredWorkIds = (array) ($old['transferred_work_ids'] ?? []);
            $transferredChildrenIds = (array) ($old['transferred_children_ids'] ?? []);

            // Revert pivot works
            if (! empty($transferredWorkIds)) {
                DB::table('subject_work')
                    ->where('subject_id', $target->id)
                    ->whereIn('work_id', $transferredWorkIds)
                    ->delete();

                $inserts = array_map(fn ($wid) => [
                    'subject_id' => $sourceId,
                    'work_id' => $wid,
                ], $transferredWorkIds);

                DB::table('subject_work')->insert($inserts);
            }

            // Revert children
            if (! empty($transferredChildrenIds)) {
                Subject::whereIn('id', $transferredChildrenIds)
                    ->where('parent_id', $target->id)
                    ->update(['parent_id' => $sourceId]);
            }

            // Recount works
            $target->update(['works_count' => DB::table('subject_work')->where('subject_id', $target->id)->count()]);
            Subject::where('id', $sourceId)->update(['works_count' => DB::table('subject_work')->where('subject_id', $sourceId)->count()]);

            // Remove entity redirect
            EntityRedirect::removeRedirect('subjects', (int) $sourceId);

            // Audit log of rollback
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Subject::class,
                'auditable_id' => $target->id,
                'action' => 'rollback_merge',
                'old_values' => ['rolled_back_log_id' => $log->id, 'target_id' => $target->id],
                'new_values' => ['restored_subject_id' => $sourceId, 'restored_subject_name' => $sourceSnapshot['name'] ?? ''],
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        // 3. Library merge approved from a suggestion (see LibraryMerger)
        if ($log->auditable_type === \App\Models\Library::class) {
            $target = \App\Models\Library::find($log->auditable_id)
                ?? throw new \Exception("کتابخانه‌ی مقصد با شناسه #{$log->auditable_id} یافت نشد.");
            $restored = app(\App\Suggestions\LibraryMerger::class)->undoMerge($target, $old);

            if ($suggestionId = $log->new_values['suggestion_id'] ?? null) {
                FieldSuggestion::whereKey($suggestionId)->update(['status' => 'pending', 'resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            }
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => \App\Models\Library::class,
                'auditable_id' => $target->id,
                'action' => 'rollback_merge',
                'old_values' => ['rolled_back_log_id' => $log->id, 'target_id' => $target->id],
                'new_values' => ['restored_library_id' => $restored->id, 'restored_library_name' => $restored->name],
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        // 4. Manuscript or work merge approved from the suggestions desk (see RecordMerger)
        if (in_array($log->auditable_type, [Manuscript::class, Work::class], true)) {
            $target = $log->auditable_type::find($log->auditable_id)
                ?? throw new \Exception("رکورد ماندنی با شناسه #{$log->auditable_id} یافت نشد.");
            $merger = app(\App\Suggestions\RecordMerger::class);
            $restored = $target instanceof Work ? $merger->undoWorkMerge($target, $old) : $merger->undoManuscriptMerge($target, $old);

            if ($suggestionId = $log->new_values['suggestion_id'] ?? null) {
                FieldSuggestion::whereKey($suggestionId)->update(['status' => 'pending', 'resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            }
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $target->id,
                'action' => 'rollback_merge',
                'old_values' => ['rolled_back_log_id' => $log->id, 'target_id' => $target->id],
                'new_values' => ['restored_id' => $restored->id],
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        throw new \Exception("مدل مورد نظر برای بازگردانی ادغام شناسایی نشد.");
    }

    /**
     * Aliases a person merge added, minus any that also belong to a source of another
     * merge into the same target that is still in effect.
     */
    protected function aliasesToDropOnRollback(AuditLog $log): array
    {
        $added = (array) ($log->new_values['added_aliases'] ?? []);
        if (empty($added)) {
            return [];
        }

        $rolledBackIds = AuditLog::where('auditable_type', Person::class)
            ->where('auditable_id', $log->auditable_id)
            ->where('action', 'rollback_merge')
            ->get()
            ->map(fn($l) => $l->old_values['rolled_back_log_id'] ?? null)
            ->filter()
            ->all();

        $stillMergedNames = AuditLog::where('auditable_type', Person::class)
            ->where('auditable_id', $log->auditable_id)
            ->where('action', 'merge')
            ->where('id', '!=', $log->id)
            ->whereNotIn('id', $rolledBackIds)
            ->get()
            ->flatMap(function ($l) {
                $snapshot = $l->old_values['source_snapshot'] ?? [];
                $aliases = $snapshot['aliases'] ?? [];
                if (is_string($aliases)) {
                    $aliases = json_decode($aliases, true) ?: [];
                }

                return array_merge([$snapshot['name'] ?? ''], (array) $aliases);
            })
            ->map(fn($n) => TextNormalizer::normalize($n))
            ->all();

        return array_values(array_filter(
            $added,
            fn($alias) => ! in_array(TextNormalizer::normalize($alias), $stillMergedNames, true)
        ));
    }

    protected function rollbackVerify(AuditLog $log, Request $request): void
    {
        $modelClass = $log->auditable_type;
        $target = $modelClass::find($log->auditable_id);

        if (! $target) {
            throw new \Exception("رکورد هدف جهت بازگردانی فیلد یافت نشد.");
        }

        $old = $log->old_values ?? [];
        // An added scribe or author is one pivot row; "contributors" only records the list before
        unset($old['contributors'], $old['people_roles'], $old['relations']);
        if ($relationRow = $log->new_values['added_record_relation'] ?? null) {
            DB::table($relationRow['table'])->where('id', $relationRow['id'])->delete();
        }
        if ($roleId = $log->new_values['added_person_role'] ?? null) {
            \App\Models\ManuscriptPerson::whereKey($roleId)->delete();
        }
        if ($added = $log->new_values['added_contributor'] ?? null) {
            DB::table($added['table'])->where('id', $added['id'])->delete();
            Person::find($added['person_id'])?->recountContributions();
        }
        $previousAuthor = $target->author_id ?? null;
        $previousScribe = $target->scribe_id ?? null;
        $previousWork = $target instanceof Manuscript ? $target->work_id : null;
        $previousLibrary = $target instanceof Manuscript ? $target->library_id : null;
        if ($old) {
            $target->forceFill($old)->save();
        }
        // A manuscript moved between works: both works' counts change back
        if ($previousWork && array_key_exists('work_id', $old)) {
            foreach (array_unique([$previousWork, $old['work_id']]) as $workId) {
                Work::find($workId)?->update(['manuscripts_count' => Manuscript::where('work_id', $workId)->count()]);
            }
        }

        // A manuscript moved to another library: both libraries' counts change back, and a library
        // the approval created goes if nothing else uses it
        if (array_key_exists('library_id', $old)) {
            \App\Suggestions\ManuscriptLocation::recountLibraries([$previousLibrary, $old['library_id']]);
        }
        // A renamed library: the copies of its name on manuscripts follow
        if ($target instanceof \App\Models\Library && array_key_exists('name', $old)) {
            $target->syncManuscriptCopies();
        }
        if ($createdLibrary = \App\Models\Library::find($log->new_values['created_library_id'] ?? 0)) {
            if (Manuscript::where('library_id', $createdLibrary->id)->doesntExist() && $createdLibrary->catalogVolumes()->doesntExist()) {
                $createdLibrary->delete();
            }
        }

        if (method_exists($target, 'searchable')) {
            $target->searchable();
        }

        // Logs written since the per-field desk carry their suggestion id; older ones fall back to the latest match
        // A reviewer's direct edit has no suggestion behind it
        $directEdit = ! empty($log->new_values['direct_edit']);
        $suggestionId = $log->new_values['suggestion_id'] ?? null;
        $suggestion = match (true) {
            $directEdit => null,
            (bool) $suggestionId => FieldSuggestion::find($suggestionId),
            default => FieldSuggestion::where('suggestable_type', $log->auditable_type)
                ->where('suggestable_id', $log->auditable_id)
                ->where('status', 'approved')
                ->latest('id')
                ->first(),
        };

        if (! $directEdit) {
            ScholarlyAnnotation::where('annotatable_type', $log->auditable_type)
                ->where('annotatable_id', $log->auditable_id)
                ->when($suggestionId, fn ($q) => $q->where('suggestion_id', $suggestionId))
                ->latest('id')
                ->first()
                ?->delete();
        }

        // Readings the approval changed: the adopted reading it demoted, or the value a typo fix corrected
        if ($demoted = $log->new_values['demoted'] ?? null) {
            FieldSuggestion::whereKey($demoted['id'])->update(['standing' => $demoted['standing'], 'superseded_by' => null]);
        }
        if ($edited = $log->new_values['edited_reading'] ?? null) {
            FieldSuggestion::whereKey($edited['id'])->update(['applied_value' => $edited['applied_value']]);
        }

        // An unlinked catalog name that a newly linked primary person replaced
        if ($row = $log->new_values['removed_name_row'] ?? null) {
            $table = $row['table'];
            unset($row['table']);
            DB::table($table)->insertOrIgnore($row);
        }

        if (array_key_exists('author_id', $old)) {
            foreach (array_unique(array_filter([$previousAuthor, $old['author_id']])) as $personId) {
                Person::find($personId)?->recountContributions();
            }
        }

        // A scribe link changed: recount both people, and drop a person the approval created if nothing else uses it
        if (array_key_exists('scribe_id', $old)) {
            foreach (array_unique(array_filter([$previousScribe, $old['scribe_id']])) as $personId) {
                Person::find($personId)?->recountContributions();
            }
            $resolution = $suggestion?->resolution ?? [];
            if (($resolution['person_action'] ?? null) === 'create' && ($created = Person::find($resolution['person_id']))) {
                if ($created->scribedManuscripts()->doesntExist() && $created->authoredWorks()->doesntExist()) {
                    $created->delete();
                }
            }
        }

        if (! empty($log->new_values['promoted'])) {
            $suggestion?->update(['standing' => 'alternative', 'superseded_by' => null]);
            $suggestion = null;
        }
        $suggestion?->update(['status' => 'pending', 'standing' => null, 'applied_value' => null, 'superseded_by' => null, 'resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'action' => 'rollback_verify',
            'old_values' => $log->new_values,
            'new_values' => $log->old_values,
            'ip_address' => $request->ip(),
        ]);
    }

    protected function rollbackUpdate(AuditLog $log, Request $request): void
    {
        $modelClass = $log->auditable_type;
        $target = $modelClass::find($log->auditable_id);

        if (! $target) {
            throw new \Exception("رکورد هدف جهت بازگردانی یافت نشد.");
        }

        $target->forceFill($log->old_values)->save();
        if ($target instanceof \App\Models\Library) {
            $target->syncManuscriptCopies();
        }

        if (method_exists($target, 'searchable')) {
            $target->searchable();
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'action' => 'rollback_update',
            'old_values' => $log->new_values,
            'new_values' => $log->old_values,
            'ip_address' => $request->ip(),
        ]);
    }
}
