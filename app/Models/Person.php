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
            // ...and the death range, certainty and hedge word, as for a copy's date (backlog item 96)
            if ($person->isDirty('life_dates_raw') || $person->isDirty('death_year_hijri')) {
                $person->forceFill(self::deathColumns($person->life_dates_raw, $person->death_year_hijri));
            }
        });
    }

    /**
     * The death range columns (CopyDateRange) of the catalogue's life dates: the death is what follows the dash
     * («1040 - 1124 ؟ قمری» is 1124 with «؟», «730؟ - 834» a certain 834) and «ق13» is «قرن 13». Solar, Gregorian and
     * BC dates give no range. After a dash, one or two digits are a year of the first century («- 40 قمری»), or the
     * century when they are less than the birth year («1297-14 ق»). Without a range from the text, a death year is a
     * range of its own.
     */
    public static function deathColumns(?string $lifeDates, ?int $deathYear): array
    {
        $range = ['from' => null, 'to' => null, 'certainty' => null, 'hedge' => null];
        $text = strtr((string) $lifeDates, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (trim($text) !== '' && ! preg_match('/میلاد|شمسی|خورشیدی|هجرت|(?<!\p{L})(?:م|ش|ق\.\s*م)(?!\p{L})/u', $text)) {
            $parts = preg_split('/\s*[-–]\s*/u', $text);
            $death = preg_replace('/(?<![\p{L}\d])ق\s*(\d{1,2})(?!\d)/u', 'قرن $1', end($parts));
            if (count($parts) > 1 && preg_match('/^\s*(\d{1,2})\s*(؟)?\s*(?:ق|قمری)?\s*(؟)?\s*$/u', $death, $m) && (int) $m[1] > 0) {
                $hedge = ($m[2] ?? '') . ($m[3] ?? '') !== '' ? '؟' : null;
                $birth = preg_match('/(?<!\d)(\d{1,4})(?!\d)/u', $parts[0], $b) ? (int) $b[1] : null;
                $range = $birth !== null && (int) $m[1] < $birth
                    ? \App\Support\CopyDateRange::parse("قرن {$m[1]}" . ($hedge ? ' ؟' : ''))
                    : ['from' => (int) $m[1], 'to' => (int) $m[1], 'certainty' => \App\Support\PersonNameMatcher::certainty($hedge), 'hedge' => $hedge];
            } else {
                $range = \App\Support\CopyDateRange::parse($death);
            }
        }
        if ($range['from'] === null && $range['to'] === null && $deathYear) {
            $range = ['from' => $deathYear, 'to' => $deathYear, 'certainty' => null, 'hedge' => null];
        }

        return ['death_from' => $range['from'], 'death_to' => $range['to'], 'death_certainty' => $range['certainty'],
            'death_hedge_raw' => $range['hedge'] ? mb_substr($range['hedge'], 0, 60) : null];
    }

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            // Misspelt forms (backlog item 90): searchable, not listed among the other names
            'wrong_names' => 'array',
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'century_hijri' => 'integer',
            'death_year_gregorian' => 'integer',
            'death_from' => 'integer',
            'death_to' => 'integer',
            'is_author' => 'boolean',
            'is_scribe' => 'boolean',
            'is_translator' => 'boolean',
            'is_donor' => 'boolean',
            'works_count' => 'integer',
            'manuscripts_count' => 'integer',
        ];
    }

    /**
     * Life dates for display next to the name, in Persian digits: «۸۱۷–۸۹۸ ق», «د. ۸۹۸ ق»
     * or «سده‌ی ۱۰ ق». Null when nothing is known.
     */
    public function getLifeSpanAttribute(): ?string
    {
        $birth = $this->birth_year_hijri;
        $death = $this->death_year_hijri;

        $text = match (true) {
            $birth && $death => "{$birth}–{$death} ق",
            (bool) $death => "د. {$death} ق",
            (bool) $birth => "ز. {$birth} ق",
            (bool) $this->century_hijri => "سده‌ی {$this->century_hijri} ق",
            default => null,
        };

        return $text ? \App\Support\Fa::digits($text) : null;
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

    /** Other names and misspelt forms: a search by either finds the person (backlog item 90). */
    public function searchAliases(): array
    {
        return array_values(array_merge((array) ($this->aliases ?? []), (array) ($this->wrong_names ?? [])));
    }

    public function toSearchableArray(): array
    {
        $aliases = $this->searchAliases();
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

    /** Works this person is connected with in a role other than author (translator, dedicatee…). */
    public function workRoles(): HasMany
    {
        return $this->hasMany(WorkPerson::class);
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
