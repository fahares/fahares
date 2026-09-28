<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page' => 'integer',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function targetWork(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'target_work_id');
    }
}
