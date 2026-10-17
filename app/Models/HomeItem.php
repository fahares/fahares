<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An image the admin places on the home page: the header banner (only the first active one is
 * shown), a slide of the carousel below the search box, or one of the «از گنجینه‌ها» pictures.
 * Images live on the public disk, which is the persistent volume in production.
 */
class HomeItem extends Model
{
    public const SECTIONS = [
        'hero' => 'تصویر سربرگ',
        'slides' => 'اسلایدها',
        'treasures' => 'از گنجینه‌ها',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'show_caption' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getImageUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image_path) . '?v=' . $this->updated_at?->timestamp;
    }
}
