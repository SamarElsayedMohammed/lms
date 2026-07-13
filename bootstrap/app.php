<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/canonical-api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust proxies (Traefik/Caddy/Coolify) so Laravel detects correct scheme (HTTPS) and host
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'webhook/razorpay',
            'webhooks/kashier',
            'api/course-view',
        ]);

        // Add CORS middleware globally (handles preflight and actual requests)
        $middleware->prepend([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Force HTTPS when behind proxy - must run early to fix asset URLs (Mixed Content)
        $middleware->web(prepend: [
            \App\Http\Middleware\ForceHttps::class,
        ]);

        // Add instructor mode middleware to web group
        $middleware->web(append: [
            \App\Http\Middleware\InstructorModeMiddleware::class,
        ]);

        // Add demo mode middleware to both API and web groups
        $middleware->api(prepend: [
            \App\Http\Middleware\AllowQueryToken::class,
        ]);
        
        $middleware->api(append: [
            \App\Http\Middleware\ForceJsonResponseToSnakeCase::class,
            \App\Http\Middleware\DemoModeMiddleware::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\DemoModeMiddleware::class,
            \App\Http\Middleware\SetAdminLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
