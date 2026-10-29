<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The page range of one volume's scanned pages (backlog item 94), which caps the page viewer's «next»:
 * /api/page-images/fankha/33 → {"first": 1, "last": 984}. Read from the image files themselves, since a volume
 * may start past page 1 (Kashf al-Zunun vol. 2 starts at 945) and pages_count is not filled in.
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

        return $range
            ? response()->json($range)->header('Cache-Control', 'public, max-age=86400')
            : response()->json(['message' => 'No page images'], 404);
    }
}
