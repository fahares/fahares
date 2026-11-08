<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What search engines may index (backlog item 72, step 14.107). Record pages are indexed under one address each, the
 * canonical one; private, account and form pages say noindex in a header, and public/robots.txt keeps crawlers off
 * the same paths. robots.txt and the header are guidance for crawlers, not protection: that is the middleware's job.
 */
class Indexing
{
    /** Paths with nothing to index: admin, account, sign-in, forms, internal JSON and files (patterns of Request::is). */
    public const PRIVATE_PATHS = [
        'admin', 'admin/*',
        'my', 'my/*', 'settings', 'settings/*', 'account/*', 'notifications', 'notifications/*',
        'login', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'email/*', 'auth/*',
        'contribute/*', 'notes/*', 'messages/*', 'api/*', 'search/export', 'search/export/*', 'insights/lookup',
    ];

    public static function isPrivate(Request $request): bool
    {
        return $request->is(...self::PRIVATE_PATHS);
    }

    /**
     * The address a page is indexed under: the site's own host and the path, without the query string, so the sorted,
     * paged and filtered copies of a page point at the page itself. A record page reaches this only at its canonical
     * route key, since every other address of it (an old slug, a short link, a merged record) is a 301 to that key.
     */
    public static function canonical(?Request $request = null): string
    {
        $request ??= request();
        $segments = array_map(fn ($s) => rawurlencode(rawurldecode($s)), explode('/', $request->getPathInfo()));

        return rtrim((string) config('app.url'), '/') . implode('/', $segments);
    }
}
