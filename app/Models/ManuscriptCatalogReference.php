<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a manuscript is described in a library catalogue (backlog item 51): its Fankha citation, a page
 * matched by shelfmark in another printing of the catalogue, or one entered by hand.
 */
class ManuscriptCatalogReference extends Model
{
    public const SOURCE_FANKHA = 'fankha';

    public const SOURCE_AI_MATCH = 'ai_match';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::STATUS_UNVERIFIED];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'shelfmark_page' => 'integer',
            'entry_number' => 'integer',
            'confidence' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }

    public function catalogVolume(): BelongsTo
    {
        return $this->belongsTo(CatalogVolume::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Machine matches nobody has reviewed yet: the review queue, and the ⓘ badge on the record page. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_AI_MATCH)->where('status', self::STATUS_UNVERIFIED);
    }

    public function isAwaitingReview(): bool
    {
        return $this->source === self::SOURCE_AI_MATCH && $this->status === self::STATUS_UNVERIFIED;
    }

    /** The detail of the `open-catalog-viewer` event, or null when the volume has no page images. */
    public function viewer(): ?array
    {
        $volume = $this->catalogVolume;
        if (! $volume?->facsimile_code || ! $this->page_start) {
            return null;
        }

        return [
            'catalog' => $volume->facsimile_code,
            'catalogName' => $volume->short_title,
            'volume' => (int) ($this->volume_number ?? $volume->volume_number ?? 1),
            'page' => $this->page_start,
        ];
    }

    public function review(User $user, string $status): void
    {
        $this->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
    }
}
