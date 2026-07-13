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
     */
    public function render($request, Throwable $e)
    {
        // Handle authentication exceptions FIRST — before anything else
        // to prevent "Route [login] not defined" crash on API routes.
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return response()->json([
                'success' => false,
                'error'   => true,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($request->is('api/*')) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return \App\Services\ApiResponseService::errorResponse($e->getMessage(), $e->errors(), $e->status, $e);
            }
            if ($e instanceof ApiException) {
                return \App\Services\ApiResponseService::errorResponse($e->getMessage(), $e->getData(), $e->getStatusCode(), $e);
            }
            
            $statusCode = $this->isHttpException($e) ? $e->getStatusCode() : 500;
            return \App\Services\ApiResponseService::errorResponse($e->getMessage(), null, $statusCode, $e);
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
    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        // Always return JSON for API routes — never redirect to 'login'.
        return response()->json([
            'success' => false,
            'error'   => true,
            'message' => 'Unauthenticated.',
        ], 401);
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
