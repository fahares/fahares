<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A public notice of the site, written in the admin panel and shown once to each visitor (see
 * x-announcement and resources/js/announcement.js). Only the newest running notice of a visitor's
 * audience is offered, so two notices never queue up on one page. Its picture lives on the public
 * disk under announcements/{id}/, which is the persistent volume in production.
 */
class Announcement extends Model
{
    public const STYLES = [
        'card' => 'کارت گوشه‌ی صفحه',
        'modal' => 'پنجره‌ی میان صفحه',
    ];

    public const AUDIENCES = [
        'all' => 'همه',
        'guests' => 'فقط مهمان‌ها',
        'members' => 'فقط اعضا',
    ];

    public const DEFAULT_DAYS = 14;

    private const CACHE_KEY = 'announcements.running';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'telegram' => 'boolean',
            'telegram_photo' => 'boolean',
            'telegram_sent_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** off, scheduled, running or ended */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'off',
            $this->ends_at !== null && $this->ends_at->lte(now()) => 'ended',
            $this->starts_at !== null && $this->starts_at->gt(now()) => 'scheduled',
            default => 'running',
        };
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** A link to another site opens in a new tab; a path of this site does not. */
    public function linkIsExternal(): bool
    {
        return $this->link_url !== null && ! str_starts_with($this->link_url, '/');
    }

    /** What the page needs to draw the notice. */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->link_url,
            'label' => $this->link_url ? ($this->link_label ?: 'بیشتر') : null,
            'external' => $this->linkIsExternal(),
            'image' => $this->image_url,
            'style' => $this->style,
            'audience' => $this->audience,
            'starts_at' => $this->starts_at?->getTimestamp(),
            'ends_at' => $this->ends_at?->getTimestamp(),
        ];
    }

    /**
     * The notice to offer this visitor, or null: the newest running one of their audience, unless a
     * member has already seen it. The running notices are cached, so a page view costs no query
     * while nothing runs, and one small query for a member while something does.
     */
    public static function forViewer(?User $user): ?array
    {
        $running = Cache::remember(self::CACHE_KEY, 600, fn () => static::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->get()->map->payload()->all());

        $now = now()->getTimestamp();
        $mine = $user ? 'members' : 'guests';
        foreach ($running as $item) {
            if (($item['starts_at'] ?? 0) > $now || ($item['ends_at'] !== null && $item['ends_at'] <= $now)) {
                continue;
            }
            if (! in_array($item['audience'], ['all', $mine], true)) {
                continue;
            }
            if ($user && DB::table('announcement_user')->where('announcement_id', $item['id'])->where('user_id', $user->id)->exists()) {
                return null;
            }

            return $item;
        }

        return null;
    }

    public function isRunning(): bool
    {
        return $this->status() === 'running';
    }

    public function deleteFiles(): void
    {
        Storage::disk('public')->deleteDirectory("announcements/{$this->id}");
    }
}
