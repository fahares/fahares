<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Catalog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volumes_count' => 'integer',
        ];
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }
}
