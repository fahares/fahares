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

    /** Standings of an approved field suggestion; see the add_opinions migration. */
    public const STANDINGS = [
        'adopted' => 'مقدار منتخب',
        'alternative' => 'قول دیگر',
        'superseded' => 'منسوخ',
        'edit' => 'ویرایش',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'payload' => 'array',
            'resolution' => 'array',
            'notify_submitter' => 'boolean',
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

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    /** The adopted reading of the same field of the same record, if any. */
    public function scopeAdoptedFor($query, string $type, int $id, string $field)
    {
        return $query->where('suggestable_type', $type)->where('suggestable_id', $id)
            ->where('kind', 'field')->where('field_name', $field)->where('standing', 'adopted');
    }

    public function getStandingLabelAttribute(): ?string
    {
        return self::STANDINGS[$this->standing] ?? null;
    }

    /** The reading as a reader sees it: the reviewer's clean value, with yes/no for flags. */
    public function getDisplayValueAttribute(): string
    {
        $value = $this->applied_value ?? $this->suggested_value;
        if (($this->fieldDefinition()['input'] ?? null) === 'boolean') {
            return in_array($value, ['1', 'true', 'بله'], true) ? 'بله' : 'خیر';
        }

        return (string) $value;
    }

    /** Where the suggestion is about: a record, or for site feedback the page it was sent from. */
    public function getPlaceLabelAttribute(): string
    {
        if ($this->kind === 'feedback') {
            return 'صفحه: ' . ($this->payload['title'] ?? $this->payload['url'] ?? 'نامشخص');
        }

        return SuggestionRegistry::typeLabel($this->suggestable_type) . ' #' . $this->suggestable_id;
    }

    public function getPlaceUrlAttribute(): ?string
    {
        if ($this->kind === 'feedback') {
            return isset($this->payload['url']) ? url($this->payload['url']) : null;
        }
        $record = $this->suggestable;

        return $record ? SuggestionRegistry::describe($record)['url'] : null;
    }

    /** The name a user chose last time beside a correction, or their account's name: the credit box starts from it. */
    public static function creditNameFor(User $user): string
    {
        return self::where('user_id', $user->id)->whereNotNull('credit_name')->where('credit_name', '!=', '')
            ->latest('id')->value('credit_name') ?: $user->name;
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
        if ($this->kind === 'note') {
            return \App\Suggestions\NoteTypes::label($this->field_name);
        }
        if ($this->kind === 'feedback') {
            return SuggestionRegistry::FEEDBACK_CATEGORIES[$this->field_name] ?? 'بازخورد سایت';
        }

        return $this->fieldDefinition()['label'] ?? ($this->field_name ?: 'نامشخص');
    }
}
