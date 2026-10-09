<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityRedirect extends Model
{
    protected $table = 'entity_redirects';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'target_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record a permanent redirect from source entity to target entity.
     * Automatically fixes any prior redirects pointing to source_id so chains resolve directly to target_id.
     */
    public static function recordRedirect(string $entityType, int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        // 1. Resolve prior redirect chains: any entity previously pointing to $sourceId now points to $targetId
        static::where('entity_type', $entityType)
            ->where('target_id', $sourceId)
            ->update(['target_id' => $targetId]);

        // 2. Upsert the current redirect
        static::updateOrCreate(
            ['entity_type' => $entityType, 'source_id' => $sourceId],
            ['target_id' => $targetId, 'created_at' => now()]
        );
    }

    /**
     * Resolve the final target ID for a given source entity ID.
     */
    public static function resolveTargetId(string $entityType, int $sourceId, int $maxHops = 5): ?int
    {
        $currentId = $sourceId;
        $hops = 0;
        $resolved = null;

        while ($hops < $maxHops) {
            $redirect = static::where('entity_type', $entityType)
                ->where('source_id', $currentId)
                ->first();

            if (! $redirect) {
                break;
            }

            $currentId = (int) $redirect->target_id;
            $resolved = $currentId;
            $hops++;
        }

        return $resolved;
    }

    /**
     * Remove redirect entry (used during merge rollback).
     */
    public static function removeRedirect(string $entityType, int $sourceId): void
    {
        static::where('entity_type', $entityType)
            ->where('source_id', $sourceId)
            ->delete();
    }
}
