<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->guest(route('login'))
                ->with('warning', 'برای ورود به پنل مدیریت، ابتدا باید به حساب کاربری خود وارد شوید.');
        }

        if (! $request->user()->canAccessAdmin()) {
            abort(403, 'دسترسی به پنل مدیریت و نظارت علمی فهارس برای این حساب کاربری مجاز نیست.');
        }

        return $next($request);
    }
}
