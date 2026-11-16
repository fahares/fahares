<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DateConflict;
use App\Review\CollectiveReview;
use App\Support\DateEstimates\ConflictRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel's «تناقض‌های تاریخی» (step 14.167): the documented dates fahares:estimate-dates found to conflict, widest
 * gap first, each with the two records to open and mend. The page changes no date: a conflict goes when the nightly
 * run no longer finds it. «خطا نیست» dismisses one that is right as it stands (a later hand named as the scribe, a
 * copy of a dated original), and that is kept across runs. The researchers' answers in «تصحیح جمعی» (DateConflictQueue,
 * step 14.212) are counted beside each conflict, and the best-supported ones come first.
 */
class DateConflictController extends Controller
{
    public function index(Request $request)
    {
        $direct = $request->query('kind') !== 'indirect';
        $dismissed = $request->query('view') === 'dismissed';
        $rule = array_key_exists($request->query('rule'), DateConflict::RULE_LABELS) ? $request->query('rule') : null;
        $smallFirst = $request->query('sort') === 'small';

        $base = DateConflict::where('direct', $direct)->when($dismissed, fn ($q) => $q->whereNotNull('dismissed_at'), fn ($q) => $q->open());
        $ruleCounts = (clone $base)->selectRaw('rule, COUNT(*) n')->groupBy('rule')->pluck('n', 'rule');
        // the conflicts readers answered come first; ?conflict=<id> shows one, as a suggestion's page links it
        // and of those, the ones with the most agreeing weight in «تصحیح جمعی» (step 14.212)
        $conflicts = $base->when($rule, fn ($q) => $q->where('rule', $rule))->when($request->integer('conflict'), fn ($q, $id) => $q->whereKey($id))
            ->leftJoinSub(CollectiveReview::agreement('date'), 'agreement', 'agreement.review_item_id', '=', 'date_conflicts.id')
            ->select('date_conflicts.*')
            ->with(['dismisser', 'suggestions' => fn ($q) => $q->latest('id')])
            ->withCount(['suggestions as waiting_count' => fn ($q) => $q->where('status', 'pending')])
            ->orderByRaw('COALESCE(agreement.agreement, 0) DESC')
            ->orderByDesc('waiting_count')->orderBy('gap', $smallFirst ? 'asc' : 'desc')->orderBy('date_conflicts.id')->paginate(30)->withQueryString();
        $counts = DateConflict::selectRaw('direct, dismissed_at IS NOT NULL dismissed, COUNT(*) n')->groupBy('direct', DB::raw('dismissed_at IS NOT NULL'))->get();

        return view('admin.date-conflicts.index', [
            'conflicts' => $conflicts, 'records' => ConflictRecords::load($conflicts->getCollection()),
            'opinions' => CollectiveReview::opinionsOn($conflicts->getCollection()->map(fn ($c) => ['date', $c->id])->all()), 'ruleCounts' => $ruleCounts, 'counts' => $counts,
            'direct' => $direct, 'dismissed' => $dismissed, 'rule' => $rule, 'smallFirst' => $smallFirst,
            'lastRun' => ($last = DateConflict::max('last_seen_at')) ? \Illuminate\Support\Carbon::parse($last) : null,
        ]);
    }

    public function dismiss(Request $request, DateConflict $conflict)
    {
        $validated = $request->validate(['note' => 'nullable|string|max:500']);
        DB::transaction(function () use ($conflict, $validated) {
            $conflict->update(['dismissed_at' => now(), 'dismissed_by' => auth()->id(), 'dismiss_note' => $validated['note'] ?? null]);
            // «خطا نیست» is the answer «هر دو درست‌اند» of «تصحیح جمعی»: the opinions on the conflict are settled with it
            CollectiveReview::settle('date', $conflict->id, 'none', auth()->user());
        });

        return back()->with('success', 'ثبت شد: خطا نیست. این مورد در اجراهای بعدی هم کنار می‌ماند.');
    }

    public function reopen(DateConflict $conflict)
    {
        $conflict->update(['dismissed_at' => null, 'dismissed_by' => null, 'dismiss_note' => null]);

        return back()->with('success', 'مورد به فهرست بازگشت.');
    }
}
