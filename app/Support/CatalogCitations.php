<?php

namespace App\Support;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as Import;
use App\Console\Commands\LinkCatalogVolumesCommand as Link;

/**
 * The places of the printed catalogues a copy's Fankha text cites, one for each place of each source (step 14.42).
 * The citation closes the text: «[ف: 2-224]», sometimes two brackets («[اهدائی رهبر: 3-673] و [ف: 21-1380]») and
 * sometimes several sources or pages in one («[سنا: ف: 2-136؛ فهرست رایانه‌ای ج4-1076]», «[ف: 2 - 444 و عکسی ف: 5 - 350]»,
 * «[ف: 11-495 و 12-407]», «[فیلمها ف: 1 - 566 و 743]»). A place that leaves out its series or volume takes them from
 * the one before it. «و» inside a name («ساری و تنکابن») or an issue («س18ش1و2») does not part two places.
 */
class CatalogCitations
{
    /** A bracket longer than this is a description Fankha bracketed, not a citation */
    private const LONGEST = 150;

    /**
     * The brackets of the closing citation, in order: the last one and those joined to it by «و», «،» or «؛».
     *
     * @return list<string>
     */
    public static function brackets(?string $rawText): array
    {
        $raw = rtrim(preg_replace('/<!--.*?-->/su', '', (string) $rawText));
        if (! preg_match('/(?:\[[^\[\]]*\]\s*(?:و|،|؛)\s*)*\[[^\[\]]*\]\s*\.?$/u', $raw, $m)) {
            return [];
        }
        preg_match_all('/\[([^\[\]]*)\]/u', $m[0], $all);

        return array_values(array_filter($all[1], fn ($b) => trim($b) !== '' && mb_strlen($b) <= self::LONGEST));
    }

    /**
     * The places of one bracket, each written in full (the «743» of «فیلمها ف: 1 - 566 و 743» is «فیلمها ف: 1-743»),
     * with what parse() reads of it.
     *
     * @return list<array{text: string, series: ?string, volume: ?int, page: ?int}>
     */
    public static function places(string $bracket): array
    {
        $text = Link::normalize($bracket);
        $places = [];
        $prev = null;
        // «؛» parts places; «و» parts a number from a number or a new series, but not the issues of a journal
        // («س 13ش 2 و 3-329»); «،» only before a new series («ف: 2-236، ف: 3-147»)
        $marked = preg_replace_callback('/\s*؛\s*|(?<=\d)\s+و\s+(?=\d|[^\d\s][^\d:]*:\s*\S)|(?<=\d)\s*،\s*(?=[^\d\s،][^\d:،]*:\s*\d)/u',
            fn () => "\x1F", $text);
        $pieces = [];
        foreach (explode("\x1F", $marked) as $piece) {
            // a «و» right after the issue of a journal joins two issues: put it back
            if ($pieces && preg_match('/[شس]\s*\d+$/u', end($pieces)) && preg_match('/^\d+\s*-/u', $piece)
                && ! preg_match('/[:]\s*\d+\s*-\s*\d+$/u', end($pieces))) {
                $pieces[array_key_last($pieces)] .= ' و ' . $piece;

                continue;
            }
            $pieces[] = $piece;
        }
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ($prev && preg_match('/^\d/u', $piece)) {
                // «12-407» after «ف: 11-495» is of the same series; «743» after «ف: 1 - 566» of the same volume
                if (preg_match('/^\d+\s*-\s*\d+/u', $piece)) {
                    $piece = $prev['series'] . ': ' . $piece;
                } else {
                    // «ف: 137 و 155»: two pages, so the lone number before was a page too
                    $last = array_key_last($places);
                    if ($last !== null && $places[$last]['volume'] === null && $places[$last]['page'] === null
                        && preg_match('/:\s*(\d+)$/u', $places[$last]['text'], $n)) {
                        $places[$last]['page'] = (int) $n[1];
                    }
                    $piece = $prev['series'] . ': ' . ($prev['volume'] ?? '') . '-' . $piece;
                }
            }
            if (! preg_match('/\d/u', $piece) && ! Link::nonCatalogSource($piece)) {
                continue;
            }
            $parsed = Import::parse($piece);
            $place = ['text' => $piece, 'series' => $parsed['series'], 'volume' => $parsed['volume'], 'page' => $parsed['page']];
            $places[] = $place;
            $prev = $place['series'] !== null ? $place : $prev;
        }

        return $places;
    }

    /**
     * Every place the copy cites but the one its catalogue entry already holds (the first place of rawCitation()).
     *
     * @return list<array{text: string, series: ?string, volume: ?int, page: ?int}>
     */
    public static function others(?string $metadata, ?string $rawText): array
    {
        $primary = Import::rawCitation($metadata, $rawText);
        $primary = $primary === null ? null : Link::normalize($primary);
        $places = [];
        $skipped = false;
        foreach (self::brackets($rawText) as $bracket) {
            $own = self::places($bracket);
            // the primary citation may be a longer string that ends with this bracket (a bracket left open before it)
            if (! $skipped && $own && $primary !== null && str_ends_with($primary, Link::normalize($bracket))) {
                array_shift($own);
                $skipped = true;
            }
            array_push($places, ...$own);
        }

        return $places;
    }

    /** The series as a key: «عکسی - ف» and «عکسی ف» are one series. */
    public static function seriesKey(?string $series): string
    {
        return preg_replace('/[\s\-:]+/u', '', Link::normalize((string) $series));
    }
}
