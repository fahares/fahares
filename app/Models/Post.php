<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A writing the admin publishes on the site (a report of a scholarly meeting, an introduction to
 * a manuscript, …). The body is Markdown; raw HTML in it is escaped, so only Markdown formatting
 * reaches the page. Its cover and the pictures used in the body live on the public disk under
 * posts/{id}/, which is the persistent volume in production.
 */
class Post extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }

    public function getRouteKey(): string
    {
        // A zero-width non-joiner would vanish and join the two parts of a word
        $slug = Str::slug(str_replace("\u{200C}", ' ', $this->title ?? ''), '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }

        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    /** Admin routes get the id-slug key; only its leading id matters. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->whereKey((int) $value)->firstOrFail();
    }

    /** Alt text the admin page offers in the copied snippet; it is not a caption until it is replaced. */
    public const IMAGE_ALT_PLACEHOLDER = 'توضیح تصویر';

    /**
     * The body as HTML. Only Markdown is rendered for now; body_format leaves room for another editor.
     * A picture on a line of its own becomes a figure, with its alt text as the caption below it.
     */
    public function getBodyHtmlAttribute(): string
    {
        $html = Str::markdown((string) $this->body, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return preg_replace_callback('#<p>(<img [^>]*?alt="([^"]*)"[^>]*>)</p>#u', function ($m) {
            $caption = trim($m[2]);
            $caption = $caption === self::IMAGE_ALT_PLACEHOLDER ? '' : $caption;

            return '<figure>' . $m[1] . ($caption !== '' ? "<figcaption>{$caption}</figcaption>" : '') . '</figure>';
        }, $html);
    }

    /** The summary, or the start of the body as plain text, for lists and the meta description. */
    public function getExcerptAttribute(): string
    {
        if ($this->summary) {
            return $this->summary;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($this->body_html)));

        return Str::limit($text, 220);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path
            ? Storage::disk('public')->url($this->cover_path) . '?v=' . $this->updated_at?->timestamp
            : null;
    }

    public function imagesDirectory(): string
    {
        return "posts/{$this->id}/images";
    }

    /**
     * Pictures uploaded for use in the body, newest first. The URL is root-relative, so the
     * Markdown pasted into a body works on every host.
     */
    public function images(): array
    {
        $disk = Storage::disk('public');

        return collect($disk->files($this->imagesDirectory()))
            ->sortByDesc(fn ($path) => $disk->lastModified($path))
            ->map(fn ($path) => ['path' => $path, 'name' => basename($path), 'url' => '/storage/' . $path])
            ->values()
            ->all();
    }

    /** Removes the cover and the body pictures from the disk. */
    public function deleteFiles(): void
    {
        Storage::disk('public')->deleteDirectory("posts/{$this->id}");
    }
}
