<?php

use App\Http\Middleware\EnsureDemoMode;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RecordDeviceRejections;
use App\Http\Middleware\EnsureUserToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth for the dashboard: POST /api/broadcasting/auth with
    // the Bearer token (no session, no cookies).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['auth:sanctum', 'user.token'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // API only: an unauthenticated request gets 401 JSON, never a redirect
        // to a "login" route (there is none).
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'user.token' => EnsureUserToken::class,
            'device.token' => EnsureDeviceToken::class,
            'role' => EnsureRole::class,
            'demo' => EnsureDemoMode::class,
        ]);

        // Laravel sorts auth and throttle middleware before route middleware; the rejection log
        // must wrap them to see 401 and 429, so it sits in the api group (it only acts on api/device/*).
        $middleware->prependToGroup('api', RecordDeviceRejections::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
