<?php

namespace App\Exceptions;

use App\Services\ResponseService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
    protected function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            $this->logException($e);
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * All API error responses follow the standard envelope:
     * { status: false, message: string, errors?: object }
     */
    #[\Override]
    public function render($request, Throwable $e)
    {
        // Always return JSON for API routes — never redirect to login pages.
        if ($request->is('api/*') || $request->expectsJson()) {

            // 1. Intentional API responses raised via ApiResponseService/HttpResponseException
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $e->getResponse();
            }

            // 2. Authentication — unauthenticated user
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthenticated.',
                ], 401, [], JSON_UNESCAPED_UNICODE);
            }

            // 3. Authorization — forbidden action
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage() ?: 'Forbidden.',
                ], 403, [], JSON_UNESCAPED_UNICODE);
            }

            // 4. Validation — structured field errors
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage(),
                    'errors'  => $e->errors(),
                ], 422, [], JSON_UNESCAPED_UNICODE);
            }

            // 5. Model not found (route model binding or findOrFail)
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $model = class_basename($e->getModel());
                return response()->json([
                    'status'  => false,
                    'message' => "{$model} not found.",
                ], 404, [], JSON_UNESCAPED_UNICODE);
            }

            // 6. Route not found
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'status'  => false,
                    'message' => 'The requested endpoint does not exist.',
                ], 404, [], JSON_UNESCAPED_UNICODE);
            }

            // 7. Method not allowed
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                return response()->json([
                    'status'  => false,
                    'message' => 'HTTP method not allowed.',
                ], 405, [], JSON_UNESCAPED_UNICODE);
            }

            // 8. Named ApiException (legacy)
            if ($e instanceof ApiException) {
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage(),
                    'data'    => $e->getData(),
                ], $e->getStatusCode(), [], JSON_UNESCAPED_UNICODE);
            }

            // 9. Catch-all — avoid leaking stack traces in production
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An unexpected server error occurred.';

            return response()->json([
                'status'  => false,
                'message' => $message,
            ], 500, [], JSON_UNESCAPED_UNICODE);
        }

        return parent::render($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     * Always returns JSON — never redirects to a login route.
     */
    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        return response()->json([
            'status'  => false,
            'message' => 'Unauthenticated.',
        ], 401, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Log the exception with detailed information
     */
    protected function logException(Throwable $e): void
    {
        $request = request();
        $trace = $e->getTrace();

        // Get the controller and action name
        $controller = '';
        $action = '';
        foreach ($trace as $item) {
            if (!(isset($item['class']) && str_contains($item['class'], 'Controller'))) {
                continue;
            }

            $controller = class_basename($item['class']);
            $action = $item['function'];
            break;
        }

        // Get the line number where the exception occurred
        $line = $e->getLine();

        // Get request details
        $url = $request->fullUrl();
        $method = $request->method();
        $params = $request->all();

        // Get user token if authenticated
        $userToken = null;
        if (Auth::check()) {
            $userToken = Auth::user()->currentAccessToken()?->plainTextToken;
        }

        // Prepare log message
        $logMessage = [
            'error' => $e->getMessage(),
            'controller' => $controller,
            'action' => $action,
            'line' => $line,
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'user_token' => $userToken,
            'trace' => $e->getTraceAsString(),
        ];

        // Log the exception with context
        Log::error('Application Exception', $logMessage);
    }
}
