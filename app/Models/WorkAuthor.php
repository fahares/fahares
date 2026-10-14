<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the work_author list: a linked person, or only the name as the catalog gives it
 * when the person cannot be identified (person_id null).
 */
class WorkAuthor extends Model
{
    protected $table = 'work_author';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
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
