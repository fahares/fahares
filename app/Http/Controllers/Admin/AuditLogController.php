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

            // Revert works
            if (! empty($transferredWorkIds)) {
                Work::whereIn('id', $transferredWorkIds)
                    ->where('author_id', $target->id)
                    ->update([
                        'author_id' => $sourceId,
                        'author_name' => $sourceName,
                    ]);
            }

            // Revert manuscripts
            if (! empty($transferredManuscriptIds)) {
                Manuscript::whereIn('id', $transferredManuscriptIds)
                    ->where('scribe_id', $target->id)
                    ->update([
                        'scribe_id' => $sourceId,
                        'scribe_name' => $sourceName,
                    ]);
            }

            // Recount works & manuscripts for target, and drop the aliases this merge added
            $targetWorks = Work::where('author_id', $target->id)->count();
            $targetManuscripts = Manuscript::where('scribe_id', $target->id)->count();
            $addedAliases = $this->aliasesToDropOnRollback($log);
            $target->update([
                'works_count' => $targetWorks,
                'manuscripts_count' => $targetManuscripts,
                'is_author' => $targetWorks > 0,
                'is_scribe' => $targetManuscripts > 0,
                'aliases' => array_values(array_diff((array) ($target->aliases ?? []), $addedAliases)),
            ]);

            // Recount works & manuscripts for restored source
            $sourceWorks = Work::where('author_id', $sourceId)->count();
            $sourceManuscripts = Manuscript::where('scribe_id', $sourceId)->count();
            Person::where('id', $sourceId)->update([
                'works_count' => $sourceWorks,
                'manuscripts_count' => $sourceManuscripts,
                'is_author' => $sourceWorks > 0,
                'is_scribe' => $sourceManuscripts > 0,
            ]);

            // Sync Meilisearch
            if (method_exists($target, 'searchable')) {
                $target->searchable();
            }
            $restoredPerson = Person::find($sourceId);
            if ($restoredPerson && method_exists($restoredPerson, 'searchable')) {
                $restoredPerson->searchable();
            }

            // Remove entity redirect
            EntityRedirect::removeRedirect('people', (int) $sourceId);

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

        $target->forceFill($log->old_values)->save();

        if (method_exists($target, 'searchable')) {
            $target->searchable();
        }

        // Deactivate/remove scholarly annotation if created
        ScholarlyAnnotation::where('annotatable_type', $log->auditable_type)
            ->where('annotatable_id', $log->auditable_id)
            ->latest('id')
            ->first()
            ?->delete();

        // Revert field suggestion to pending if exists
        FieldSuggestion::where('suggestable_type', $log->auditable_type)
            ->where('suggestable_id', $log->auditable_id)
            ->where('status', 'approved')
            ->latest('id')
            ->first()
            ?->update(['status' => 'pending']);

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
