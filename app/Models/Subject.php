<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'works_count' => 'integer',
        ];
    }

    public function getTitleAttribute(): string
    {
        return $this->name ?? '';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Subject::class, 'parent_id');
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class);
    }
}
