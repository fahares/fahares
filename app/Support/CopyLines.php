<?php

namespace App\Support;

/**
 * A copy's line counts as its Fankha text gives them (item 87, step 13.156). A catalogue that writes «18 و 21 سطر»
 * reports two layouts, not a span from 18 to 21, and a third or a fourth may follow («11 و 20 و 24 سطر»); «15-22 سطر»
 * («15 تا 22») is one layout whose count varies between the two. So the counts are a list of layouts, each a pair
 * [from, to] (equal for a fixed count): [[18, 18], [21, 21]] and [[15, 22]]. line_counts keeps that list and
 * lines_raw the catalogue's wording; lines, the single count the site has shown and sorts by, is left as it is.
 */
class CopyLines
{
    /**
     * A run of counts joined by «و», «-», «تا» or «،» right before «سطر» (not «سطرها», as in «میان سطرها»), and not the
     * end of a text block's size («25 سطر 13/5×23 سطر»)
     */
    private const PHRASE = '/(?<![×\d\/])((?:\d+(?:\/\d+)?\s*(?:و|-|–|تا|،)\s*)*\d+(?:\/\d+)?)\s*سطر(?!ها)/u';

    /** A count above this is a leaf, page or folio number the run caught («487-521، 25 سطر»), not lines */
    private const MOST = 99;

    /**
     * The wording and the layouts of a copy's text, or null when it names no lines. A phrase with a fraction
     * («13/5 و 14 سطر») keeps its wording but gives no layout.
     *
     * @return array{lines_raw: string, line_counts: ?array<int, array{0: int, 1: int}>}|null
     */
    public static function fromText(?string $text): ?array
    {
        $text = strtr(preg_replace('/\s*<!--\s*page:\s*\d+\s*-->\s*/u', ' ', (string) $text), array_combine(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']
        ));
        if (! preg_match_all(self::PHRASE, $text, $all, PREG_SET_ORDER)) {
            return null;
        }
        $raws = [];
        $counts = [];
        foreach ($all as [, $run]) {
            $run = self::own($run);
            if ($run === null) {
                continue;
            }
            $raws[] = trim(preg_replace('/\s+/u', ' ', $run)) . ' سطر';
            if (! str_contains($run, '/')) {
                array_push($counts, ...self::layouts($run));
            }
        }
        if (! $raws) {
            return null;
        }

        return ['lines_raw' => mb_substr(implode('؛ ', array_unique($raws)), 0, 255), 'line_counts' => $counts ?: null];
    }

    /** The two columns as stored, typed as fromText gives them. */
    public static function stored(object $row): array
    {
        $counts = $row->line_counts === null ? null : json_decode($row->line_counts, true);

        return ['lines_raw' => $row->lines_raw, 'line_counts' => $counts];
    }

    /** The columns to write when they differ from what the copy's text gives, else null. */
    public static function columns(object $row): ?array
    {
        $new = self::fromText($row->raw_text) ?? ['lines_raw' => null, 'line_counts' => null];

        return $new === self::stored($row) ? null : $new;
    }

    /** The columns ready for DB::table()->update(), the layouts as JSON; [] for null. */
    public static function forUpdate(?array $cols): array
    {
        if ($cols === null) {
            return [];
        }

        return ['lines_raw' => $cols['lines_raw'], 'line_counts' => $cols['line_counts'] === null ? null : json_encode($cols['line_counts'])];
    }

    /**
     * The part of a run that counts lines: from after the last count that cannot be one («133، 21» is 133 pages and
     * 21 lines), and with «،» joining counts only in a list of three or more or one with «و» («19، 22 و 25»).
     */
    private static function own(string $run): ?string
    {
        preg_match_all('/\d+(?:\/\d+)?/u', $run, $m, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($m[0]) as [$number, $at]) {
            if ((float) str_replace('/', '.', $number) > self::MOST) {
                $run = substr($run, $at + strlen($number));
                break;
            }
        }
        $run = trim($run, " \t،-–");
        if (! preg_match('/\d/', $run)) {
            return null;
        }
        if (str_contains($run, '،') && ! str_contains($run, 'و') && substr_count($run, '،') < 2) {
            $run = trim(substr($run, strrpos($run, '،') + strlen('،')));
        }

        return $run;
    }

    /** @return array<int, array{0: int, 1: int}> */
    private static function layouts(string $run): array
    {
        $layouts = [];
        foreach (preg_split('/\s*(?:و|،)\s*/u', $run, -1, PREG_SPLIT_NO_EMPTY) as $group) {
            $numbers = array_map('intval', preg_split('/\s*(?:-|–|تا)\s*/u', trim($group), -1, PREG_SPLIT_NO_EMPTY));
            // «15-22» is one layout that varies; «17-15-10» lists three
            if (count($numbers) === 2) {
                $layouts[] = [min($numbers), max($numbers)];
            } else {
                foreach ($numbers as $n) {
                    $layouts[] = [$n, $n];
                }
            }
        }

        return $layouts;
    }
}
