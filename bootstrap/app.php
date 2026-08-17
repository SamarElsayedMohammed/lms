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
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

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
            \App\Http\Middleware\EnsureAccessToken::class,
            \App\Http\Middleware\ForceJsonResponseToSnakeCase::class,
            \App\Http\Middleware\DemoModeMiddleware::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\DemoModeMiddleware::class,
            \App\Http\Middleware\SetAdminLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => 'Unauthenticated.',
                    'code' => 401,
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => $e->getMessage() ?: 'Unauthorized action.',
                    'code' => 403,
                ], 403);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => 'Resource not found.',
                    'code' => 404,
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => $e->validator->errors()->first() ?: 'Validation failed.',
                    'errors' => $e->errors(),
                    'code' => 422,
                ], 422);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 429,
                ], 429);
            }
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    return $e->getResponse();
                }

                try {
                    \Illuminate\Support\Facades\Log::error('API Exception: ' . $e->getMessage(), [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                    ]);
                } catch (\Throwable) {
                    // Suppress log failure to guarantee API error response delivery
                }

                $isLocal = app()->environment('local') && config('app.debug') === true;

                $payload = [
                    'success' => false,
                    'status' => false,
                    'message' => $isLocal ? $e->getMessage() : 'Internal server error.',
                    'code' => 500,
                ];

                if ($isLocal) {
                    $payload['debug'] = [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                }

                return response()->json($payload, 500);
            }
        });
    })->create();
