<?php

ini_set('memory_limit', '512M');

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // search/click only records which rank of an anonymous search was opened (SearchController::click)
        $middleware->validateCsrfTokens(except: ['telegram/webhook', 'search/click', 'messages/inbound']);
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The admins hear of search index errors, at most once an hour (backlog item 25); the error is still logged
        $exceptions->report(function (\Meilisearch\Exceptions\ApiException|\Meilisearch\Exceptions\CommunicationException|\Meilisearch\Exceptions\TimeOutException $e) {
            \App\Notifications\SystemNotices::searchFailed($e);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
