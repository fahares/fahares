<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Language extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'works_count' => 'integer',
        ];
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class);
    }
}
