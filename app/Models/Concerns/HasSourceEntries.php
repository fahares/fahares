<?php

namespace App\Models\Concerns;

use App\Models\SourceEntry;
use App\Support\RecordSources;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Where a record stands in the sources (step 13.2): its Fankha entry, the library catalogue pages, the addenda and
 * the bibliography, grouped for the record page by sources(). Load RecordSources::RELATIONS with the record.
 */
trait HasSourceEntries
{
    public function sourceEntries(): MorphMany
    {
        return $this->morphMany(SourceEntry::class, 'citable')->orderBy('sort_order')->orderBy('id');
    }

    public function sources(): RecordSources
    {
        return new RecordSources($this->sourceEntries);
    }
}
