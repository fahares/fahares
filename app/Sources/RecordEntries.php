<?php

namespace App\Sources;

use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of a record's source entries (backlog item 170, step 14.396): its Fankha entry (place, text, a copy's
 * row), a work's bibliography and a copy's citation entry. Since step 14.407 the record has no columns of these; a
 * writer that gives them under their old names on a model has them taken here on save (take()).
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
        $held = self::primary($record);
        $before = self::fankhaOf($record);
        $after = array_merge($before, array_intersect_key($values, array_flip(self::FANKHA)));

        if (! $after['source_id']) {
            $held && DB::table('source_entries')->where('id', $held->id)->delete();

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

        return [$before, self::fankhaOf($record)];
    }

    /**
     * Writes the source values a writer gave a work or a copy under their old names (HasSourceEntries, step 14.407):
     * the Fankha place and text, a work's bibliography, a copy's catalogue volume, part and cataloger. A record made
     * without a source is Fankha's, as the column's default made it; one made with none (a copy made in the panel)
     * has no Fankha entry.
     */
    public static function take(Model $record, array $given, bool $created): void
    {
        $fankha = [];
        foreach (self::COLUMNS as $column => $key) {
            if (array_key_exists($column, $given)) {
                $fankha[$key] = $given[$column];
            }
        }
        if ($created && ! array_key_exists('source_id', $fankha)) {
            $fankha['source_id'] = self::fankhaSourceId();
        }
        if ($fankha && (! $created || $fankha['source_id'])) {
            self::fankha($record, $fankha);
        }
        if ($record instanceof Work && ($created || array_key_exists('bibliography', $given))) {
            self::bibliography($record, SourceEntrySync::bibliographyStrings($given['bibliography'] ?? null));
        }
        if ($record instanceof Manuscript) {
            self::citation($record, $given, $created);
        }
    }

    /**
     * Inserts a record's row back under its id, from the attributes an audit log kept (a removal or a merge). A log
     * from before step 14.407 holds the source values under their old names; they are no columns, and the record's
     * entries outlive its deletion, so they are taken in only where the record has none.
     */
    public static function insertRow(string $class, array $attributes): void
    {
        $class === Manuscript::class && $attributes = self::liftCitation($attributes);
        $source = defined($class . '::SOURCE_ATTRIBUTES') && $class !== \App\Models\Library::class ? array_intersect_key($attributes, array_flip($class::SOURCE_ATTRIBUTES)) : [];
        $row = array_diff_key($attributes, $source);
        $table = (new $class)->getTable();
        DB::table($table)->insert(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $row));
        $record = (new $class)->forceFill(['id' => $attributes['id']]);
        if ($source && ! self::primary($record)) {
            self::take($record, $source, false);
        }
    }

    /**
     * Writes a payload with the query builder, as the commands that skip the model hooks do: the columns on the record's
     * row, the source values under their old names (HasSourceEntries::SOURCE_ATTRIBUTES) into its entries (step 14.407).
     */
    public static function write(Model $record, array $payload): void
    {
        $record instanceof Manuscript && $payload = self::liftCitation($payload);
        $source = array_intersect_key($payload, array_flip($record::SOURCE_ATTRIBUTES));
        $columns = array_diff_key($payload, $source);
        $columns && DB::table($record->getTable())->where('id', $record->getKey())->update($columns);
        $source && self::take($record, $source, false);
    }

    /** Fankha's source (catalogs.id = sources.id), the one a record is made in when none is named. */
    public static function fankhaSourceId(): ?int
    {
        static $id = null;

        return $id ??= DB::table('catalogs')->where('code', 'fankha')->value('id');
    }

    /**
     * A copy's metadata gives up its catalog_citation (step 14.413, backlog item 170): Fankha's citation of the copy's
     * catalogue place is held by the copy's citation entry (citation_text), and a writer that still gives it in the
     * metadata (the corpus's export, an old audit log, a merge of metadata maps) gives it as the source value
     * catalog_citation. The metadata keeps its form: an array, a JSON string or null.
     */
    public static function liftCitation(array $values): array
    {
        $metadata = $values['metadata'] ?? null;
        $map = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        if (! is_array($map) || ! array_key_exists('catalog_citation', $map)) {
            return $values;
        }
        array_key_exists('catalog_citation', $values) || $values['catalog_citation'] = $map['catalog_citation'];
        unset($map['catalog_citation']);
        $values['metadata'] = $map === [] ? null : (is_string($metadata) ? json_encode($map, JSON_UNESCAPED_UNICODE) : $map);

        return $values;
    }

    /**
     * A copy's citation entry: Fankha's citation of its catalogue place (the citation given, else the one the entry
     * holds, else the last bracket of the copy's text) at the catalogue volume given or held, then the part and the
     * cataloger given.
     */
    private static function citation(Manuscript $copy, array $given, bool $created): void
    {
        $held = DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->getKey())->where('legacy_index', 0)->first();
        if ($created || array_key_exists('catalog_citation', $given) || array_key_exists('catalog_volume_id', $given) || array_key_exists('raw_text', $given)) {
            $citation = array_key_exists('catalog_citation', $given) ? $given['catalog_citation'] : $held?->citation_text;
            SourceEntrySync::syncCited([(object) [
                'id' => $copy->getKey(),
                'library_id' => $copy->library_id,
                'catalog_citation' => is_string($citation) ? $citation : null,
                'raw_text' => self::fankhaOf($copy)['text'],
                'catalog_volume_id' => array_key_exists('catalog_volume_id', $given) ? $given['catalog_volume_id'] : $held?->source_volume_id,
            ]]);
        }
        $catalogue = array_intersect_key($given, array_flip(['catalog_volume_part_id', 'cataloger_id']));
        if ($catalogue) {
            DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->getKey())->where('legacy_index', 0)
                ->update($catalogue);
            // as the columns held them before the citation was read: the copy's part, if its volume has parts, has the last word
            \App\Models\CatalogVolumePart::assign([$copy->getKey()]);
        }
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
        if ($record instanceof Work && array_key_exists('bibliography', $old)) {
            self::bibliography($record, SourceEntrySync::bibliographyStrings($old['bibliography']));
            unset($old['bibliography']);
        }

        return $old;
    }

    /** A work's bibliography as its entries hold it: the strings in their order. */
    public static function bibliographyOf(Work $work): array
    {
        return DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->getKey())
            ->orderBy('legacy_index')->pluck('citation_text')->all();
    }

    /** The columns of a bibliography entry its string gives; a text taken there, a tie and a review are kept. */
    private const CITATION_COLUMNS = ['source_id', 'source_volume_id', 'volume_number', 'page_start', 'role', 'citation_text', 'locations',
        'origin', 'sort_order'];

    /**
     * Writes a work's bibliography, one entry per string in order (step 14.396): a string changed at its index
     * rewrites that entry's citation and places, and marks a text taken at a place the entry has left; strings past
     * the new end go. The work's bibliography column follows.
     *
     * @param  list<string>  $strings
     */
    public static function bibliography(Work $work, array $strings): void
    {
        $strings = SourceEntrySync::bibliographyStrings($strings);
        $sources = SourceEntrySync::configSources();
        $values = SourceEntrySync::bibliographyValues($work->getKey(), $strings, $sources, SourceEntrySync::volumeIds(array_values(array_filter($sources))));
        $held = DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->getKey())->get()->keyBy('legacy_index');
        $new = [];
        foreach ($values as $i => $v) {
            $entry = $held[$i] ?? null;
            if (! $entry) {
                $new[] = SourceEntrySync::row($v, $work->created_at);

                continue;
            }
            $update = array_intersect_key($v, array_flip(self::CITATION_COLUMNS));
            $update += array_fill_keys(array_diff(self::CITATION_COLUMNS, array_keys($update)), null);
            $moved = [(int) $entry->volume_number, (int) $entry->page_start, (int) $entry->source_id] !== [(int) $update['volume_number'], (int) $update['page_start'], (int) $update['source_id']];
            if ($moved && (filled($entry->entry_text) || filled($entry->copy_text)) && $entry->text_status !== SourceEntry::TEXT_STALE) {
                $update['text_status'] = SourceEntry::TEXT_STALE;
            }
            $resolved = (int) $entry->source_volume_id !== (int) $update['source_volume_id'] || $entry->locations != $update['locations'];
            if ($entry->citation_text !== $update['citation_text'] || $moved || $resolved || isset($update['text_status'])) {
                DB::table('source_entries')->where('id', $entry->id)->update($update + ['updated_at' => now()]);
            }
        }
        $new && SourceEntrySync::upsert($new);
        DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->getKey())
            ->where('legacy_index', '>=', count($strings))->delete();
    }
}
