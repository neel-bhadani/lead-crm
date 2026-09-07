<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        /*
         | The webhook routes, registered with NO middleware group of their own.
         |
         | Deliberately not passed as `web:` and not merged into routes/web.php:
         | everything in that file runs the web stack — session, cookies, CSRF —
         | and Meta can carry none of it. Registering them here leaves them
         | stateless, which is a smaller thing to expose than a stateful route
         | with an exemption bolted on. See routes/webhooks.php.
         */
        then: function () {
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         | Belt and braces. The webhook routes are stateless, so CSRF never runs
         | on them and this exclusion is doing nothing today — it is here so
         | that moving them into routes/web.php later, which is the obvious
         | thing for somebody to do, cannot silently start rejecting Meta's
         | deliveries with a 419 nobody would think to look for.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
