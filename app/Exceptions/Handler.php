<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    #[\Override]
    public function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            $this->logException($e);
        });
    }

    /**
     * Report or log an exception safely.
     *
     * @param  \Throwable  $e
     * @return void
     */
    #[\Override]
    public function report(Throwable $e)
    {
        $e = $this->mapException($e);

        if ($this->shouldntReport($e)) {
            return;
        }

        try {
            $this->logException($e);
        } catch (Throwable $outerEx) {
            $this->fallbackStderrLog($e, ['outer_report_exception' => $outerEx->getMessage()]);
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[\Override]
    public function render($request, Throwable $e)
    {
        // Handle authentication exceptions FIRST — before anything else
        // to prevent "Route [login] not defined" crash on API routes.
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'status' => false,
                'error'   => true,
                'message' => 'Unauthenticated.',
                'code' => 401,
            ], 401);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof ApiException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'API Error',
                    'data' => $e->getData() ?? (object) [],
                    'code' => $e->getStatusCode(),
                ], $e->getStatusCode());
            }

            if ($e instanceof PromoQuotaExceededException) {
                $code = $e->getCode() ?: 422;
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'كوبون الخصم استنفذ الحد الأقصى للاستخدام',
                    'code' => $code,
                ], $code);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->validator?->errors()->first() ?: ($e->getMessage() ?: 'Validation failed.'),
                    'errors' => $e->errors(),
                    'code' => 422,
                ], 422);
            }

            if ($e instanceof AuthorizationException ||
                $e instanceof AccessDeniedHttpException ||
                $e instanceof UnauthorizedException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'Unauthorized action.',
                    'code' => 403,
                ], 403);
            }

            if ($e instanceof ModelNotFoundException ||
                $e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => 'Resource not found.',
                    'code' => 404,
                ], 404);
            }

            if ($e instanceof TooManyRequestsHttpException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 429,
                ], 429);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => true,
                    'message' => $e->getMessage() ?: 'Http Error',
                    'code' => $e->getStatusCode(),
                ], $e->getStatusCode());
            }

            $isLocal = app()->environment('local') && config('app.debug') === true;
            $statusCode = $this->isHttpException($e) ? $e->getStatusCode() : 500;

            $payload = [
                'success' => false,
                'status' => false,
                'error' => true,
                'message' => ($isLocal || $statusCode < 500) ? ($e->getMessage() ?: 'Error Occurred') : 'Internal server error.',
                'code' => $statusCode,
            ];

            if ($isLocal) {
                $payload['debug'] = [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ];
            }

            return response()->json($payload, $statusCode);
        }

        return parent::render($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[\Override]
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // Always return JSON for API routes — never redirect to 'login'.
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'status' => false,
                'error'   => true,
                'message' => 'Unauthenticated.',
                'code' => 401,
            ], 401);
        }

        return redirect()->guest($exception->redirectTo($request) ?? (Route::has('login') ? route('login') : '/'));
    }

    /**
     * Log the exception with detailed information safely.
     * Prevents secondary exceptions if Auth/DB or Log driver fails.
     */
    protected function logException(Throwable $e): void
    {
        try {
            $request = request();
            $trace = $e->getTrace();

            // Get the controller and action name safely
            $controller = '';
            $action = '';
            if (is_array($trace)) {
                foreach ($trace as $item) {
                    if (!(isset($item['class']) && str_contains($item['class'], 'Controller'))) {
                        continue;
                    }

                    $controller = class_basename($item['class']);
                    $action = $item['function'] ?? '';
                    break;
                }
            }

            // Get the line number where the exception occurred
            $line = $e->getLine();

            // Safely extract request details without assuming active HTTP request
            $url = null;
            $method = null;
            $params = [];
            try {
                if ($request && method_exists($request, 'fullUrl')) {
                    $url = $request->fullUrl();
                }
                if ($request && method_exists($request, 'method')) {
                    $method = $request->method();
                }
                if ($request && method_exists($request, 'except')) {
                    $params = $request->except([
                        'password',
                        'password_confirmation',
                        'current_password',
                        'token',
                        'access_token',
                        'refresh_token',
                        'authorization',
                        'card_number',
                        'cvv',
                        'otp',
                    ]);
                    if (is_array($params)) {
                        foreach ($params as $key => $value) {
                            if ((method_exists($request, 'file') && $request->file($key)) ||
                                (is_string($key) && str_contains(strtolower($key), 'file'))) {
                                $params[$key] = '[uploaded-file]';
                            }
                        }
                    }
                }
            } catch (Throwable) {
                // Ignore request parsing errors
            }

            // Safely isolate auth/user context extraction to prevent secondary QueryException/PDOException
            $userId = null;
            try {
                if (class_exists(Auth::class)) {
                    $userId = Auth::id();
                }
            } catch (Throwable) {
                $userId = null;
            }

            $logMessage = [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $line,
                'controller' => $controller,
                'action' => $action,
                'url' => $url,
                'method' => $method,
                'params' => $params,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ];

            // Safely invoke Log::error with try-catch and direct stderr fallback
            try {
                Log::error('Application Exception: ' . $e->getMessage(), $logMessage);
            } catch (Throwable $logError) {
                $this->fallbackStderrLog($e, $logMessage, $logError);
            }
        } catch (Throwable $outerEx) {
            // Absolute fail-safe: write directly to stderr
            $this->fallbackStderrLog($e, ['outer_handler_exception' => $outerEx->getMessage()]);
        }
    }

    /**
     * Synchronous fallback write to php://stderr when Monolog/disk logging fails.
     */
    protected function fallbackStderrLog(Throwable $e, array $context = [], ?Throwable $logError = null): void
    {
        try {
            $stderrData = [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => 'CRITICAL',
                'channel' => 'fallback_stderr',
                'message' => 'Application Exception: ' . $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'context' => $context,
            ];
            if ($logError !== null) {
                $stderrData['logging_failure'] = [
                    'message' => $logError->getMessage(),
                    'file' => $logError->getFile(),
                    'line' => $logError->getLine(),
                ];
            }
            $json = json_encode($stderrData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents('php://stderr', "[FALLBACK-EXCEPTION] " . $json . PHP_EOL);
        } catch (Throwable) {
            // Suppress fallback errors to guarantee non-crashing execution
        }
    }
}
