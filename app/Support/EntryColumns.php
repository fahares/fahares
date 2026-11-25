<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A query-builder read of works or manuscripts whose rows carry the Fankha entry's values under the old column names
 * (backlog item 170, step 14.399): the code after the query keeps reading raw_text, volume_number, page_start,
 * page_end, catalog_id, sequence_number and catalog_citation, while the values come from the entries, not the
 * columns that go in phase 5. A record with no Fankha entry reads null.
 */
class EntryColumns
{
    private const MANUSCRIPT = ['raw_text' => 'fe.copy_text', 'catalog_id' => 'fe.source_id', 'volume_number' => 'fe.volume_number',
        'page_start' => 'fe.page_start', 'page_end' => 'fe.page_end', 'sequence_number' => 'fe.entry_number', 'catalog_citation' => 'ce.citation_text',
        // the catalogue volume, its part and the cataloger of the copy's citation entry (step 14.402)
        'catalog_volume_id' => 'ce.source_volume_id', 'catalog_volume_part_id' => 'ce.catalog_volume_part_id', 'cataloger_id' => 'ce.cataloger_id'];

    private const WORK = ['raw_text' => 'fe.entry_text', 'catalog_id' => 'fe.source_id', 'volume_number' => 'fe.volume_number',
        'page_start' => 'fe.page_start', 'page_end' => 'fe.page_end'];

    /** Rows of the table (works or manuscripts) with the columns asked for, an entry value under its old name. */
    public static function query(string $table, array $columns): Builder
    {
        return self::join(DB::table($table), $table)->select(self::select($table, $columns));
    }

    /** The select list: the old names of entry values aliased, any other column qualified by the table. */
    public static function select(string $table, array $columns): array
    {
        $map = $table === 'manuscripts' ? self::MANUSCRIPT : self::WORK;

        return array_map(fn (string $c) => isset($map[$c]) ? "{$map[$c]} as {$c}" : (str_contains($c, '.') || str_contains($c, ' ') || $c === '*' ? $c : "{$table}.{$c}"), $columns);
    }

    /** The SQL expression of an old column name: fe.copy_text for raw_text, and so on; the table's own column otherwise. */
    public static function column(string $table, string $name): string
    {
        return ($table === 'manuscripts' ? self::MANUSCRIPT : self::WORK)[$name] ?? "{$table}.{$name}";
    }

    /** Joins the record's Fankha entry as «fe» and, for a copy, the citation Fankha gives of its catalogue place as «ce». */
    public static function join(Builder $query, string $table): Builder
    {
        $legacy = $table === 'manuscripts' ? 'manuscript' : 'work';
        $query->leftJoin('source_entries as fe', fn ($j) => $j->on('fe.legacy_id', '=', "{$table}.id")->where('fe.legacy_type', $legacy));

        return $table === 'manuscripts' ? self::citation($query) : $query;
    }

    /** Joins only the copy's citation entry as «ce», for a query on the copy's catalogue volume, part or cataloger (step 14.402). */
    public static function citation(Builder $query): Builder
    {
        return $query->leftJoin('source_entries as ce', fn ($j) => $j->on('ce.legacy_id', '=', 'manuscripts.id')->where('ce.legacy_type', 'fankha_citation')->where('ce.legacy_index', 0));
    }

    /** How many copies a catalogue volume, part or cataloger has, by the copies' citation entries ($column: catalog_volume_id, catalog_volume_part_id or cataloger_id). */
    public static function copiesOf(string $column, int $id): int
    {
        return self::cited()->where(self::MANUSCRIPT[$column], $id)->count();
    }

    /** The copies with their citation entry joined: filter or count them by ce.source_volume_id, ce.catalog_volume_part_id, ce.cataloger_id. */
    public static function cited(): Builder
    {
        return self::citation(DB::table('manuscripts'));
    }
}
