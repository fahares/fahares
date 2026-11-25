<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Support\PrintedPage;
use App\Support\SourceReference;
use Illuminate\Http\Request;

/**
 * The citation of a place in a printed source (step 14.389, backlog item 132): /cite?source=monzavi&volume=5&page=3366,
 * &entry=1442 for an entry, or catalog= (a volume's facsimile code) from the page viewer. The forms to copy, the
 * permanent address, and BibTeX and RIS to download (format=bib, format=ris). The source text, entry and source pages
 * and the page viewer link here; the work and copy pages no longer carry a citation window of their own (step 14.390).
 */
class CiteController extends Controller
{
    public function show(Request $request)
    {
        $reference = $this->reference($request);

        return match ($request->query('format')) {
            'bib' => $this->download($reference->bibtex(), $reference->key() . '.bib', 'application/x-bibtex'),
            'ris' => $this->download($reference->ris(), $reference->key() . '.ris', 'application/x-research-info-systems'),
            default => view('cite.show', ['reference' => $reference]),
        };
    }

    private function reference(Request $request): SourceReference
    {
        $volumeNumber = ctype_digit((string) $request->query('volume')) ? (int) $request->query('volume') : null;
        $page = ctype_digit((string) $request->query('page')) ? (int) $request->query('page') : null;

        if ($request->filled('catalog')) {
            // the page viewer knows a volume by its facsimile code
            $volume = SourceVolume::with('source')->where('facsimile_code', (string) $request->query('catalog'))
                ->where('volume_number', $volumeNumber)->first();
            abort_if(! $volume?->source, 404);
            $source = $volume->source;
        } else {
            $source = Source::where('code', (string) $request->query('source'))->first();
            abort_if(! $source, 404);
            $volume = $volumeNumber !== null ? SourceVolume::where('source_id', $source->id)->where('volume_number', $volumeNumber)->first() : null;
            abort_if($volumeNumber !== null && ! $volume, 404);
        }
        $volume?->load('catalogers');

        if ($request->filled('entry')) {
            abort_if(! $volume, 404);
            $item = SourceItem::with(['source', 'sourceVolume.catalogers'])->where('source_volume_id', $volume->id)
                ->where('item_key', SourceItem::keyFromUrl((string) $request->query('entry')))->first();
            abort_if(! $item, 404);

            return SourceReference::forItem($item);
        }
        // a page cited is one the volume has, as text or (from the page viewer) as an image
        abort_if($page !== null && (! $volume || ! ($volume->facsimile_code && $source->hasPageImages()) && ! PrintedPage::exists($volume, $page)), 404);

        return new SourceReference($source, $volume, $volume ? $page : null);
    }

    private function download(string $body, string $name, string $type)
    {
        return response($body, 200, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
