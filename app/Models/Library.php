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
            'aliases' => 'array',
        ];
    }

    // Other names are matched with LIKE, so Persian text is stored as is, not as \u escapes
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Manuscripts carry a copy of the library's name and city (shown in lists and searched in the
     * manuscripts index); after a rename they are brought in step and re-indexed.
     */
    public function syncManuscriptCopies(): void
    {
        $this->manuscripts()->where(fn ($q) => $q->where('library', '!=', $this->name)->orWhereNull('library')->orWhere('city', '!=', $this->city)->orWhereNull('city'))
            ->update(['library' => $this->name, 'city' => $this->city]);
        $this->manuscripts()->searchable();
    }

    /** True when $name is already this library's name, official name or one of its other names. */
    public function knowsName(string $name): bool
    {
        $key = \App\Helpers\TextNormalizer::normalize($name);
        foreach (array_merge([$this->name, $this->full_name], (array) ($this->aliases ?? [])) as $known) {
            if ($known && \App\Helpers\TextNormalizer::normalize($known) === $key) {
                return true;
            }
        }

        return false;
    }

    /** Official name, for introductions, titles, metadata and citations; the short name when none is recorded. */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?: $this->name;
    }

    /** Short name, for compact lists (manuscript tables, search results, facets); pair it with display_name as a tooltip. */
    public function getShortNameAttribute(): string
    {
        return $this->name;
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
