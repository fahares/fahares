<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\SourceEntry as Entry;
use App\Models\SourceVolume;
use App\Review\CollectiveReview;
use App\Support\Fa;
use Illuminate\Http\Request;

/**
 * Review queue of the catalogue pages matched by machine (backlog item 51), the source entries of origin match
 * (step 13.3): each match is checked against the page image and verified or rejected; reviewed_by and reviewed_at
 * record who did it and when.
 */
class CatalogReferenceController extends Controller
{
    public const CONFIDENCE_BANDS = [
        'high' => ['label' => 'بالا (۶۰ به بالا)', 'range' => [60, 100]],
        'medium' => ['label' => 'میانه (۳۵ تا ۵۹)', 'range' => [35, 59]],
        'low' => ['label' => 'پایین (زیر ۳۵)', 'range' => [0, 34]],
    ];

    public const STATUSES = [
        Entry::STATUS_UNVERIFIED => 'بازبینی‌نشده',
        Entry::STATUS_VERIFIED => 'تأییدشده',
        Entry::STATUS_REJECTED => 'ردشده',
    ];

    public const METHODS = [
        'shelfmark_title' => 'شماره و عنوان',
        'shelfmark_single_page' => 'شماره، عنوان نزدیک',
        'shelfmark_only' => 'فقط شماره',
        'ambiguous' => 'مبهم',
        'llm_copy' => 'مدل زبانی: اثر و تاریخ',
    ];

    /** The researchers' votes in «تصحیح جمعی» (step 14.417): any vote, or enough agreeing «همین نسخه است» and none against. */
    public const OPINIONS = [
        'any' => 'دارای رأی پژوهشگران',
        'consensus' => 'اجماع پژوهشگران بر «همین نسخه است»',
    ];

    public function index(Request $request)
    {
        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : Entry::STATUS_UNVERIFIED;

        $base = Entry::where('origin', Entry::ORIGIN_MATCH)->where('citable_type', Manuscript::class);
        $volumes = SourceVolume::whereIn('id', (clone $base)->select('source_volume_id'))->orderBy('title')->get();
        $counts = (clone $base)->when($request->integer('volume'), fn ($q, $v) => $q->where('source_volume_id', $v))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // the matches researchers have voted on come first, the best supported on top (step 14.417)
        $references = $this->filtered($request)->with(['citable.work', 'source', 'sourceVolume', 'reviewer'])
            ->where('source_entries.status', $status)
            ->leftJoinSub(CollectiveReview::agreement('catalog'), 'agreement', 'agreement.review_item_id', '=', 'source_entries.id')
            ->select('source_entries.*')->orderByRaw('COALESCE(agreement.agreement, 0) DESC')
            ->orderBy('confidence')->orderBy('source_volume_id')->orderBy('page_start')->orderBy('id')
            ->paginate(40)->withQueryString();

        $bulkAllowed = $status === Entry::STATUS_UNVERIFIED && self::bulkAllowed($request);
        $lastBulk = Entry::lastBulkVerify()?->load('user');
        $opinions = CollectiveReview::opinionsOn($references->getCollection()->map(fn ($r) => ['catalog', $r->id])->all(), true);
        $voted = (clone $base)->awaitingReview()->whereIn('id', CollectiveReview::answered('catalog'))->count();
        $agreed = (clone $base)->awaitingReview()->whereIn('id', CollectiveReview::consensus('catalog', 'yes'))->count();

        return view('admin.catalog_references.index', compact('references', 'volumes', 'counts', 'status', 'bulkAllowed', 'lastBulk', 'opinions', 'voted', 'agreed'));
    }

    /**
     * Verifies every match of the queue's filters at once (title matches only, see Entry::BULK_METHOD). The count the
     * reviewer confirmed is sent back and must still hold, so a queue changed meanwhile is not verified blind.
     */
    public function bulkVerify(Request $request)
    {
        abort_unless(self::bulkAllowed($request), 422, 'تأیید گروهی فقط برای روش «شماره و عنوان» یا اجماع پژوهشگران است.');
        $query = $this->filtered($request)->awaitingReview();
        $count = (clone $query)->count();
        if ($count === 0 || $count !== $request->integer('expected')) {
            return back()->with('error', 'شمار نشانی‌های این پالایه تغییر کرده است (' . Fa::num($count) . '). صفحه را دوباره ببینید و از نو تأیید کنید.');
        }

        $batch = Entry::bulkVerify($query, $request->user(), $this->filters($request), $request->ip());

        return back()->with('success', Fa::num($batch->new_values['count']) . ' نشانی یک‌جا تأیید شد (دستۀ #' . Fa::digits($batch->id) . ').');
    }

    public function revertBulk(Request $request, AuditLog $batch)
    {
        abort_unless(Entry::isBulkVerify($batch) && Entry::bulkQueue($batch) === null, 404);
        try {
            [$reverted, $kept] = Entry::revertBulkVerify($batch, $request->user(), $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', Fa::num($reverted) . ' نشانی به صف داوری برگشت'
            . ($kept ? '؛ ' . Fa::num($kept) . ' نشانی که از آن پس جداگانه داوری شده بود دست نخورد.' : '.'));
    }

    /**
     * A bulk verification is for title matches, where shelfmark and title agree, or for the matches the researchers of
     * «تصحیح جمعی» agree on (step 14.417); a reviewer still looks at the samples first.
     */
    private static function bulkAllowed(Request $request): bool
    {
        return $request->input('method') === Entry::BULK_METHOD || $request->input('opinions') === 'consensus';
    }

    /** The queue's filters (volume, method, confidence band, researchers' votes) that a bulk verification records. */
    private function filters(Request $request): array
    {
        return array_filter([
            'volume' => $request->integer('volume') ?: null,
            'method' => array_key_exists($request->input('method'), self::METHODS) ? $request->input('method') : null,
            'confidence' => array_key_exists($request->input('confidence'), self::CONFIDENCE_BANDS) ? $request->input('confidence') : null,
            'opinions' => array_key_exists($request->input('opinions'), self::OPINIONS) ? $request->input('opinions') : null,
        ]);
    }

    private function filtered(Request $request)
    {
        $filters = $this->filters($request);

        // the works tied to a bibliography's entries have their own queue (SourceLinkController)
        return Entry::where('origin', Entry::ORIGIN_MATCH)->where('citable_type', Manuscript::class)
            ->when($filters['volume'] ?? null, fn ($q, $v) => $q->where('source_volume_id', $v))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('match_method', $m))
            ->when($filters['confidence'] ?? null, fn ($q, $b) => $q->whereBetween('confidence', self::CONFIDENCE_BANDS[$b]['range']))
            ->when(($filters['opinions'] ?? null) === 'any', fn ($q) => $q->whereIn('source_entries.id', CollectiveReview::answered('catalog')))
            ->when(($filters['opinions'] ?? null) === 'consensus', fn ($q) => $q->whereIn('source_entries.id', CollectiveReview::consensus('catalog', 'yes')))
            // one match, as a vote's page links it
            ->when($request->integer('entry'), fn ($q, $id) => $q->where('source_entries.id', $id));
    }

    public function verify(Request $request, Entry $reference)
    {
        return $this->review($request, $reference, Entry::STATUS_VERIFIED, 'تأیید شد');
    }

    public function reject(Request $request, Entry $reference)
    {
        return $this->review($request, $reference, Entry::STATUS_REJECTED, 'رد شد');
    }

    /** The «بازگردانی» of the ⓘ badge (backlog item 59): returns the entry to the queue if the user's review still stands. */
    public function reopen(Request $request, Entry $reference)
    {
        abort_unless($reference->origin === Entry::ORIGIN_MATCH, 404);
        $expected = $request->input('status');
        abort_unless(in_array($expected, [Entry::STATUS_VERIFIED, Entry::STATUS_REJECTED], true), 422);

        if (! $reference->reopen($request->user(), $expected, $request->ip())) {
            $message = 'این نشانی را پس از شما دوباره داوری کرده‌اند و بازگردانده نشد. صفحه را تازه کنید.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->with('error', $message);
        }

        return $this->reviewed($request, $reference, 'به صف داوری برگشت');
    }

    private function review(Request $request, Entry $reference, string $status, string $done)
    {
        abort_unless($reference->origin === Entry::ORIGIN_MATCH, 404);
        $reference->review($request->user(), $status, $request->ip());

        return $this->reviewed($request, $reference, $done);
    }

    /** JSON for the badge's fetch on the record page, a redirect back for the queue's forms. */
    private function reviewed(Request $request, Entry $reference, string $done)
    {
        $message = $reference->citable instanceof Manuscript
            ? 'نشانی نسخۀ «' . Fa::digits($reference->citable->shelfmark) . "» {$done}."
            : ($reference->citable instanceof \App\Models\Person
                ? 'زندگی‌نامۀ «' . $reference->citable->name . "» {$done}."
                : 'پیوند «' . $reference->citable?->primary_title . "» {$done}.");
        if (! $request->expectsJson()) {
            return back()->with('success', $message);
        }

        return response()->json([
            'status' => $reference->status,
            'message' => $message,
            'undo' => $reference->status === Entry::STATUS_UNVERIFIED ? null : route('admin.catalog-references.reopen', $reference),
        ]);
    }
}
