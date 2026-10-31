<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An incipit or explicit of a work beyond its main one (numbered, or of a part), or a copy's pointer at one
 * («برابر 2», «برابر جلد دوم»; backlog item 98).
 */
class TextExcerpt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_part' => 'boolean', 'n' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function sameAs(): BelongsTo
    {
        return $this->belongsTo(self::class, 'same_as_excerpt_id');
    }

    /** «آغاز ۲ (دیباچه)», «آغاز جلد دوم», «انجام ۱». */
    public function getHeadingAttribute(): string
    {
        $kind = $this->kind === 'explicit' ? 'انجام' : 'آغاز';
        if ($this->is_part) {
            return trim("{$kind} " . ($this->label ?? ''));
        }

        return trim($kind . ($this->n ? ' ' . \App\Support\Fa::digits($this->n) : '') . ($this->label ? " ({$this->label})" : ''));
    }
}
