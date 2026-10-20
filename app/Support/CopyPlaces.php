<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use Illuminate\Support\Facades\Cache;

/**
 * The cities of the free-text copy_place of manuscripts (config/insights.php copy_places).
 * A place belongs to the city whose name, or another name of it, comes first in the text as a
 * whole word: «قمصر کاشان» is قمصر, «اصفهان مدرسه جده» is اصفهان, «ایران» is no city.
 */
class CopyPlaces
{
    private const VARIANTS_KEY = 'copy_places.variants';

    /** @var array<int, array{0:string, 1:string}>|null normalized name => city, longest name first */
    private static ?array $names = null;

    /** @return array<string, array{lat: float, lon: float, aliases: array<int, string>}> */
    public static function cities(): array
    {
        return config('insights.copy_places', []);
    }

    public static function isCity(?string $city): bool
    {
        return $city !== null && array_key_exists($city, self::cities());
    }

    public static function cityOf(?string $place): ?string
    {
        $text = ' ' . preg_replace('/[^\p{L}\p{N}]+/u', ' ', TextNormalizer::normalize($place)) . ' ';
        if (trim($text) === '') {
            return null;
        }

        $best = null;
        $bestPos = PHP_INT_MAX;
        foreach (self::names() as [$name, $city]) {
            $pos = mb_strpos($text, ' ' . $name . ' ');
            // Longest name first, so «کرمانشاه» wins over «کرمان» at the same position
            if ($pos !== false && $pos < $bestPos) {
                $best = $city;
                $bestPos = $pos;
            }
        }

        return $best;
    }

    /**
     * The copy_place texts of a city as they are stored, for a search filter on them.
     *
     * @return array<int, string>
     */
    public static function variants(string $city): array
    {
        return self::variantMap()[$city] ?? [];
    }

    /**
     * Every stored copy_place text grouped by its city, cached for a day.
     *
     * @return array<string, array<int, string>>
     */
    public static function variantMap(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::VARIANTS_KEY);
        }

        return Cache::remember(self::VARIANTS_KEY, 86400, function () {
            $map = [];
            Manuscript::query()
                ->whereNotNull('copy_place')->where('copy_place', '!=', '')
                ->distinct()->orderBy('copy_place')
                ->pluck('copy_place')
                ->each(function ($place) use (&$map) {
                    if ($city = self::cityOf($place)) {
                        $map[$city][] = $place;
                    }
                });

            return $map;
        });
    }

    /** @return array<int, array{0:string, 1:string}> */
    private static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        $names = [];
        foreach (self::cities() as $city => $info) {
            foreach (array_merge([$city], $info['aliases'] ?? []) as $name) {
                $norm = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', TextNormalizer::normalize($name)));
                $names[$norm] = [$norm, $city];
            }
        }
        usort($names, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return self::$names = $names;
    }
}
