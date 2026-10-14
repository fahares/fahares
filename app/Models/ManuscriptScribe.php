<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the manuscript_scribe list: a linked person, or only the name as the catalog gives it
 * when the person cannot be identified (person_id null).
 */
class ManuscriptScribe extends Model
{
    protected $table = 'manuscript_scribe';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->person?->name ?? (string) $this->name;
    }
}
