<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\SourceEntry as Entry;
use App\Models\SourceVolume;
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
    ];

    public function index(Request $request)
    {
        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : Entry::STATUS_UNVERIFIED;

        $base = Entry::where('origin', Entry::ORIGIN_MATCH);
        $volumes = SourceVolume::whereIn('id', (clone $base)->select('source_volume_id'))->orderBy('title')->get();
        $counts = (clone $base)->when($request->integer('volume'), fn ($q, $v) => $q->where('source_volume_id', $v))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $references = $this->filtered($request)->with(['citable.work', 'source', 'sourceVolume', 'reviewer'])
            ->where('status', $status)
            ->orderBy('confidence')->orderBy('source_volume_id')->orderBy('page_start')->orderBy('id')
            ->paginate(40)->withQueryString();

        $bulkAllowed = $status === Entry::STATUS_UNVERIFIED && $request->query('method') === Ref::BULK_METHOD;
        $lastBulk = Entry::lastBulkVerify()?->load('user');

        return view('admin.catalog_references.index', compact('references', 'volumes', 'counts', 'status', 'bulkAllowed', 'lastBulk'));
    }

    /**
     * Verifies every match of the queue's filters at once (title matches only, see Ref::BULK_METHOD). The count the
     * reviewer confirmed is sent back and must still hold, so a queue changed meanwhile is not verified blind.
     */
    public function bulkVerify(Request $request)
    {
        abort_unless($request->input('method') === Ref::BULK_METHOD, 422, 'تأیید گروهی فقط برای روش «شماره و عنوان» است.');
        $query = $this->filtered($request)->awaitingReview();
        $count = (clone $query)->count();
        if ($count === 0 || $count !== $request->integer('expected')) {
            return back()->with('error', 'شمار نشانی‌های این پالایه تغییر کرده است (' . Fa::num($count) . '). صفحه را دوباره ببینید و از نو تأیید کنید.');
        }

        $batch = Entry::bulkVerify($query, $request->user(), $this->filters($request), $request->ip());

        return back()->with('success', Fa::num($batch->new_values['count']) . ' نشانی یک‌جا تأیید شد (دسته‌ی #' . Fa::digits($batch->id) . ').');
    }

    public function revertBulk(Request $request, AuditLog $batch)
    {
        abort_unless(Entry::isBulkVerify($batch), 404);
        try {
            [$reverted, $kept] = Entry::revertBulkVerify($batch, $request->user(), $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', Fa::num($reverted) . ' نشانی به صف داوری برگشت'
            . ($kept ? '؛ ' . Fa::num($kept) . ' نشانی که از آن پس جداگانه داوری شده بود دست نخورد.' : '.'));
    }

    /** The queue's filters (volume, method, confidence band) that a bulk verification records. */
    private function filters(Request $request): array
    {
        return array_filter([
            'volume' => $request->integer('volume') ?: null,
            'method' => array_key_exists($request->input('method'), self::METHODS) ? $request->input('method') : null,
            'confidence' => array_key_exists($request->input('confidence'), self::CONFIDENCE_BANDS) ? $request->input('confidence') : null,
        ]);
    }

    private function filtered(Request $request)
    {
        $filters = $this->filters($request);

        return Entry::where('origin', Entry::ORIGIN_MATCH)
            ->when($filters['volume'] ?? null, fn ($q, $v) => $q->where('source_volume_id', $v))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('match_method', $m))
            ->when($filters['confidence'] ?? null, fn ($q, $b) => $q->whereBetween('confidence', self::CONFIDENCE_BANDS[$b]['range']));
    }

    public function verify(Request $request, Entry $reference)
    {
        return $this->review($request, $reference, Entry::STATUS_VERIFIED, 'تأیید شد');
    }

    public function reject(Request $request, Entry $reference)
    {
        return $this->review($request, $reference, Entry::STATUS_REJECTED, 'رد شد');
    }

    private function review(Request $request, Entry $reference, string $status, string $done)
    {
        abort_unless($reference->origin === Entry::ORIGIN_MATCH, 404);
        $reference->review($request->user(), $status);

        return back()->with('success', 'نشانی نسخه‌ی «' . Fa::digits($reference->citable?->shelfmark) . "» {$done}.");
    }
}
