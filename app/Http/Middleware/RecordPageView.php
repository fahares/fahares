<?php

namespace App\Http\Middleware;

use App\Support\PageViews;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visit stats (backlog item 103): notes the page view once the response has gone to the browser, so the page
 * does not wait for it.
 */
class RecordPageView
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        PageViews::record($request, $response);
    }
}
