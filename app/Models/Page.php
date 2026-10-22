<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A fixed page of the site, such as «درباره ما», whose text the admin edits in the panel.
 * Pages are made by migrations with a fixed slug; the panel changes only the title and body.
 * The body is Markdown, rendered like a post body: raw HTML in it is escaped.
 */
class Page extends Model
{
    /** Slugs of the pages and the public route of each. */
    public const ROUTES = [
        'about' => 'about',
    ];

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return route(self::ROUTES[$this->slug]);
    }

    /** The body as HTML. An opening «بسم الله …» line is centred, as it is in print. */
    public function getBodyHtmlAttribute(): string
    {
        $html = Str::markdown((string) $this->body, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return preg_replace('#^<p>(بسم الله[^<]*)</p>#u', '<p class="basmala">$1</p>', $html);
    }

    /** The start of the body as plain text, for the meta description. */
    public function getExcerptAttribute(): string
    {
        $text = preg_replace('/^بسم الله\S*(\s+\S+){0,2}\s*/u', '', strip_tags($this->body_html));

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text)), 220);
    }
}
