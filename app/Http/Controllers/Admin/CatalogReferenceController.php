<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogVolume;
use App\Support\Fa;
use App\Models\ManuscriptCatalogReference as Ref;
use Illuminate\Http\Request;

/**
 * Review queue of the catalogue references matched by machine (backlog item 51): each match is checked against
 * the page image and verified or rejected; reviewed_by and reviewed_at record who did it and when.
 */
class CatalogReferenceController extends Controller
{
    public const CONFIDENCE_BANDS = [
        'high' => ['label' => 'بالا (۶۰ به بالا)', 'range' => [60, 100]],
        'medium' => ['label' => 'میانه (۳۵ تا ۵۹)', 'range' => [35, 59]],
        'low' => ['label' => 'پایین (زیر ۳۵)', 'range' => [0, 34]],
    ];

    public const STATUSES = [
        Ref::STATUS_UNVERIFIED => 'بازبینی‌نشده',
        Ref::STATUS_VERIFIED => 'تأییدشده',
        Ref::STATUS_REJECTED => 'ردشده',
    ];

    public function index(Request $request)
    {
        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : Ref::STATUS_UNVERIFIED;
        $band = self::CONFIDENCE_BANDS[$request->query('confidence')] ?? null;

        $base = Ref::where('source', Ref::SOURCE_AI_MATCH);
        $volumes = CatalogVolume::whereIn('id', (clone $base)->select('catalog_volume_id'))->orderBy('title')->get();
        $counts = (clone $base)->when($request->integer('volume'), fn ($q, $v) => $q->where('catalog_volume_id', $v))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $references = $base->with(['manuscript.work', 'catalogVolume', 'reviewer'])
            ->where('status', $status)
            ->when($request->integer('volume'), fn ($q, $v) => $q->where('catalog_volume_id', $v))
            ->when($band, fn ($q) => $q->whereBetween('confidence', $band['range']))
            ->orderBy('confidence')->orderBy('catalog_volume_id')->orderBy('page_start')->orderBy('id')
            ->paginate(40)->withQueryString();

        return view('admin.catalog_references.index', compact('references', 'volumes', 'counts', 'status'));
    }

    public function verify(Request $request, Ref $reference)
    {
        return $this->review($request, $reference, Ref::STATUS_VERIFIED, 'تأیید شد');
    }

    public function reject(Request $request, Ref $reference)
    {
        return $this->review($request, $reference, Ref::STATUS_REJECTED, 'رد شد');
    }

    private function review(Request $request, Ref $reference, string $status, string $done)
    {
        abort_unless($reference->source === Ref::SOURCE_AI_MATCH, 404);
        $reference->review($request->user(), $status);

        return back()->with('success', 'نشانی نسخه‌ی «' . Fa::digits($reference->manuscript?->shelfmark) . "» {$done}.");
    }
}
