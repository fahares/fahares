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

    public function getPersianSlugAttribute(): string
    {
        $name = $this->full_name ?: ($this->name . ($this->city ? ' ' . $this->city : ''));
        $slug = \Illuminate\Support\Str::slug($name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'library';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function catalogVolumes(): HasMany
    {
        return $this->hasMany(CatalogVolume::class);
    }
}
