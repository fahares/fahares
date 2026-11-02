<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One printed page's text of a source (step 13.95), the target of a link to a page as a whole (step 13.155). */
class SourcePage extends Model
{
    protected $guarded = ['id'];

    public function volume(): BelongsTo
    {
        return $this->belongsTo(SourceVolume::class, 'source_volume_id');
    }

    public function url(): string
    {
        return route('source-pages.show', [$this->volume->source->code, $this->volume->volume_number, $this->page]);
    }
}
