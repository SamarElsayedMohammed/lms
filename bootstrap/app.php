<?php

use App\Exceptions\ApiException;
use App\Exceptions\PromoQuotaExceededException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// ─── Pre-allocate 512KB emergency memory reserve for fatal error forensics ───
if (!isset($GLOBALS['__skillso_memory_reserve'])) {
    $GLOBALS['__skillso_memory_reserve'] = str_repeat(' ', 512 * 1024);
}

// ─── Register fail-safe fatal error and memory exhaustion shutdown hook ─────
if (!defined('SKILLSO_SHUTDOWN_REGISTERED')) {
    define('SKILLSO_SHUTDOWN_REGISTERED', true);

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;
        if (($error['type'] & $fatalTypes) === 0) {
            return;
        }

        // Immediately release emergency memory reserve so JSON encoding & log writes have headroom
        $GLOBALS['__skillso_memory_reserve'] = null;

        $typeNames = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
        ];
        $typeName = $typeNames[$error['type']] ?? ('FATAL_ERROR_' . $error['type']);

        $isOom = str_contains(strtolower($error['message']), 'allowed memory size of') ||
                 str_contains(strtolower($error['message']), 'out of memory');

        $isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
        $requestUri = !$isCli && isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        $requestMethod = !$isCli && isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        $cliCommand = $isCli && isset($_SERVER['argv']) ? implode(' ', $_SERVER['argv']) : null;

        $diagnostic = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'event' => 'FATAL_SHUTDOWN',
            'error_type' => $typeName,
            'is_oom' => $isOom,
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'sapi' => PHP_SAPI,
            'request_method' => $requestMethod,
            'request_uri' => $requestUri,
            'cli_command' => $cliCommand,
            'pid' => getmypid(),
        ];

        $jsonRecord = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 1. Direct synchronous stderr write for container stream / Supervisor
        try {
            file_put_contents('php://stderr', "[FATAL-SHUTDOWN] " . $jsonRecord . PHP_EOL);
        } catch (\Throwable) {}

        // 2. Safe disk persistence to storage/logs/laravel.log
        try {
            $logDir = dirname(__DIR__) . '/storage/logs';
            if (is_dir($logDir) && is_writable($logDir)) {
                $logFile = $logDir . '/laravel.log';
                $logLine = sprintf(
                    "[%s] production.EMERGENCY: Fatal Shutdown Hook: %s in %s:%d %s\n",
                    date('Y-m-d H:i:s'),
                    $error['message'],
                    $error['file'],
                    $error['line'],
                    $jsonRecord
                );
                @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {}
    });
}

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
            'audit.admin' => \App\Http\Middleware\LogAdminMutationMiddleware::class,
            'geo.signed' => \App\Http\Middleware\ForceSignedProxyCountry::class,
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
            \App\Http\Middleware\ForceSignedProxyCountry::class,
            \App\Http\Middleware\EnsureAccessToken::class,
            \App\Http\Middleware\ForceJsonResponseToSnakeCase::class,
            \App\Http\Middleware\DemoModeMiddleware::class,
            \App\Http\Middleware\SetAdminLocale::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\DemoModeMiddleware::class,
            \App\Http\Middleware\SetAdminLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'API Error',
                    'data' => $e->getData() ?? (object) [],
                    'code' => $e->getStatusCode(),
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (PromoQuotaExceededException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $code = $e->getCode() ?: 422;
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'كوبون الخصم استنفذ الحد الأقصى للاستخدام',
                    'code' => $code,
                ], $code);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => 'Unauthenticated.',
                    'code' => 401,
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException|\Spatie\Permission\Exceptions\UnauthorizedException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
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
                    'error' => true,
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
                    'error' => true,
                    'message' => $e->validator?->errors()->first() ?: ($e->getMessage() ?: 'Validation failed.'),
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
                    'error' => true,
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 429,
                ], 429);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'Http Error',
                    'code' => $e->getStatusCode(),
                ], $e->getStatusCode());
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
                } catch (\Throwable $logEx) {
                    // Direct synchronous stderr fallback on logging driver failure
                    try {
                        $fallback = [
                            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                            'level' => 'CRITICAL',
                            'channel' => 'fallback_stderr',
                            'message' => 'API Exception: ' . $e->getMessage(),
                            'exception' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'url' => $request->fullUrl(),
                            'method' => $request->method(),
                            'logging_failure' => $logEx->getMessage(),
                        ];
                        file_put_contents('php://stderr', "[FALLBACK-API-EXCEPTION] " . json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
                    } catch (\Throwable) {}
                }

                $isLocal = false;
                try {
                    $isLocal = app()->environment('local') && config('app.debug') === true;
                } catch (\Throwable) {}

                $payload = [
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $isLocal ? ($e->getMessage() ?: 'Internal server error.') : 'Internal server error.',
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
