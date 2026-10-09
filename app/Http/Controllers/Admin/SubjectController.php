<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('q');
        $query = Subject::with(['parent', 'children'])->withCount('works');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $subjects = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('admin.subjects.index', compact('subjects', 'search'));
    }

    public function tree()
    {
        $rootSubjects = Subject::whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->withCount('works')->orderBy('name')])
            ->withCount('works')
            ->orderBy('name')
            ->get();

        return view('admin.subjects.tree', compact('rootSubjects'));
    }

    public function create()
    {
        $parents = Subject::whereNull('parent_id')->orderBy('name')->get();
        return view('admin.subjects.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:subjects,name',
            'parent_id' => 'nullable|exists:subjects,id',
            'description' => 'nullable|string',
        ]);

        $subject = Subject::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name'], '-', null) ?: (string) time(),
            'parent_id' => $validated['parent_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'works_count' => 0,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Subject::class,
            'auditable_id' => $subject->id,
            'action' => 'create',
            'new_values' => $subject->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.subjects.index')
            ->with('success', "موضوع [{$subject->name}] با موفقیت افزوده شد.");
    }

    public function edit(Subject $subject)
    {
        $parents = Subject::whereNull('parent_id')
            ->where('id', '!=', $subject->id)
            ->orderBy('name')
            ->get();

        return view('admin.subjects.edit', compact('subject', 'parents'));
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate([
            'name' => "required|string|max:255|unique:subjects,name,{$subject->id}",
            'parent_id' => 'nullable|exists:subjects,id',
            'description' => 'nullable|string',
        ]);

        $oldValues = $subject->toArray();

        $subject->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name'], '-', null) ?: $subject->slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Subject::class,
            'auditable_id' => $subject->id,
            'action' => 'update',
            'old_values' => $oldValues,
            'new_values' => $subject->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.subjects.index')
            ->with('success', "موضوع [{$subject->name}] با موفقیت به‌روزرسانی شد.");
    }

    public function merge(Request $request)
    {
        if ($request->isMethod('GET')) {
            $subjects = Subject::orderBy('name')->get();
            return view('admin.subjects.merge', compact('subjects'));
        }

        $validated = $request->validate([
            'source_subject_id' => 'required|exists:subjects,id|different:target_subject_id',
            'target_subject_id' => 'required|exists:subjects,id',
        ], [
            'source_subject_id.required' => 'انتخاب موضوع مبدأ (موضوع حذف‌شونده) الزامی است.',
            'target_subject_id.required' => 'انتخاب موضوع مقصد (موضوع حفظ‌شونده) الزامی است.',
            'source_subject_id.different' => 'موضوع مبدأ و مقصد نمی‌توانند یکسان باشند.',
        ]);

        $source = Subject::findOrFail($validated['source_subject_id']);
        $target = Subject::findOrFail($validated['target_subject_id']);

        DB::transaction(function () use ($source, $target, $request) {
            // Snapshot of source subject
            $sourceSnapshot = $source->getAttributes();
            foreach ($sourceSnapshot as $k => $v) {
                if (is_array($v)) {
                    $sourceSnapshot[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }

            // 1. Reassign pivot records subject_work without duplicate key conflict
            $existingWorkIdsInTarget = DB::table('subject_work')
                ->where('subject_id', $target->id)
                ->pluck('work_id')
                ->toArray();

            $transferredWorkIds = DB::table('subject_work')
                ->where('subject_id', $source->id)
                ->whereNotIn('work_id', $existingWorkIdsInTarget)
                ->pluck('work_id')
                ->toArray();

            $transferredChildrenIds = Subject::where('parent_id', $source->id)->pluck('id')->toArray();

            // Transfer non-duplicate works
            DB::table('subject_work')
                ->where('subject_id', $source->id)
                ->whereIn('work_id', $transferredWorkIds)
                ->update(['subject_id' => $target->id]);

            // Delete residual duplicates for source
            DB::table('subject_work')
                ->where('subject_id', $source->id)
                ->delete();

            // 2. Reassign children of source
            Subject::where('parent_id', $source->id)->update(['parent_id' => $target->id]);

            // 3. Recount works for target
            $targetWorksCount = DB::table('subject_work')->where('subject_id', $target->id)->count();
            $target->update(['works_count' => $targetWorksCount]);

            // 4. Audit Log with snapshot and IDs
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Subject::class,
                'auditable_id' => $target->id,
                'action' => 'merge',
                'old_values' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_snapshot' => $sourceSnapshot,
                    'transferred_work_ids' => $transferredWorkIds,
                    'transferred_children_ids' => $transferredChildrenIds,
                    'works_transferred' => count($transferredWorkIds),
                ],
                'new_values' => [
                    'target_id' => $target->id,
                    'new_works_count' => $targetWorksCount,
                ],
                'ip_address' => $request->ip(),
            ]);

            // 5. Delete source subject
            $source->delete();
        });

        return redirect()->route('admin.subjects.index')
            ->with('success', "موضوع [{$source->name}] با موفقیت در [{$target->name}] ادغام و تمامی آثار آن بازانتساب شد.");
    }

    public function recount()
    {
        $subjects = Subject::all();
        foreach ($subjects as $subject) {
            $count = DB::table('subject_work')->where('subject_id', $subject->id)->count();
            $subject->update(['works_count' => $count]);
        }

        return redirect()->route('admin.subjects.index')
            ->with('success', 'شمارش آثار برای تمامی شاخه‌های موضوعی با موفقیت به‌روزرسانی شد.');
    }
}
