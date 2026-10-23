<?php

namespace App\Sources\Extractors;

/**
 * Finds a copy's entry in the pages of one volume of a source and takes its text (step 13.6). Every printed format
 * has its own; a source names its format in sources.extractor (see Extractors). The pages are the printed page
 * number => its text, from source_pages.
 */
interface EntryTextExtractor
{
    /**
     * The entry of the copy with this shelfmark that begins at, or holds the shelfmark on, the given page; null when
     * the pages do not show it there. Hints, when the place is the one the entry was found at: entry_number and
     * shelfmark_page tell several entries of one page apart; strict_part, when the shelfmark itself was corrected,
     * takes no treatise of a composite volume but the one of its part.
     *
     * @param  array<int, string>  $pages
     * @param  array{entry_number?: ?int, shelfmark_page?: ?int, strict_part?: bool}  $hints
     * @return array{entry_text: ?string, copy_text: ?string, page_start: int, page_end: ?int, shelfmark_page: int, entry_number: ?int}|null
     */
    public function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array;
}
