<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A public notice of the site, written in the admin panel and shown once to each visitor (see
 * x-announcement and resources/js/announcement.js). The running notices of a visitor's audience form a
 * queue, newest first: one is shown at a time, and the next not before GAP_HOURS later, so a new notice
 * does not push an older one out and nobody meets two in a row. A notice with on_site off is not shown
 * on the site at all; it only goes to the Telegram channel. Its picture lives on the public disk under
 * announcements/{id}/, which is the persistent volume in production.
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

    /** After one notice, a visitor sees the next one no sooner than this */
    public const GAP_HOURS = 24;

    private const CACHE_KEY = 'announcements.running';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'on_site' => 'boolean',
            'telegram' => 'boolean',
            'telegram_photo' => 'boolean',
            'telegram_sent_at' => 'datetime',
            'notified_at' => 'datetime',
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

    /** The notice to offer this visitor now, or null: the head of their queue. */
    public static function forViewer(?User $user): ?array
    {
        return self::queueFor($user)[0] ?? null;
    }

    /**
     * The running notices of this visitor's audience, newest first. For a member, those already seen are
     * left out, and the queue is empty while the last one seen is younger than GAP_HOURS; a guest's
     * browser keeps both (resources/js/announcement.js). The running notices are cached, so a page view
     * costs no query while nothing runs, and one small query for a member while something does.
     */
    public static function queueFor(?User $user): array
    {
        $running = Cache::remember(self::CACHE_KEY, 600, fn () => static::query()
            ->where('is_active', true)
            ->where('on_site', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->get()->map->payload()->all());

        $now = now()->getTimestamp();
        $mine = $user ? 'members' : 'guests';
        $queue = array_values(array_filter($running, fn ($item) => ($item['starts_at'] ?? 0) <= $now
            && ($item['ends_at'] === null || $item['ends_at'] > $now)
            && in_array($item['audience'], ['all', $mine], true)));
        if (! $queue || ! $user) {
            return $queue;
        }

        $seen = DB::table('announcement_user')->where('user_id', $user->id)->pluck('seen_at', 'announcement_id');
        $last = $seen->max();
        if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours(self::GAP_HOURS))) {
            return [];
        }

        return array_values(array_filter($queue, fn ($item) => ! $seen->has($item['id'])));
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
