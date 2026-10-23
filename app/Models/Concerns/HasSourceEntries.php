<?php

namespace App\Models\Concerns;

use App\Models\SourceEntry;
use App\Sources\SourceEntrySync;
use App\Support\RecordSources;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Where a record stands in the sources (step 13.2): its Fankha entry, the library catalogue pages, the addenda and
 * the bibliography, grouped for the record page by sources(). Load RecordSources::RELATIONS with the record.
 *
 * Saving the record's Fankha columns or a work's bibliography brings its entries in line (step 13.3). A deleted
 * record keeps its entries, so the rollback that restores it under its id finds them again.
 */
trait HasSourceEntries
{
    public static function bootHasSourceEntries(): void
    {
        static::saved(function ($record) {
            $columns = [...SourceEntrySync::RECORD_COLUMNS, ...($record instanceof \App\Models\Work ? ['bibliography'] : [])];
            if ($record->wasRecentlyCreated || $record->wasChanged($columns)) {
                SourceEntrySync::syncRecord($record);
                $record->unsetRelation('sourceEntries');
            }
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
