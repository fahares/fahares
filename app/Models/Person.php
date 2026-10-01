<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Person extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'century_hijri' => 'integer',
            'death_year_gregorian' => 'integer',
            'is_author' => 'boolean',
            'is_scribe' => 'boolean',
            'is_translator' => 'boolean',
            'is_donor' => 'boolean',
            'works_count' => 'integer',
            'manuscripts_count' => 'integer',
        ];
    }

    public function getPersianSlugAttribute(): string
    {
        $slug = \Illuminate\Support\Str::slug($this->name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'person';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function searchableAs(): string
    {
        return 'people_index';
    }

    public function toSearchableArray(): array
    {
        $aliases = (array) ($this->aliases ?? []);
        $normalizedWithAliases = trim($this->normalized_name . ' ' . implode(' ', $aliases));

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'normalized_name' => $normalizedWithAliases,
            'aliases' => $aliases,
            'slug' => $this->slug,
            'transliteration' => $this->transliteration,
            'death_year_hijri' => $this->death_year_hijri ? (int) $this->death_year_hijri : null,
            'century_hijri' => $this->century_hijri ? (int) $this->century_hijri : null,
            'death_year_gregorian' => $this->death_year_gregorian ? (int) $this->death_year_gregorian : null,
            'is_author' => (bool) $this->is_author,
            'is_scribe' => (bool) $this->is_scribe,
            'is_translator' => (bool) $this->is_translator,
            'is_donor' => (bool) $this->is_donor,
            'works_count' => (int) $this->works_count,
            'manuscripts_count' => (int) $this->manuscripts_count,
        ];
    }

    public function authoredWorks(): HasMany
    {
        return $this->hasMany(Work::class, 'author_id');
    }

    public function works(): HasMany
    {
        return $this->authoredWorks();
    }

    public function scribedManuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class, 'scribe_id');
    }
}
