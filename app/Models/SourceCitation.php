<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SourceCitation extends Model
{
    protected $guarded = ['id'];

    public const ROLE_LABELS = [
        'addendum' => 'استدراک',
        'erratum' => 'اصلاحیه',
        'entry' => 'مدخل',
    ];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function citable(): MorphTo
    {
        return $this->morphTo();
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function getRoleLabelAttribute(): string
    {
        return $this->label ?: (self::ROLE_LABELS[$this->role] ?? $this->role);
    }

    public function getCleanRawTextAttribute(): ?string
    {
        if (empty($this->raw_text)) {
            return null;
        }

        return trim(preg_replace("/\n{3,}/u", "\n\n", preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->raw_text)));
    }
}
