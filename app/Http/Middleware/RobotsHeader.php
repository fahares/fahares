<?php

namespace App\Http\Middleware;

use App\Support\Indexing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Private and form pages tell crawlers not to index them (backlog item 72, step 14.107). */
class RobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (Indexing::isPrivate($request) && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
