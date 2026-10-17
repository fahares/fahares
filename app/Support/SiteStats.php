<?php

namespace App\Support;

use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;

/**
 * Record counts shown on the site, read from the database and cached for an hour.
 * Call forget() after an import or a bulk merge to show the new numbers at once.
 */
class SiteStats
{
    private const CACHE_KEY = 'site_stats';
    private const TTL = 3600;

    /** @return array{works:int, manuscripts:int, people:int, libraries:int, catalogers:int} */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => [
            'works' => Work::count(),
            'manuscripts' => Manuscript::count(),
            'people' => Person::count(),
            'libraries' => Library::count(),
            'catalogers' => Cataloger::count(),
        ]);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
