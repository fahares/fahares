<?php

namespace App\Models;

use App\Suggestions\SuggestionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FieldSuggestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'payload' => 'array',
            'resolution' => 'array',
        ];
    }

    public function suggestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(ScholarlyAnnotation::class, 'suggestion_id');
    }

    /** The registry definition of the target field, or null for general reports and retired fields. */
    public function fieldDefinition(): ?array
    {
        return $this->kind === 'field' ? SuggestionRegistry::field($this->suggestable_type, $this->field_name) : null;
    }

    public function relationDefinition(): ?array
    {
        return $this->kind === 'relation' ? SuggestionRegistry::relation($this->suggestable_type, $this->field_name) : null;
    }

    /** The other record a relational suggestion points at. */
    public function relationTarget(): ?Model
    {
        $relation = $this->relationDefinition();
        $id = $this->payload['target_id'] ?? null;

        return $relation && $id ? SuggestionRegistry::modelClass($relation['target'])::find($id) : null;
    }

    public function getFieldLabelAttribute(): string
    {
        if ($this->kind === 'general') {
            return 'پیشنهاد کلی';
        }
        if ($this->kind === 'relation') {
            return $this->relationDefinition()['action'] ?? 'پیشنهاد رابطه‌ای';
        }

        return $this->fieldDefinition()['label'] ?? ($this->field_name ?: 'نامشخص');
    }
}
