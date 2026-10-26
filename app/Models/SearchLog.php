<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * One search made on the site (backlog item 83, phase 0): anonymous for guests and for users who turned their
 * search history off, otherwise kept with the account.
 */
class SearchLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** How long a search is kept */
    public const KEEP_DAYS = 365;

    /** User agents of crawlers, link previews and scripts, whose searches are not people's */
    private const BOT_AGENTS = '~bot|crawl|spider|slurp|preview|fetch|curl|wget|python|java/|go-http|headless|lighthouse|monitor~i';

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'is_member' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** Whether a request comes from a person, worth counting */
    public static function isCountable(Request $request): bool
    {
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOT_AGENTS, $agent)) {
            return false;
        }

        // A page the browser fetched ahead of a click is not a search the user made
        $purpose = strtolower($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? '');

        return ! str_contains($purpose, 'prefetch') && ! str_contains($purpose, 'prerender');
    }
}
