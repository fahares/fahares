<?php

namespace App\Sources\Extractors;

use App\Models\SourceEntry;
use Illuminate\Support\Facades\DB;

/**
 * The extractor of each printed format (step 13.6), by the key a source keeps in sources.extractor, and the reading of
 * an entry's text at an address through its source's. A source with no extractor, or no page texts, gives none.
 */
class Extractors
{
    public const FORMATS = [
        'numbered_catalogue' => NumberedCatalogueExtractor::class,
    ];

    /** @var array<int, array<int, string>> source_volumes.id => its pages, read once a request */
    private static array $pages = [];

    public static function for(?string $key): ?EntryTextExtractor
    {
        $class = self::FORMATS[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** Whether the entry's source can be read for its text. */
    public static function canRead(SourceEntry $entry): bool
    {
        return (bool) self::for($entry->source?->extractor) && $entry->source_volume_id && self::pages($entry->source_volume_id);
    }

    /**
     * The entry's text taken at an address (its own when none is given) for a copy of this shelfmark (the copy's when
     * none is given): the extractor's result, or null when the source has none or the pages do not show the copy
     * there. Where the entry's own text was taken, its entry number tells the entries of a page apart; a shelfmark
     * that differs from the copy's is a correction, which names its treatise strictly.
     */
    public static function extract(SourceEntry $entry, ?int $page = null, ?string $shelfmark = null, ?bool $strictPart = null): ?array
    {
        $extractor = self::for($entry->source?->extractor);
        $own = $entry->citable?->shelfmark;
        $page ??= $entry->page_start;
        $shelfmark ??= $own;
        if (! $extractor || ! $page || ! $entry->source_volume_id || blank($shelfmark)) {
            return null;
        }
        $samePlace = $page === $entry->text_page && $entry->text_status !== SourceEntry::TEXT_STALE;
        $hints = ['strict_part' => $strictPart ?? (string) $shelfmark !== (string) $own]
            + ($samePlace ? ['entry_number' => $entry->entry_number, 'shelfmark_page' => $entry->shelfmark_page] : []);

        return $extractor->extract(self::pages($entry->source_volume_id), (string) $shelfmark, $page, $hints);
    }

    /** @return array<int, string> */
    public static function pages(int $volumeId): array
    {
        return self::$pages[$volumeId] ??= DB::table('source_pages')->where('source_volume_id', $volumeId)->orderBy('page')->pluck('text', 'page')->all();
    }

    /** A few pages of a volume for the reader, from $from to $to. */
    public static function pageRange(int $volumeId, int $from, int $to): array
    {
        return DB::table('source_pages')->where('source_volume_id', $volumeId)->whereBetween('page', [$from, $to])->orderBy('page')->pluck('text', 'page')->all();
    }

    public static function forget(): void
    {
        self::$pages = [];
    }
}
