<?php

namespace App\Sources;

/**
 * One copy with two Fankha citation entries (origin fankha_citation) of the same text (backlog item 178, step 14.455).
 * A merge of two records of one copy moved the removed record's citation to the kept one, without its legacy_type; when
 * the kept record's own citation has the same text, the moved one is a repeat. The catalogue commands and
 * SourceEntrySync keep only the entries with legacy_type up to date, so the repeat keeps an older volume or page.
 *
 * Shared by RecordMerger, which no longer moves such a repeat, and sources:drop-repeated-citations, which removes the
 * repeats earlier merges left.
 */
final class RepeatedCitations
{
    /** What an entry may hold beyond its place; a repeat holding any of these the kept entry lacks is kept. */
    public const DATA = ['entry_text', 'copy_text', 'notes', 'locations', 'confirmed_fields', 'source_item_id', 'reviewed_by'];

    /** The entry's place in the catalogue. */
    public const PLACE = ['source_id', 'source_volume_id', 'catalog_volume_part_id', 'volume_number', 'page_start', 'page_end',
        'entry_number', 'shelfmark_page', 'cataloger_id'];

    /** The text compared: the citation as printed, trimmed. */
    public static function key(?string $text): string
    {
        return trim((string) $text);
    }

    /** The first field of $repeat's data that $kept does not hold, or null when it holds all of it. */
    public static function adds(object|array $repeat, object|array $kept): ?string
    {
        $repeat = (array) $repeat;
        $kept = (array) $kept;
        foreach (self::DATA as $field) {
            if (! self::blank($repeat[$field] ?? null) && (string) $repeat[$field] !== (string) ($kept[$field] ?? '')) {
                return $field;
            }
        }
        // a reviewed repeat is a decision of its own
        if (($repeat['status'] ?? 'unverified') !== 'unverified' && ($repeat['status'] ?? null) !== ($kept['status'] ?? null)) {
            return 'status';
        }

        return null;
    }

    /** The place fields where $repeat gives another value than $kept (a field $repeat leaves empty agrees). */
    public static function otherPlace(object|array $repeat, object|array $kept): array
    {
        $repeat = (array) $repeat;
        $kept = (array) $kept;

        return array_values(array_filter(self::PLACE, fn ($field) => ! self::blank($repeat[$field] ?? null)
            && (string) $repeat[$field] !== (string) ($kept[$field] ?? '')));
    }

    private static function blank($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
