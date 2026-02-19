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
    #[\Override]
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            if ($e instanceof ApiException) {
                return ResponseService::errorResponse($e->getMessage(), $e->getData(), $e->getStatusCode());
            }
            return ResponseService::errorResponse($e->getMessage());
        }

        return parent::render($request, $e);
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
