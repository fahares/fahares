<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    /** Audit log actions of a bulk verification in the review queue and of its revert. */
    public const ACTION_BULK_VERIFY = 'bulk_verify';

    public const ACTION_BULK_REVERT = 'rollback_bulk_verify';

    /**
     * The only match method the queue may verify in bulk: the title confirms the number. A close title on a single
     * page (shelfmark_single_page, 35 to 60), the number alone (shelfmark_only, 25) or several places (ambiguous, 10)
     * says nothing more than the number, so those are reviewed one by one.
     */
    public const BULK_METHOD = 'shelfmark_title';

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

    /**
     * Verifies every machine match of the query that awaits review, in one transaction and chunks of 1,000, with one
     * reviewed_at for the batch. The audit log row keeps the ids, the filters and that time: revertBulkVerify() undoes it.
     */
    public static function bulkVerify(Builder $query, User $user, array $filters, ?string $ip = null): AuditLog
    {
        return DB::transaction(function () use ($query, $user, $filters, $ip) {
            $ids = (clone $query)->awaitingReview()->lockForUpdate()->orderBy('id')->pluck('id')->all();
            $now = now()->startOfSecond();
            foreach (array_chunk($ids, 1000) as $chunk) {
                static::whereIn('id', $chunk)->update([
                    'status' => self::STATUS_VERIFIED, 'reviewed_by' => $user->id, 'reviewed_at' => $now, 'updated_at' => $now,
                ]);
            }

            return AuditLog::create([
                'user_id' => $user->id,
                'auditable_type' => static::class,
                'auditable_id' => 0,
                'action' => self::ACTION_BULK_VERIFY,
                'old_values' => ['status' => self::STATUS_UNVERIFIED, 'ids' => $ids],
                'new_values' => ['status' => self::STATUS_VERIFIED, 'count' => count($ids), 'filters' => $filters, 'reviewed_at' => $now->toDateTimeString()],
                'ip_address' => $ip,
                'created_at' => $now,
            ]);
        });
    }

    public static function isBulkVerify(AuditLog $log): bool
    {
        return $log->action === self::ACTION_BULK_VERIFY && $log->auditable_type === static::class;
    }

    /** The latest bulk verification that has not been reverted, if any. */
    public static function lastBulkVerify(): ?AuditLog
    {
        return AuditLog::where('auditable_type', static::class)->where('action', self::ACTION_BULK_VERIFY)
            ->whereNotIn('id', AuditLog::where('auditable_type', AuditLog::class)->where('action', self::ACTION_BULK_REVERT)->select('auditable_id'))
            ->latest('id')->first();
    }

    /**
     * Returns a bulk verification's rows to the queue: those still verified by the same reviewer at the batch's time.
     * A row reviewed again since (rejected, or verified by hand) keeps its review. Returns [reverted, kept].
     */
    public static function revertBulkVerify(AuditLog $batch, ?User $user = null, ?string $ip = null): array
    {
        throw_unless(self::isBulkVerify($batch), new \InvalidArgumentException("Audit log #{$batch->id} is not a bulk verification."));

        return DB::transaction(function () use ($batch, $user, $ip) {
            throw_if(AuditLog::where('auditable_type', AuditLog::class)->where('action', self::ACTION_BULK_REVERT)->where('auditable_id', $batch->id)->lockForUpdate()->exists(),
                new \RuntimeException("تأیید گروهی #{$batch->id} پیش‌تر برگردانده شده است."));
            $ids = $batch->old_values['ids'] ?? [];
            $reverted = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $reverted += static::whereIn('id', $chunk)->where('source', self::SOURCE_AI_MATCH)->where('status', self::STATUS_VERIFIED)
                    ->where('reviewed_by', $batch->user_id)->where('reviewed_at', $batch->new_values['reviewed_at'])
                    ->update(['status' => self::STATUS_UNVERIFIED, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
            }
            AuditLog::create([
                'user_id' => $user?->id,
                'auditable_type' => AuditLog::class,
                'auditable_id' => $batch->id,
                'action' => self::ACTION_BULK_REVERT,
                'new_values' => ['reverted' => $reverted, 'kept' => count($ids) - $reverted],
                'ip_address' => $ip,
            ]);

            return [$reverted, count($ids) - $reverted];
        });
    }
}
