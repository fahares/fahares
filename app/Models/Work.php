<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Work extends Model
{
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
