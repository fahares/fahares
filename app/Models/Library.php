<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Library extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'manuscripts_count' => 'integer',
        ];
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }
}
