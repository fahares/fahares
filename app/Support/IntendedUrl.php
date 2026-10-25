<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where to return after signing in or up, passed as `intended` by the sign-in dialog of the suggestion desk
 * (backlog item 76), e.g. «/works/12?suggest=primary_title». Only a path on this site is kept.
 */
class IntendedUrl
{
    public static function remember(Request $request): void
    {
        $path = (string) $request->input('intended', '');
        if ($path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') && mb_strlen($path) <= 500) {
            $request->session()->put('url.intended', url($path));
        }
    }
}
