<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Person extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Derived columns follow their source unless the caller set them too
        static::saving(function (Person $person) {
            if ($person->isDirty('name') && ! $person->isDirty('normalized_name') && $person->exists) {
                $person->normalized_name = mb_substr(\App\Helpers\TextNormalizer::normalize($person->name), 0, 600);
            }
            if ($person->isDirty('death_year_hijri') && ! $person->isDirty('century_hijri') && $person->death_year_hijri) {
                $person->century_hijri = (int) ceil($person->death_year_hijri / 100);
            }
        });
    }

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
            'name' => \App\Helpers\TextNormalizer::expandVariants($this->name),
            'normalized_name' => \App\Helpers\TextNormalizer::expandVariants($normalizedWithAliases),
            'aliases' => array_map(fn($a) => \App\Helpers\TextNormalizer::expandVariants($a), $aliases),
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

    /**
     * Works this person authored, alone or with others.
     */
    public function authoredWorks(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_author')->withPivot(['position', 'note']);
    }

    public function works(): BelongsToMany
    {
        return $this->authoredWorks();
    }

    /**
     * Manuscripts this person copied, alone or with others.
     */
    public function scribedManuscripts(): BelongsToMany
    {
        return $this->belongsToMany(Manuscript::class, 'manuscript_scribe')->withPivot(['position', 'note']);
    }

    /**
     * Works where this person is the primary author (works.author_id).
     */
    public function primaryWorks(): HasMany
    {
        return $this->hasMany(Work::class, 'author_id');
    }

    /**
     * Recompute works_count, manuscripts_count and the role flags from the pivots.
     */
    /** Manuscripts this person is connected with in another role (owner, endower…). */
    public function manuscriptRoles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptPerson::class);
    }

    public function recountContributions(): void
    {
        $works = $this->authoredWorks()->count();
        $manuscripts = $this->scribedManuscripts()->count();

        $this->update([
            'works_count' => $works,
            'manuscripts_count' => $manuscripts,
            'is_author' => $this->is_author || $works > 0,
            'is_scribe' => $this->is_scribe || $manuscripts > 0,
        ]);
    }
}
