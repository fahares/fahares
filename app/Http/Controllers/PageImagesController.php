<?php

namespace App\Http\Controllers;

use App\Models\SourceItem;
use App\Models\SourceVolume;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * The page range of one volume's scanned pages (backlog item 94), which caps the page viewer's «next»:
 * /api/page-images/fankha/33 → {"first": 1, "last": 984}. Read from the image files themselves, since a volume
 * may start past page 1 (Kashf al-Zunun vol. 2 starts at 945) and pages_count is not filled in. When the site has the
 * volume's text too, "text" is the address of its page text without the page, for the viewer's «متن» button.
 */
class PageImagesController extends Controller
{
    public function show(string $code, int $volume): JsonResponse
    {
        $range = Cache::remember("page-images:{$code}:{$volume}", now()->addDay(), function () use ($code, $volume) {
            $dir = storage_path('app/public/catalogs/' . $code . '/v' . str_pad($volume, 2, '0', STR_PAD_LEFT));
            $pages = collect(glob($dir . '/p*.webp') ?: [])->map(fn ($f) => (int) substr(basename($f), 1, -5))->filter();

            return $pages->isEmpty() ? null : ['first' => $pages->min(), 'last' => $pages->max()];
        });

        if ($range && ($text = $this->textBase($code, $volume))) {
            $range['text'] = $text;
        }

        return $range
            ? response()->json($range)->header('Cache-Control', 'public, max-age=86400')
            : response()->json(['message' => 'No page images'], 404);
    }

    /** /sources/fankha/2/page/ for a volume whose page texts or entries the site has (plan docs/plans/source-page-text.md). */
    private function textBase(string $code, int $volume): ?string
    {
        return Cache::remember("page-text:{$code}:{$volume}", now()->addHour(), function () use ($code, $volume) {
            $sourceVolume = SourceVolume::with('source')->where('facsimile_code', $code)->where('volume_number', $volume)->first();
            if (! $sourceVolume?->source || ! (DB::table('source_pages')->where('source_volume_id', $sourceVolume->id)->exists()
                || SourceItem::where('source_volume_id', $sourceVolume->id)->exists())) {
                return null;
            }

            // the address of page 0, without the 0
            return substr(route('source-pages.show', [$sourceVolume->source->code, $volume, 0]), 0, -1);
        });
    }
}
