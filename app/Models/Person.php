<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'century_hijri' => 'integer',
            'death_year_gregorian' => 'integer',
            'is_author' => 'boolean',
            'is_scribe' => 'boolean',
            'is_translator' => 'boolean',
            'is_donor' => 'boolean',
            'works_count' => 'integer',
            'manuscripts_count' => 'integer',
        ];
    }

    public function authoredWorks(): HasMany
    {
        return $this->hasMany(Work::class, 'author_id');
    }

    public function scribedManuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class, 'scribe_id');
    }
}
