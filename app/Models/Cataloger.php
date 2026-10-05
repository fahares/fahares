<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Cataloger extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_year_solar' => 'integer',
            'death_year_solar' => 'integer',
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'is_alive' => 'boolean',
            'manuscripts_count' => 'integer',
            'volumes_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getPersianSlugAttribute(): string
    {
        $slug = Str::slug($this->name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'cataloger';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name;
    }

    public function getLifeYearsTextAttribute(): ?string
    {
        if ($this->birth_year_solar && $this->death_year_solar) {
            return "{$this->birth_year_solar} – {$this->death_year_solar} هـ.ش";
        }

        if ($this->death_year_solar) {
            return "وفات {$this->death_year_solar} هـ.ش";
        }

        if ($this->birth_year_solar) {
            return "متولد {$this->birth_year_solar} هـ.ش";
        }

        if ($this->death_year_hijri) {
            return "وفات {$this->death_year_hijri} هـ.ق";
        }

        return null;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path) {
            if (str_starts_with($this->avatar_path, 'http://') || str_starts_with($this->avatar_path, 'https://')) {
                return $this->avatar_path;
            }
            if (file_exists(public_path('images/' . ltrim($this->avatar_path, '/')))) {
                return asset('images/' . ltrim($this->avatar_path, '/'));
            }
            return asset('storage/' . ltrim($this->avatar_path, '/'));
        }

        if ($this->mtif_entry_id && file_exists(public_path("images/catalogers/avatars/{$this->mtif_entry_id}.jpg"))) {
            return asset("images/catalogers/avatars/{$this->mtif_entry_id}.jpg");
        }

        return null;
    }

    public function catalogVolumes(): BelongsToMany
    {
        return $this->belongsToMany(CatalogVolume::class, 'catalog_volume_cataloger')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
