<?php

namespace App\Support;

/**
 * The dynasties and who ruled each city of copying in each decade (database/data/dynasties.php, written by
 * scripts/dev/dynasty-rule from the statistics of dawawin.ir; backlog item 154, step 14.265). A city is under the
 * dynasty whose territory held it in the middle year of the decade, so a year near a change of rule may fall on the
 * wrong side of it, and a decade with no territory over the city has no dynasty.
 */
class Dynasties
{
    private static ?array $data = null;

    /** @var array<string, string>|null the authority name of a ruler of KnownRulers => his dynasty */
    private static ?array $courts = null;

    private static function data(): array
    {
        return self::$data ??= require database_path('data/dynasties.php');
    }

    /** @return array<int, array{name: string, region: string, from: int, to: int}> */
    public static function all(): array
    {
        return self::data()['dynasties'];
    }

    /** The regions of the dynasties, in the order of the data: the order of their tints and of the rows of the chart */
    public static function regions(): array
    {
        return array_values(array_unique(array_column(self::all(), 'region')));
    }

    /** @return array<int, array{name: string, dynasty: string, lat: float, lon: float, from: int, to: int}> the seats of the dynasties */
    public static function capitals(): array
    {
        return self::data()['capitals'] ?? [];
    }

    /** The dynasty a city was under in a Hijri year, or null */
    public static function of(string $city, int $year): ?string
    {
        foreach (self::data()['rule'][$city] ?? [] as [$from, $to, $name]) {
            if ($year >= $from && $year <= $to) {
                return $name;
            }
        }

        return null;
    }

    /** The rule over a city within a span of years: [[from, to, dynasty]], each cut to the span */
    public static function runs(string $city, int $from, int $to): array
    {
        $runs = [];
        foreach (self::data()['rule'][$city] ?? [] as [$start, $end, $name]) {
            if ($end >= $from && $start <= $to) {
                $runs[] = [max($start, $from), min($end, $to), $name];
            }
        }

        return $runs;
    }

    /** The dynasty of a ruler, minister or scholar of a court, by the name of his person record (KnownRulers) */
    public static function courtOf(?string $personName): ?string
    {
        self::$courts ??= array_column(KnownRulers::LIST, 'dynasty', 'name');

        return $personName === null ? null : (self::$courts[$personName] ?? null);
    }
}
