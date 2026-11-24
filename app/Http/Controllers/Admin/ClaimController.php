<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Manuscript;
use App\Models\Source;
use App\Sources\FirstHand\ClaimReview;
use Illuminate\Http\Request;

/**
 * The panel's «ادعاهای فهرست‌های چاپی» (backlog item 147, step 14.255): where a library's own printed catalogue says
 * other than the record (made from Fankha), or fills a field it lacks. Each shows both values, the catalogue's words
 * and its printed page. «فهرست درست است» sends the claim to the suggestion page, where it is approved like any
 * correction; «فنخا درست است» rejects it.
 */
class ClaimController extends Controller
{
    public const FIELD_LABELS = [
        'shelfmark' => 'شمارۀ نسخه', 'folios' => 'شمار برگ', 'lines' => 'شمار سطر', 'dimensions' => 'اندازه',
        'copy_date' => 'تاریخ کتابت', 'scribe_name' => 'کاتب', 'script_names' => 'خط',
        // the volume's own line, read against the copy's «مجموعه» (step 14.331)
        'collection_folios' => 'شمار برگ کل مجموعه', 'collection_dimensions' => 'اندازۀ مجموعه',
    ];

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), [Claim::STATUS_ACCEPTED, Claim::STATUS_REJECTED], true) ? $request->query('status') : Claim::STATUS_CANDIDATE;
        $field = array_key_exists($request->query('field'), self::FIELD_LABELS) ? $request->query('field') : null;
        $kind = in_array($request->query('kind'), ['differs', 'fills'], true) ? $request->query('kind') : null;
        $sourceId = $request->integer('source') ?: null;

        $base = Claim::where('subject_type', Manuscript::class)->where('status', $status)
            ->when($sourceId, fn ($q) => $q->whereHas('sourceEntry', fn ($e) => $e->where('source_id', $sourceId)));
        $fieldCounts = (clone $base)->selectRaw('field, COUNT(*) n')->groupBy('field')->pluck('n', 'field');
        $filtered = (clone $base)->when($field, fn ($q) => $q->where('field', $field));
        $kindCounts = [
            'differs' => (clone $filtered)->whereNotNull('current_text')->where('current_text', '!=', '')->count(),
            'fills' => (clone $filtered)->where(fn ($q) => $q->whereNull('current_text')->orWhere('current_text', ''))->count(),
        ];
        $claims = $filtered
            ->when($kind === 'differs', fn ($q) => $q->whereNotNull('current_text')->where('current_text', '!=', ''))
            ->when($kind === 'fills', fn ($q) => $q->where(fn ($w) => $w->whereNull('current_text')->orWhere('current_text', '')))
            ->with(['subject.work', 'subject.libraryRecord', 'sourceEntry.source.library', 'sourceEntry.sourceVolume'])
            ->orderBy('subject_id')->orderBy('id')->paginate(40)->withQueryString();

        $sources = Source::whereIn('id', Claim::join('source_entries', 'source_entries.id', '=', 'claims.source_entry_id')->distinct()->pluck('source_entries.source_id'))
            ->get(['id', 'title', 'short_name']);
        $statusCounts = Claim::where('subject_type', Manuscript::class)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('admin.claims.index', compact('claims', 'status', 'field', 'kind', 'sourceId', 'fieldCounts', 'kindCounts', 'sources', 'statusCounts'));
    }

    /** «فهرست درست است»: the claim as a suggestion of the system, to approve on its page. */
    public function suggest(Claim $claim, ClaimReview $review)
    {
        abort_unless($claim->status === Claim::STATUS_CANDIDATE, 409);
        $suggestion = $review->suggestion($claim);

        return redirect()->route('admin.suggestions.show', $suggestion)
            ->with('success', 'پیشنهاد از ادعای فهرست چاپی ساخته شد. مقدار را وارسی و تأیید کنید.');
    }

    /** «فنخا درست است»: the record stands. */
    public function reject(Request $request, Claim $claim, ClaimReview $review)
    {
        $review->reject($claim, $request->user());

        return back()->with('success', 'ادعا رد شد؛ مقدار رکورد می‌ماند.');
    }

    public function reopen(Claim $claim, ClaimReview $review)
    {
        $review->reopen($claim);

        return back()->with('success', 'ادعا به فهرست بازگشت.');
    }

    /** The words of a claim, «differs» or «fills», for the page. */
    public static function kindOf(Claim $claim): string
    {
        return blank($claim->current_text) ? 'fills' : 'differs';
    }
}
