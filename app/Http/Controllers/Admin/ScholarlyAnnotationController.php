<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ScholarlyAnnotation;
use Illuminate\Http\Request;

class ScholarlyAnnotationController extends Controller
{
    public function index(Request $request)
    {
        $category = $request->query('category');
        $query = ScholarlyAnnotation::with(['user', 'suggestion']);

        if ($category) {
            $query->where('revision_category', $category);
        }

        $annotations = $query->latest('id')->paginate(20)->withQueryString();

        return view('admin.annotations.index', compact('annotations', 'category'));
    }

    public function togglePublic(ScholarlyAnnotation $annotation)
    {
        $annotation->is_public = ! $annotation->is_public;
        $annotation->save();

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => ScholarlyAnnotation::class,
            'auditable_id' => $annotation->id,
            'action' => 'update',
            'new_values' => ['is_public' => $annotation->is_public],
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'وضعیت نمایش عمومی پانویس علمی با موفقیت به‌روزرسانی شد.');
    }

    public function destroy(ScholarlyAnnotation $annotation)
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => ScholarlyAnnotation::class,
            'auditable_id' => $annotation->id,
            'action' => 'delete',
            'old_values' => $annotation->toArray(),
            'ip_address' => request()->ip(),
        ]);

        $annotation->delete();

        return back()->with('info', 'پانویس انتقادی با موفقیت حذف شد.');
    }
}
