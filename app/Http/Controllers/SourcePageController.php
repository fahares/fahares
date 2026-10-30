<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\BibliographyLinks;
use App\Support\PrintedPage;

/**
 * The text of one printed page of a source (plan docs/plans/source-page-text.md): /sources/monzavi/5/page/3366. It
 * lets a citation whose entry was not found still reach its page; noindex, since every entry's text has its own page.
 */
class SourcePageController extends Controller
{
    public function show(string $code, int $volume, int $page)
    {
        $source = Source::where('code', $code)->firstOrFail();
        $sourceVolume = SourceVolume::where('source_id', $source->id)->where('volume_number', $volume)->first();
        $printed = $sourceVolume ? PrintedPage::find($source, $sourceVolume, $page) : null;
        if (! $printed) {
            // pages that run on across volumes: «3257» cited in volume 4 is in volume 5
            $other = BibliographyLinks::textVolume($source->metadata['config'] ?? $code, $volume, $page);
            abort_if(! $other || $other === $volume, 404);

            return redirect()->route('source-pages.show', [$code, $other, $page], 301);
        }

        // the works tied to the page's entries, a person's review first; a rejected tie is gone. An entry's heading
        // links its first work, so the page leads to the records rather than to the entry's text again
        $ties = SourceEntry::with(['citable', 'sourceItem'])->whereIn('source_item_id', $printed->items->pluck('id'))->where('citable_type', Work::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)->orderByRaw('status = ? desc', [SourceEntry::STATUS_VERIFIED])
            ->orderByDesc('confidence')->get()->filter(fn ($l) => $l->citable);
        $links = $ties->unique('citable_id')->values();
        $works = $ties->groupBy('source_item_id')->map(fn ($l) => $l->unique('citable_id')->values());

        return view('source-pages.show', ['printed' => $printed, 'links' => $links, 'works' => $works]);
    }
}
