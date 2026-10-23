<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where a work, manuscript or person stands in a source, and the text printed there (step 13.1). The text keeps
 * the place it was taken from: when the entry's address is corrected, a text taken elsewhere is stale until it is
 * taken again at the new place or kept by a reviewer.
 */
class SourceEntry extends Model
{
    public const ROLE_ENTRY = 'entry';

    public const ROLE_ADDENDUM = 'addendum';

    public const ROLE_ERRATUM = 'erratum';

    public const ROLE_CITATION = 'citation';

    public const ROLE_LABELS = [
        self::ROLE_ENTRY => 'مدخل',
        self::ROLE_ADDENDUM => 'استدراک',
        self::ROLE_ERRATUM => 'اصلاحیه',
        self::ROLE_CITATION => 'یادکرد',
    ];

    /** Fankha's own entry of the record. */
    public const ORIGIN_FANKHA = 'fankha';

    /** A library catalogue page Fankha cites, «[ف: 30-576]». */
    public const ORIGIN_FANKHA_CITATION = 'fankha_citation';

    /** A work's bibliography as Fankha gives it, «الذریعه 101/25». */
    public const ORIGIN_BIBLIOGRAPHY = 'bibliography';

    /** Found by matching the shelfmark in another printing (catalogs:match-edition). */
    public const ORIGIN_MATCH = 'match';

    public const ORIGIN_MANUAL = 'manual';

    public const TEXT_NONE = 'none';

    public const TEXT_AUTO = 'auto';

    public const TEXT_EDITED = 'edited';

    public const TEXT_STALE = 'stale';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $attributes = ['role' => self::ROLE_ENTRY, 'text_status' => self::TEXT_NONE, 'status' => self::STATUS_UNVERIFIED];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'entry_number' => 'integer',
            'shelfmark_page' => 'integer',
            'is_primary' => 'boolean',
            'locations' => 'array',
            'text_volume_number' => 'integer',
            'text_page' => 'integer',
            'confidence' => 'integer',
            'reviewed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function citable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceVolume(): BelongsTo
    {
        return $this->belongsTo(SourceVolume::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hasText(): bool
    {
        return filled($this->entry_text) || filled($this->copy_text);
    }

    /** Whether the text was taken at another place than the one the entry now has. */
    public function textIsOutOfPlace(): bool
    {
        return $this->hasText() && $this->text_page !== null
            && ($this->text_page !== $this->page_start || $this->text_volume_number !== $this->volume_number);
    }
}
