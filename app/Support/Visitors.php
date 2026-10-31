<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether a request comes from a person, the shared rule of the search stats (backlog item 83) and the visit
 * stats (item 103): crawlers, link previews and scripts are left out, and so are pages fetched ahead of a click.
 */
final class Visitors
{
    /** User agents of crawlers, link previews and scripts */
    public const BOT_AGENTS = '~bot|crawl|spider|slurp|preview|fetch|curl|wget|python|java/|go-http|headless|lighthouse|monitor~i';

    public static function isPerson(Request $request): bool
    {
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOT_AGENTS, $agent)) {
            return false;
        }

        // A page the browser fetched ahead of a click is not one the user opened
        $purpose = strtolower($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? '');

        return ! str_contains($purpose, 'prefetch') && ! str_contains($purpose, 'prerender');
    }
}
