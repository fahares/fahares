<?php

namespace App\Models\Concerns;

use App\Models\SourceEntry;
use App\Sources\RecordEntries;
use App\Sources\SourceEntrySync;
use App\Support\RecordSources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Where a record stands in the sources (step 13.2): its Fankha entry, the library catalogue pages, the addenda and
 * the bibliography, grouped for the record page by sources(). Load RecordSources::RELATIONS with the record.
 *
 * The entries are the record of truth (backlog item 170): RecordEntries writes them and the record's columns follow.
 * A writer that still saves those columns has its changes taken into the entries on save. A deleted record keeps its
 * entries, so the rollback that restores it under its id finds them again.
 */
trait HasSourceEntries
{
    public static function bootHasSourceEntries(): void
    {
        // A writer that still sets the record's source columns (step 14.396): what it changed goes into the entries
        // through RecordEntries, which keeps what else they hold (a review, a text, a tie), and the columns follow
        static::saved(function ($record) {
            // a library has no source columns of its own: its entries are written as entries (step 14.405)
            if (! $record instanceof \App\Models\Work && ! $record instanceof \App\Models\Manuscript) {
                return;
            }
            $created = $record->wasRecentlyCreated;
            // a record made without catalog_id has the column's default (Fankha), which the model has not read back
            if ($created && ! array_key_exists('catalog_id', $record->getAttributes())) {
                $record->setAttribute('catalog_id', \Illuminate\Support\Facades\DB::table($record->getTable())->where('id', $record->id)->value('catalog_id'));
                $record->syncOriginalAttributes(['catalog_id']);
            }
            $fankha = [];
            foreach (RecordEntries::COLUMNS as $column => $key) {
                if ($created || $record->wasChanged($column)) {
                    $fankha[$key] = $record->getAttribute($column);
                }
            }
            $touched = false;
            if ($fankha && ($created ? $fankha['source_id'] ?? null : true)) {
                RecordEntries::fankha($record, $fankha);
                $touched = true;
            }
            if ($record instanceof \App\Models\Work && ($created || $record->wasChanged('bibliography'))) {
                RecordEntries::bibliography($record, SourceEntrySync::bibliographyStrings($record->getAttributes()['bibliography'] ?? null));
                $touched = true;
            }
            if ($record instanceof \App\Models\Manuscript && ($created || $record->wasChanged(['metadata', 'catalog_volume_id', 'raw_text']))) {
                // the row as stored: a model just made holds only the columns it was given
                SourceEntrySync::syncCited([\Illuminate\Support\Facades\DB::table('manuscripts')->where('id', $record->id)->first(['id', 'metadata', 'raw_text', 'catalog_volume_id'])]);
                $touched = true;
            }
            // the part and the cataloger a writer set on the copy go onto its citation entry (step 14.401)
            if ($record instanceof \App\Models\Manuscript && ! $created && $record->wasChanged(['catalog_volume_part_id', 'cataloger_id'])) {
                SourceEntrySync::syncCatalogue([$record->id]);
                $touched = true;
            }
            $touched && $record->unsetRelation('sourceEntries');
        });
    }

    public function sourceEntries(): MorphMany
    {
        return $this->morphMany(SourceEntry::class, 'citable')->orderBy('sort_order')->orderBy('id');
    }

    public function sources(): RecordSources
    {
        return new RecordSources($this->sourceEntries);
    }

    /**
     * The record's own entry in Fankha (backlog item 170, step 14.399): its place (source_id, volume_number,
     * page_start, page_end, a copy's row as entry_number) and its text. The record's columns of the same names are a
     * copy that goes in phase 5; read this instead. Eager load it for a list.
     */
    public function fankhaEntry(): MorphOne
    {
        return $this->morphOne(SourceEntry::class, 'citable')->whereIn('legacy_type', ['work', 'manuscript']);
    }

    /** The text of the record's Fankha entry: a copy's own line, or a work's entry. */
    public function fankhaText(): ?string
    {
        $entry = $this->fankhaEntry;

        return $entry ? ($this instanceof \App\Models\Manuscript ? $entry->copy_text : $entry->entry_text) : null;
    }

    /**
     * Joins each record's Fankha entry as «fe» (a record of no Fankha place keeps its row), for a query that filters,
     * orders or selects by the Fankha place or text: fe.volume_number, fe.page_start, fe.entry_number, fe.copy_text …
     */
    public function scopeJoinFankha(Builder $query, string $alias = 'fe'): Builder
    {
        $table = $this->getTable();
        $legacy = $this instanceof \App\Models\Manuscript ? 'manuscript' : 'work';
        if (! $query->getQuery()->columns) {
            $query->select("{$table}.*");
        }

        return $query->leftJoin("source_entries as {$alias}", fn ($j) => $j->on("{$alias}.legacy_id", '=', "{$table}.id")->where("{$alias}.legacy_type", $legacy));
    }

    /**
     * Orders by a column of the record's Fankha entry through a subquery, for a query that cannot take a join
     * (unqualified columns, a relation's own constraints); a record with no entry sorts as null.
     */
    public function scopeOrderByFankhaColumn(Builder $query, string $column, string $direction = 'asc'): Builder
    {
        $legacy = $this instanceof \App\Models\Manuscript ? 'manuscript' : 'work';
        $value = \Illuminate\Support\Facades\DB::table('source_entries')->select($column)
            ->whereColumn('legacy_id', $this->getTable() . '.id')->where('legacy_type', $legacy)->limit(1);
        // a record of no Fankha place (a copy made in the panel) comes after Fankha's, in the order it was made (step 14.405)
        $query->orderByRaw('(' . $value->toSql() . ') is null', $value->getBindings());

        return $query->orderBy($value, $direction);
    }

    /** Fankha's order: volume, page, and a copy's row in the work's entry. */
    public function scopeOrderByFankha(Builder $query, string $direction = 'asc', string $alias = 'fe'): Builder
    {
        return $query->joinFankha($alias)->orderBy("{$alias}.volume_number", $direction)->orderBy("{$alias}.page_start", $direction)
            ->orderBy("{$alias}.entry_number", $direction);
    }
}
