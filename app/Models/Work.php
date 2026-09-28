<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Work extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'alternative_titles' => 'array',
            'composition_year_hijri' => 'integer',
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'manuscripts_count' => 'integer',
        ];
    }

    public function getTitleAttribute(): string
    {
        return $this->primary_title ?? '';
    }

    public function getPersianSlugAttribute(): string
    {
        $title = $this->clean_title ?: $this->primary_title;
        $slug = \Illuminate\Support\Str::slug($title, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'work';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function searchableAs(): string
    {
        return 'works_index';
    }

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['subjects:id,name', 'languages:id,name']);
    }

    public function toSearchableArray(): array
    {
        $alts = [];
        if (!empty($this->alternative_titles)) {
            foreach ($this->alternative_titles as $item) {
                if (is_array($item) && !empty($item['title'])) {
                    $alts[] = $item['title'];
                } elseif (is_string($item)) {
                    $alts[] = $item;
                }
            }
        }

        return [
            'id' => (int) $this->id,
            'primary_title' => $this->primary_title,
            'clean_title' => $this->clean_title,
            'slug' => $this->slug,
            'transliteration' => $this->transliteration,
            'alternative_titles' => $alts,
            'author_name' => $this->author_name,
            'author_id' => $this->author_id ? (int) $this->author_id : null,
            'composition_year_hijri' => $this->composition_year_hijri ? (int) $this->composition_year_hijri : null,
            'volume_number' => (int) $this->volume_number,
            'page_start' => (int) $this->page_start,
            'page_end' => $this->page_end ? (int) $this->page_end : null,
            'work_form' => $this->work_form,
            'subject_summary' => $this->subject_summary,
            'language_summary' => $this->language_summary,
            'manuscripts_count' => (int) $this->manuscripts_count,
            'incipit_text' => $this->incipit_text,
            'explicit_text' => $this->explicit_text,
            'description' => $this->description ? mb_substr($this->description, 0, 500) : null,
            'subjects' => $this->relationLoaded('subjects') ? $this->subjects->pluck('name')->all() : [],
            'languages' => $this->relationLoaded('languages') ? $this->languages->pluck('name')->all() : [],
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_id');
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'target_work_id');
    }

    public function fieldSuggestions(): MorphMany
    {
        return $this->morphMany(FieldSuggestion::class, 'suggestable');
    }

    public function scholarlyAnnotations(): MorphMany
    {
        return $this->morphMany(ScholarlyAnnotation::class, 'annotatable');
    }

    public function getCopyCenturyTextAttribute(): ?string
    {
        if (array_key_exists('copy_century_text', $this->attributes)) {
            return $this->attributes['copy_century_text'];
        }

        $minAllowedYear = 300;
        if ($this->composition_year_hijri) {
            $minAllowedYear = max($minAllowedYear, $this->composition_year_hijri - 40);
        } elseif ($this->author?->death_year_hijri) {
            $minAllowedYear = max($minAllowedYear, $this->author->death_year_hijri - 90);
        } elseif ($this->author?->century_hijri) {
            $minAllowedYear = max($minAllowedYear, ($this->author->century_hijri - 2) * 100);
        }

        $range = $this->manuscripts()
            ->whereNotNull('copy_date_hijri_year')
            ->where('copy_date_hijri_year', '>=', $minAllowedYear)
            ->where('copy_date_hijri_year', '<=', 1500)
            ->selectRaw('MIN(copy_date_hijri_year) as min_year, MAX(copy_date_hijri_year) as max_year')
            ->first();

        if ($range && $range->min_year && $range->max_year) {
            $minCentury = (int) ceil($range->min_year / 100);
            $maxCentury = (int) ceil($range->max_year / 100);
            return $minCentury === $maxCentury
                ? "قرن {$minCentury} هـ.ق"
                : "از قرن {$minCentury} تا {$maxCentury} هـ.ق";
        }

        return null;
    }

    public function getHasAutographAttribute(): bool
    {
        if (array_key_exists('has_autograph', $this->attributes)) {
            return (bool) $this->attributes['has_autograph'];
        }
        return $this->manuscripts()->where('is_autograph', true)->exists();
    }
}
