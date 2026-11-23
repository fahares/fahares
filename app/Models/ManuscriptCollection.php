<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A «مجموعه»: the bound volume several treatises (copies) of one library are in (backlog item 163, step 14.322).
 * It is found by its library and key, the copies' collection_key; App\Support\Collections makes it, links its
 * copies and removes it when it is left with none and nothing of its own.
 */
class ManuscriptCollection extends Model
{
    public const BOUND = 'bound';

    /** The volumes of one work under one number («13/1» to «13/5»): each has its own binding and leaves */
    public const SET = 'set';

    protected $table = 'collections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['basis' => 'array', 'metadata' => 'array', 'copies_count' => 'integer', 'folios' => 'integer', 'lines' => 'integer'];
    }

    public function libraryRecord(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

    public function scribe(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scribe_id');
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class, 'collection_id');
    }

    /** Whether something other than its copies' shelfmarks keeps the record: a value an editor or a catalogue gave */
    public function hasOwnData(): bool
    {
        return (bool) array_intersect(array_values($this->basis ?? []), ['editor', 'catalogue']) || ! empty($this->metadata);
    }
}
