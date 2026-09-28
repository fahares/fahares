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
            'subjects' => $this->relationLoaded('subjects') ? $this->subjects->pluck('name')->all() : [],
            'languages' => $this->relationLoaded('languages') ? $this->languages->pluck('name')->all() : [],
        ];
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
}
