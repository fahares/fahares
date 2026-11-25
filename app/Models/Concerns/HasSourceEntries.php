<?php

namespace App\Models\Concerns;

use App\Models\SourceEntry;
use App\Sources\RecordEntries;
use App\Sources\SourceEntrySync;
use App\Support\RecordSources;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
}
