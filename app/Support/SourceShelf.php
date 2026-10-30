<?php

namespace App\Support;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the site has of each source, for «منابع و مراجع» (/sources) and a source's page (step 13.80): the works and
 * manuscripts made from it or tied to it, its printed entries, the pages whose text it has, and whether it has page
 * images. Counted over the whole tables, so kept a day.
 */
class SourceShelf
{
    /** @return array<int, array{works: int, manuscripts: int, items: int, pages: int}> by source id */
    public static function counts(): array
    {
        return Cache::remember('sources:shelf', now()->addDay(), function () {
            $counts = [];
            $add = function ($rows, string $key) use (&$counts) {
                foreach ($rows as $row) {
                    $counts[$row->source_id] ??= ['works' => 0, 'manuscripts' => 0, 'items' => 0, 'pages' => 0];
                    $counts[$row->source_id][$key] = (int) $row->n;
                }
            };
            foreach ([Work::class => 'works', Manuscript::class => 'manuscripts'] as $type => $key) {
                $add(DB::table('source_entries')->where('citable_type', $type)->where('status', '!=', SourceEntry::STATUS_REJECTED)
                    ->selectRaw('source_id, count(distinct citable_id) n')->groupBy('source_id')->get(), $key);
            }
            $add(DB::table('source_items')->selectRaw('source_id, count(*) n')->groupBy('source_id')->get(), 'items');
            $add(DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
                ->selectRaw('source_volumes.source_id, count(*) n')->groupBy('source_volumes.source_id')->get(), 'pages');

            return $counts;
        });
    }

    /** Whether the site shows the source's page images: a volume with a folder of them, and not a text-only source. */
    public static function hasImages(Source $source): bool
    {
        return $source->hasPageImages() && $source->volumes->contains(fn ($v) => filled($v->facsimile_code)
            && is_dir(storage_path('app/public/catalogs/' . $v->facsimile_code . '/v' . str_pad($v->volume_number, 2, '0', STR_PAD_LEFT))));
    }
}
