<?php

namespace App\Sources;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Sources\Extractors\Extractors;
use App\Suggestions\SuggestionRegistry;

/**
 * Taking an entry's printed text again when its address or the copy's shelfmark is corrected (step 13.6): the
 * reviewer sees the text at the new place beside the one the entry has, and approves the correction with it or
 * without. Only sources with an extractor and page texts are read (Extractors).
 */
class TextRefresh
{
    /** The columns a new text changes, and a rollback puts back. */
    public const COLUMNS = ['entry_text', 'copy_text', 'text_volume_number', 'text_page', 'text_status', 'page_start', 'page_end', 'shelfmark_page', 'entry_number'];

    /**
     * The entries a suggestion's value would move, each with the text at its new place (null when the pages do not
     * show the copy there): an address puts its own entry on a new page; a shelfmark makes every readable entry
     * of the copy another copy, or another treatise of a composite volume.
     *
     * @return list<array{entry: SourceEntry, text: ?array}>
     */
    public static function candidates(FieldSuggestion $suggestion, ?string $value): array
    {
        $field = $suggestion->fieldDefinition();
        $record = $suggestion->suggestable;
        if (! $field || ! $record || blank($value)) {
            return [];
        }
        if (isset($field['entry_id'])) {
            $entry = SourceEntry::with(['source', 'sourceVolume', 'citable'])->find($field['entry_id']);
            $page = SuggestionRegistry::parseCatalogPage($value);
            if (! $entry || ! $page || ! Extractors::canRead($entry)) {
                return [];
            }

            return [['entry' => $entry, 'text' => Extractors::extract($entry, $page['page'])]];
        }
        if ($field['name'] === 'shelfmark' && $record instanceof Manuscript) {
            return $record->sources()->cards()->filter(fn ($e) => ! $e->is_primary && Extractors::canRead($e))
                ->map(fn ($e) => ['entry' => $e, 'text' => self::find($e, $value)])->values()->all();
        }

        return [];
    }

    /**
     * Writes the text found at the entry's place for the copy's present shelfmark; returns the columns it had, for
     * the audit log, or null when the pages do not show the copy there.
     */
    public static function apply(SourceEntry $entry, ?int $page = null, ?string $shelfmark = null): ?array
    {
        $entry->loadMissing(['source', 'sourceVolume', 'citable']);
        $text = $page ? Extractors::extract($entry, $page, $shelfmark) : self::find($entry, $shelfmark);
        if (! $text) {
            return null;
        }
        $old = $entry->only(self::COLUMNS);
        $entry->forceFill([
            'entry_text' => $text['entry_text'],
            'copy_text' => $text['copy_text'],
            'text_volume_number' => $entry->volumeNumber(),
            'text_page' => $text['page_start'],
            'text_status' => SourceEntry::TEXT_AUTO,
            'page_start' => $text['page_start'],
            'page_end' => $text['page_end'],
            'shelfmark_page' => $text['shelfmark_page'],
            'entry_number' => $text['entry_number'],
        ])->save();

        return ['id' => $entry->id] + array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $old);
    }

    /**
     * The text for a shelfmark near the entry's page: another treatise of a composite volume may begin a few pages on.
     * The shelfmark's part is strict here, so a page of the same volume never gives another of its treatises.
     */
    public static function find(SourceEntry $entry, ?string $shelfmark = null): ?array
    {
        $from = (int) $entry->page_start;
        foreach ([$from, ...range($from + 1, $from + 4), max(1, $from - 1)] as $page) {
            if ($text = Extractors::extract($entry, $page, $shelfmark, true)) {
                return $text;
            }
        }

        return null;
    }
}
