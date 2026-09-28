<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Script extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'manuscripts_count' => 'integer',
        ];
    }

    public function manuscripts(): BelongsToMany
    {
        return $this->belongsToMany(Manuscript::class)->withPivot('is_primary');
    }
}
