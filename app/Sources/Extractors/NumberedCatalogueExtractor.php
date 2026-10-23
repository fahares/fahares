<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * Catalogues whose entries are numbered, «607- دیوان قدسی (فارسی)», with each copy on a line of its own, «ش 1939، 207
 * برگ»; composite volumes headed «( 2967 )» with their treatises «1 - …» and the notes that close them (the
 * Masjid-e A'zam catalogue as Ostadi printed it, backlog items 51 and 60). The reading of the format is
 * catalogs:match-edition's: this finds one copy at one address with it.
 */
class NumberedCatalogueExtractor implements EntryTextExtractor
{
    /** @var array<string, array> the format's reading of each volume's pages */
    private static array $read = [];

    public function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array
    {
        if (! $pages || ! preg_match('/(\d+)(?:\s*\/\s*(\d+))?/', Format::digits($shelfmark), $base)) {
            return null;
        }
        // one reading of a volume serves every entry of it in a request (a review page, sources:retext)
        $key = md5(implode(',', array_keys($pages)) . '|' . array_sum(array_map('strlen', $pages)));
        [$index, , $lines] = self::$read[$key] ??= Format::index($pages);
        $part = isset($base[2]) ? (int) $base[2] : null;
        $places = $index[$base[1]] ?? [];
        // the entry that begins on the page, or else the one whose copy line stands on it
        $here = array_filter($places, fn ($p) => ($p['entry']['page_start'] ?? null) === $page) ?: array_filter($places, fn ($p) => $p['page'] === $page);
        // a corrected shelfmark names its treatise of a composite volume: no other of the volume is taken for it
        if (! empty($hints['strict_part']) && $part !== null && array_filter($here, fn ($p) => isset($p['part']))) {
            $here = array_filter($here, fn ($p) => ($p['part'] ?? null) === $part);
        }
        // as catalogs:match-edition found it: its entry number, then the shelfmark's part, then the page of the number
        $number = $hints['entry_number'] ?? null;
        $at = $hints['shelfmark_page'] ?? null;
        usort($here, fn ($a, $b) => [
            $number !== null && ($a['entry']['number'] ?? null) !== $number, ($a['part'] ?? $part) !== $part, $at !== null && $a['page'] !== $at,
        ] <=> [
            $number !== null && ($b['entry']['number'] ?? null) !== $number, ($b['part'] ?? $part) !== $part, $at !== null && $b['page'] !== $at,
        ]);
        $place = $here[0] ?? null;
        if (! $place || ! isset($place['entry'])) {
            return null;
        }
        [$entryText, $copyText] = Format::texts($place, $lines);

        return [
            'entry_text' => $entryText,
            'copy_text' => $copyText,
            'page_start' => $place['entry']['page_start'] ?? $place['page'],
            'page_end' => $place['entry']['page_end'] ?? null,
            'shelfmark_page' => $place['page'],
            'entry_number' => $place['entry']['number'] ?? null,
        ];
    }
}
