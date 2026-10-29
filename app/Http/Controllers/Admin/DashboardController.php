<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Role;
use App\Models\SourceEntry;
use App\Models\Subject;
use App\Models\User;
use App\Models\Work;

/**
 * The panel's front page (backlog item 65): the work waiting for review first, each kind with its count and
 * its oldest item; then the latest pending suggestions, the database at a glance and the recent changes.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $queue = [];

        if ($user->can('suggestions.review')) {
            $pending = FieldSuggestion::inQueue()
                ->selectRaw("CASE WHEN kind = 'general' THEN 'field' ELSE kind END AS bucket, count(*) AS c, min(created_at) AS oldest")
                ->groupBy('bucket')->get()->keyBy('bucket');
            foreach ([
                'field' => ['اصلاح فیلد', 'کلی و فیلدی'],
                'relation' => ['پیشنهاد ساختاری', 'ادغام، انتقال و رابطه'],
                'note' => ['یادداشت پژوهشی', 'پیش از انتشار'],
                'feedback' => ['گزارش اشکال سایت', 'از فرم گزارش اشکال'],
            ] as $kind => [$label, $hint]) {
                $row = $pending[$kind] ?? null;
                $queue[] = [
                    'label' => $label,
                    'count' => (int) ($row->c ?? 0),
                    'hint' => $row ? 'قدیمی‌ترین: ' . \App\Support\Jalali::ago(\Illuminate\Support\Carbon::parse($row->oldest)) : $hint,
                    'url' => route('admin.suggestions.index', ['status' => 'pending', 'kind' => $kind]),
                ];
            }
        }
        if ($user->can('catalog_refs.review')) {
            $queue[] = [
                'label' => 'نشانی خودکار',
                'count' => SourceEntry::awaitingReview()->count(),
                'hint' => 'تطبیق‌های بازبینی‌نشده',
                'url' => route('admin.catalog-references.index'),
            ];
        }

        $pendingSuggestions = ! $user->can('suggestions.review') ? collect() : FieldSuggestion::with(['user', 'suggestable'])
            ->inQueue()
            ->latest('id')
            ->take(5)
            ->get();

        $counts = [
            'نسخه' => Manuscript::count(),
            'اثر' => Work::count(),
            'شخص' => Person::count(),
            'کتابخانه' => Library::count(),
            'فهرست‌نگار' => Cataloger::count(),
            'موضوع' => Subject::count(),
            'کاربر' => User::count(),
            'اصلاح پذیرفته در ۳۰ روز' => FieldSuggestion::where('status', 'approved')->where('reviewed_at', '>=', now()->subDays(30))->count(),
        ];

        // The recent changes follow the audit log page: hidden without audit.view, and without account changes for those who do not manage users
        $recentLogs = ! $user->can('audit.view') ? collect() : AuditLog::with('user')
            ->when(! $user->can('users.manage'), fn ($q) => $q->whereNotIn('auditable_type', [User::class, Role::class]))
            ->latest('id')
            ->take(6)
            ->get();

        return view('admin.dashboard', compact('queue', 'pendingSuggestions', 'counts', 'recentLogs'));
    }
}
