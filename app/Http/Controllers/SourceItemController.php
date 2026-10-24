<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\Work;

/**
 * The page of one printed entry of a source (plan docs/plans/dharia-text.md): /sources/dharia/3/1442. Every entry has
 * one, linked to a record or not; the sitemap lists only those tied to a record.
 */
class SourceItemController extends Controller
{
    public function show(string $code, int $volume, string $key)
    {
        $source = Source::where('code', $code)->firstOrFail();
        $item = SourceItem::with(['source', 'sourceVolume', 'parent', 'children'])
            ->where('source_id', $source->id)->where('volume_number', $volume)->where('item_key', SourceItem::keyFromUrl($key))->firstOrFail();

        // the works tied to the entry, a person's review first; a rejected tie is gone
        $links = SourceEntry::with('citable')->where('source_item_id', $item->id)->where('citable_type', Work::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)->orderByRaw('status = ? desc', [SourceEntry::STATUS_VERIFIED])
            ->orderByDesc('confidence')->get()->filter(fn ($l) => $l->citable)->unique('citable_id')->values();
        $previous = SourceItem::where('source_volume_id', $item->source_volume_id)->where('position', '<', $item->position)->orderByDesc('position')->first();
        $next = SourceItem::where('source_volume_id', $item->source_volume_id)->where('position', '>', $item->position)->orderBy('position')->first();

        return view('source-items.show', compact('item', 'links', 'previous', 'next'));
    }
}
