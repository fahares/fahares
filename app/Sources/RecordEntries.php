<?php

namespace App\Sources;

use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of a record's Fankha entry (backlog item 170, step 14.396). The entry is the record of truth: a
 * writer gives the place or the text, the entry takes it, and the record's own columns (catalog_id, volume_number,
 * page_start, page_end, raw_text, a copy's sequence_number) follow as a copy, written by mirror() without the saved
 * hook. Those columns stay only until the readers move to the entries (phase 2) and go in phase 5.
 */
class RecordEntries
{
    /** What a writer may give: the source (Fankha's catalog), the place, a copy's row in the work's entry, the text. */
    public const FANKHA = ['source_id', 'volume_number', 'page_start', 'page_end', 'entry_number', 'text'];

    /** The columns of the entry a writer of the place or the text changes; review, match and the rest are kept. */
    private const PLACE_COLUMNS = ['source_id', 'source_volume_id', 'volume_number', 'page_start', 'page_end', 'entry_number', 'role',
        'text_volume_number', 'text_page', 'text_status'];

    public static function legacyType(Model $record): string
    {
        return $record instanceof Manuscript ? 'manuscript' : 'work';
    }

    /** The entry's column of the Fankha text: a copy's own line, or a work's entry. */
    public static function textColumn(Model $record): string
    {
        return $record instanceof Manuscript ? 'copy_text' : 'entry_text';
    }

    /** The record's Fankha entry as stored, or null. */
    public static function primary(Model $record): ?object
    {
        return DB::table('source_entries')->where('legacy_type', self::legacyType($record))->where('legacy_id', $record->getKey())->first();
    }

    /** The record's Fankha values as the entry holds them (self::FANKHA keys), all null when it has none. */
    public static function fankhaOf(Model $record): array
    {
        $held = self::primary($record);
        if (! $held) {
            return array_fill_keys(self::FANKHA, null);
        }

        return [
            'source_id' => $held->source_id !== null ? (int) $held->source_id : null,
            'volume_number' => $held->volume_number !== null ? (int) $held->volume_number : null,
            'page_start' => $held->page_start !== null ? (int) $held->page_start : null,
            'page_end' => $held->page_end !== null ? (int) $held->page_end : null,
            'entry_number' => $held->entry_number !== null ? (int) $held->entry_number : null,
            'text' => $held->{self::textColumn($record)},
        ];
    }

    /**
     * Writes the record's Fankha entry: the values given over those it has. A null source removes the entry (a record
     * of no Fankha place). The text stands where the entry does. Returns the values before and after, for a log.
     *
     * @param  array<string, mixed>  $values  keys of self::FANKHA
     * @return array{0: array, 1: array}
     */
    public static function fankha(Model $record, array $values): array
    {
        $before = self::fankhaOf($record);
        $after = array_merge($before, array_intersect_key($values, array_flip(self::FANKHA)));
        $held = self::primary($record);

        if (! $after['source_id']) {
            $held && DB::table('source_entries')->where('id', $held->id)->delete();
            self::mirror($record);

            return [$before, array_fill_keys(self::FANKHA, null)];
        }

        $sourceId = (int) $after['source_id'];
        SourceEntrySync::ensureCatalogSource($sourceId);
        $volumeIds = SourceEntrySync::volumeIds([$sourceId]);
        $codes = DB::table('catalogs')->where('id', $sourceId)->pluck('code', 'id')->all();
        $row = SourceEntrySync::row(SourceEntrySync::primaryValues((object) [
            'id' => $record->getKey(),
            'catalog_id' => $sourceId,
            'volume_number' => $after['volume_number'],
            'page_start' => $after['page_start'],
            'page_end' => $after['page_end'],
            'raw_text' => $after['text'],
        ], $record::class, $volumeIds, $codes), $record->created_at);
        $row['entry_number'] = $after['entry_number'];

        if ($held) {
            $text = self::textColumn($record);
            DB::table('source_entries')->where('id', $held->id)
                ->update(array_intersect_key($row, array_flip([...self::PLACE_COLUMNS, $text])) + ['updated_at' => now()]);
        } else {
            SourceEntrySync::upsert([$row]);
        }
        self::mirror($record);

        return [$before, self::fankhaOf($record)];
    }

    /** The record's columns of its Fankha place and text, by the key of self::FANKHA they stand for. */
    public const COLUMNS = ['catalog_id' => 'source_id', 'volume_number' => 'volume_number', 'page_start' => 'page_start',
        'page_end' => 'page_end', 'sequence_number' => 'entry_number', 'raw_text' => 'text'];

    /**
     * Puts back the Fankha columns an audit log kept (a suggestion's or an edit's old values) through the entry, and
     * returns the other values for the caller to restore on the record.
     */
    public static function restore(Model $record, array $old): array
    {
        if (! $record instanceof Work && ! $record instanceof Manuscript) {
            return $old;
        }
        $fankha = [];
        foreach (self::COLUMNS as $column => $key) {
            if (array_key_exists($column, $old)) {
                $fankha[$key] = $old[$column];
                unset($old[$column]);
            }
        }
        $fankha && self::fankha($record, $fankha);

        return $old;
    }

    /**
     * Copies the record's Fankha entry into its own columns, by the query builder (the saved hook does not run) and
     * into the model, whose other unsaved changes stay unsaved.
     */
    public static function mirror(Model $record): void
    {
        $v = self::fankhaOf($record);
        $columns = [
            'catalog_id' => $v['source_id'],
            'volume_number' => $v['volume_number'],
            'page_start' => $v['page_start'],
            'page_end' => $v['page_end'],
            'raw_text' => $v['text'],
        ];
        // a copy's row in the work's entry; a copy of no Fankha place keeps the number it has
        if ($record instanceof Manuscript && $v['entry_number'] !== null) {
            $columns['sequence_number'] = $v['entry_number'];
        }
        DB::table($record->getTable())->where('id', $record->getKey())->update($columns);
        foreach ($columns as $column => $value) {
            $record->setAttribute($column, $value);
        }
        $record->syncOriginalAttributes(array_keys($columns));
    }
}
