<?php

namespace App\Sources\FirstHand;

use App\Models\Manuscript;
use App\Support\CopyDateRange;

/**
 * Reads a library catalogue's line for a copy against the copy's record (backlog item 147, step 14.250): each field
 * the line gives either agrees with the record (confirmed), fills a field the record lacks, or differs. The last two
 * become claims for review; the record itself is never changed here.
 *
 * Fields, named as the claims name them: shelfmark, folios, lines, dimensions, copy_date, scribe_name, script_names.
 *
 * A line that counts a whole volume («212 برگ، 11 × 18» for seven treatises) is read against the «مجموعه» the treatise
 * is in, not against the treatise (backlog item 163, step 14.331): its leaves and its size are the collection's, as
 * the fields collection_folios and collection_dimensions of the copy (SuggestionRegistry::COLLECTION_FIELDS).
 */
class CopyLineComparer
{
    public const FIELDS = ['shelfmark', 'folios', 'lines', 'dimensions', 'copy_date', 'scribe_name', 'script_names', 'collection_folios', 'collection_dimensions'];

    /**
     * @return array{confirmed: list<string>, claims: list<array{field: string, value: string, current: ?string, raw: string, fills: bool}>}
     */
    public function compare(CopyLine $line, Manuscript $ms): array
    {
        $confirmed = [];
        $claims = [];
        $claim = function (string $field, string $value, ?string $current, string $raw) use (&$claims) {
            $claims[] = ['field' => $field, 'value' => $value, 'current' => $current, 'raw' => $raw, 'fills' => $current === null || $current === ''];
        };

        if ($line->has('shelfmark')) {
            $theirs = self::numbers($line->values['shelfmark']);
            $ours = self::numbers((string) $ms->shelfmark);
            // a line may name the volume («649»), the record one treatise of it («649/2»): its counts are the volume's
            $inOrder = self::numbers((string) $ms->shelfmark, false);
            $treatise = count($theirs) === 1 && count($inOrder) > 1 && $theirs[0] === $inOrder[0];
            $line->wholeVolume = $line->wholeVolume || $treatise;
            ($theirs === $ours || $treatise) ? $confirmed[] = 'shelfmark' : $claim('shelfmark', $line->values['shelfmark'], $ms->shelfmark, $line->raw['shelfmark']);
        }

        // the volume's own line, where the treatise is in a collection of two or more: its counts are the collection's
        $volume = $line->wholeVolume ? $ms->boundCollection() : null;
        if ($volume && $line->has('folios')) {
            $value = $line->values['folios'];
            (int) $volume->folios === $value ? $confirmed[] = 'collection_folios'
                : $claim('collection_folios', (string) $value, $volume->folios === null ? null : (string) $volume->folios, $line->raw['folios']);
        }
        if ($volume && $line->has('dimensions')) {
            $theirs = $line->values['dimensions'];
            $ours = self::size((string) $volume->dimensions);
            ($ours && abs($ours[0] - $theirs[0]) <= 0.5 && abs($ours[1] - $theirs[1]) <= 0.5) ? $confirmed[] = 'collection_dimensions'
                : $claim('collection_dimensions', self::sizeText($theirs), $volume->dimensions, $line->raw['dimensions']);
        }

        if ($line->has('folios') && ! $line->wholeVolume) {
            $value = $line->values['folios'];
            (int) $ms->folios === $value ? $confirmed[] = 'folios' : $claim('folios', (string) $value, $ms->folios === null ? null : (string) $ms->folios, $line->raw['folios']);
        }

        if ($line->has('lines')) {
            $value = $line->values['lines'];
            $counts = collect(is_array($ms->line_counts) ? $ms->line_counts : (json_decode((string) $ms->line_counts, true) ?: []))->flatten()->map(fn ($n) => (int) $n);
            ((int) $ms->lines === $value || $counts->contains($value)) ? $confirmed[] = 'lines'
                : $claim('lines', (string) $value, $ms->lines === null ? null : (string) $ms->lines, $line->raw['lines']);
        }

        if ($line->has('dimensions') && ! $volume) {
            $theirs = $line->values['dimensions'];
            $ours = self::size((string) $ms->dimensions);
            // half a centimetre either way is the same size: one catalogue rounds «16/5×20/5» to «16×20»
            ($ours && abs($ours[0] - $theirs[0]) <= 0.5 && abs($ours[1] - $theirs[1]) <= 0.5) ? $confirmed[] = 'dimensions'
                : $claim('dimensions', self::sizeText($theirs), $ms->dimensions, $line->raw['dimensions']);
        }

        if ($line->has('bita')) {
            // undated, against a record with no year of its own (none, or a century or span estimated) agrees
            ($ms->is_bita || blank($ms->copy_date_raw) || $ms->copy_date_hijri_year === null) ? $confirmed[] = 'copy_date' : $claim('copy_date', 'بی‌تا', $ms->copy_date_raw, $line->raw['bita']);
        } elseif ($line->has('copy_date')) {
            $theirs = CopyDateRange::parse($line->values['copy_date']);
            // the same years in both, whatever the calendar marks: «شهریور1327» is the record's «21 شهریور 1327ش»
            $sameYears = self::years($line->values['copy_date']) && self::years($line->values['copy_date']) === self::years((string) $ms->copy_date_raw);
            if ($theirs['from'] !== null || $sameYears) {
                (($theirs['from'] === $ms->copy_date_from && $theirs['to'] === $ms->copy_date_to) || $sameYears) ? $confirmed[] = 'copy_date'
                    : $claim('copy_date', $line->values['copy_date'], $ms->copy_date_raw, $line->raw['copy_date']);
            }
        }

        if ($line->has('autograph')) {
            ($ms->is_autograph || CopyLine::isAutographWord((string) $ms->scribe_name)) ? $confirmed[] = 'scribe_name' : $claim('scribe_name', $line->values['autograph'] === true ? 'مؤلف' : (string) $line->values['autograph'], $ms->scribe_name, $line->raw['autograph']);
        } elseif ($line->has('scribe')) {
            $theirs = self::name($line->values['scribe']);
            $ours = self::name((string) $ms->scribe_name);
            ($ours !== '' && self::sameName($theirs, $ours)) ? $confirmed[] = 'scribe_name'
                : $claim('scribe_name', $line->values['scribe'], $ms->scribe_name, $line->raw['scribe']);
        } elseif ($line->has('bika')) {
            ($ms->is_bika || blank($ms->scribe_name)) ? $confirmed[] = 'scribe_name' : $claim('scribe_name', 'بی‌کا', $ms->scribe_name, $line->raw['bika']);
        }

        if ($line->has('scripts')) {
            // by words, in any order: «شکسته نستعلیق» is «نستعلیق شکسته»; «تحریری» may be the record's script style
            $ours = preg_split('/[\s،,]+/u', CopyLine::normalize($ms->script_names . ' ' . $ms->script_style));
            $missing = array_filter(preg_split('/\s+/u', implode(' ', $line->values['scripts'])), fn ($w) => ! in_array($w, $ours, true));
            ! $missing ? $confirmed[] = 'script_names' : $claim('script_names', implode('، ', $line->values['scripts']), $ms->script_names, $line->raw['scripts']);
        }

        return ['confirmed' => array_values(array_unique($confirmed)), 'claims' => $claims];
    }

    /** The numbers of a shelfmark, sorted («۲/ ۷۶۳۹-۵۱/۹۹» and «7639/2-51/99» are one), or in their order. */
    public static function numbers(string $shelfmark, bool $sorted = true): array
    {
        preg_match_all('/\d+/u', CopyLine::normalize($shelfmark), $m);
        $numbers = array_map('intval', $m[0]);
        if ($sorted) {
            sort($numbers);
        }

        return $numbers;
    }

    /** The three- and four-digit numbers of a date, sorted. */
    public static function years(string $date): array
    {
        preg_match_all('/(?<!\d)\d{3,4}(?!\d)/u', CopyLine::normalize($date), $m);
        $years = array_values(array_unique(array_map('intval', $m[0])));
        sort($years);

        return $years;
    }

    /** Two spellings of one scribe: the words of the shorter all in the longer, each alike to 85% («میر مومن حسین» and «میر مؤمن حسینی»). */
    public static function sameName(string $a, string $b): bool
    {
        // «محمد علی» and «محمدعلی», «ابو الوفا» and «ابوالوفا»: the same letters
        // «عبد النبی» and «عبدالنبی», «بدربن» and «بدر بن»: compared without spaces, «ال» and «بن»
        $bare = fn ($s) => str_replace([' ', 'ال', 'بن'], '', $s);
        if ($a === $b || $bare($a) === $bare($b) || str_contains($bare($a), $bare($b)) || str_contains($bare($b), $bare($a))) {
            return true;
        }
        // the words of one inside the other's («محمد علی» of «محمد علی قزوینی»), each word alike to nine tenths
        $short = explode(' ', mb_strlen($a) <= mb_strlen($b) ? $a : $b);
        $long = explode(' ', mb_strlen($a) <= mb_strlen($b) ? $b : $a);
        $found = 0;
        foreach ($short as $w) {
            foreach ($long as $v) {
                similar_text($w, $v, $p);
                if ($w === $v || $p >= 85) {
                    $found++;
                    break;
                }
            }
        }

        return $short !== [''] && $found === count($short);
    }

    /** «11×23سم» → [11.0, 23.0], the smaller first («16/5» is 16.5); null without two numbers. */
    public static function size(string $text): ?array
    {
        $text = preg_replace('/(\d+)\/(\d)\b/u', '$1.$2', CopyLine::normalize($text));
        if (! preg_match('/(\d+(?:\.\d+)?)\s*[×x*]\s*(\d+(?:\.\d+)?)/u', CopyLine::normalize($text), $m)) {
            return null;
        }
        $pair = [(float) $m[1], (float) $m[2]];
        sort($pair);

        return $pair;
    }

    private static function sizeText(array $pair): string
    {
        return rtrim(rtrim(number_format($pair[0], 1, '.', ''), '0'), '.') . '×' . rtrim(rtrim(number_format($pair[1], 1, '.', ''), '0'), '.') . 'سم';
    }

    /** A scribe's name for comparing: no spaces, «بن»/«ابن», «ال», or diacritics. */
    public static function name(string $name): string
    {
        $name = CopyLine::normalize($name);
        $name = preg_replace('/[\x{064B}-\x{0652}]/u', '', $name);
        // the record's notes in brackets and the catalogue's «بخش نونویس:» are not the name; «قزوینی، محمد علی» is
        // «محمد علی قزوینی»: the words, without «بن» and «ال», in order
        $name = preg_replace('/\([^)]*\)|بخش نونویس\s*:?|تلمیذ مصنف|شاگرد مصنف/u', ' ', $name);
        // the record's «کاشانی، محمد بن محمد باقر» back in the catalogue's order
        if (preg_match('/^([^،]+)،\s*(.+)$/u', trim($name), $m)) {
            $name = $m[2] . ' ' . $m[1];
        }
        $words = preg_split('/[\s\.\-،,]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn ($w) => ! in_array($w, ['بن', 'ابن', 'بنت'], true));

        return implode(' ', $words);
    }
}
