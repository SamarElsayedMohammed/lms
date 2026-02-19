<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HasWebResponse
{
    // =========================================================================
    // JSON Responses (for AJAX requests in web controllers)
    // =========================================================================

    /**
     * @param array<string, mixed> $meta
     */
    protected function jsonSuccess(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $response = [
            'error' => false,
            'message' => trans($message),
            'data' => $data,
            'code' => $status,
            ...$meta,
        ];

        return response()->json($response, $status);
    }

    protected function jsonError(
        string $message = 'Error Occurred',
        mixed $data = null,
        int $status = 400,
        null|Throwable $exception = null,
    ): JsonResponse {
        $response = [
            'error' => true,
            'message' => trans($message),
            'data' => $data,
            'code' => $status,
        ];

        if (config('app.debug') === true && $exception instanceof Throwable) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
            ];
        }

        return response()->json($response, $status);
    }

    protected function jsonValidationError(string $message = 'Validation Failed', mixed $errors = null): JsonResponse
    {
        return $this->jsonError($message, $errors, 422);
    }

    protected function jsonWarning(string $message = 'Warning', mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'error' => false,
            'warning' => true,
            'message' => trans($message),
            'data' => $data,
            'code' => $status,
        ], $status);
    }

    // =========================================================================
    // Redirect Responses
    // =========================================================================

    protected function redirectSuccess(string $message = 'Success', null|string $url = null): RedirectResponse
    {
        $redirect = $url !== null ? redirect($url) : redirect()->back();

        return $redirect->with('success', trans($message));
    }

    protected function redirectError(
        string $message = 'Error Occurred',
        null|string $url = null,
        null|array $input = null,
    ): RedirectResponse {
        $redirect = $url !== null ? redirect($url) : redirect()->back();
        $redirect = $redirect->with('errors', trans($message));

        if ($input !== null) {
            $redirect = $redirect->withInput($input);
        }

        return $redirect;
    }

    protected function redirectWithErrors(string $message = 'Error Occurred', null|string $url = null): RedirectResponse
    {
        $redirect = $url !== null ? redirect($url) : redirect()->back();

        return $redirect->withErrors(['message' => trans($message)]);
    }

    // =========================================================================
    // Permission Guards
    // =========================================================================

    /**
     * Require a specific permission or redirect to home
     */
    protected function requirePermissionOrRedirect(string $permission): void
    {
        $user = Auth::user();

        if (!$user instanceof User || !$user->can($permission)) {
            redirect(route('home'))->withErrors(['message' => trans("You Don't have enough permissions")])->send();

            exit();
        }
    }

    /**
     * Require a specific permission or send JSON error response
     */
    protected function requirePermissionOrAbortJson(string $permission): void
    {
        $user = Auth::user();

        if (!$user instanceof User || !$user->can($permission)) {
            response()->json([
                'error' => true,
                'message' => trans("You Don't have enough permissions"),
                'data' => null,
                'code' => 403,
            ], 403)->send();

            exit();
        }
    }

    /**
     * Require any of the specified permissions or redirect to home
     *
     * @param array<int, string> $permissions
     */
    protected function requireAnyPermissionOrRedirect(array $permissions): void
    {
        $user = Auth::user();

        if (!$user instanceof User || !$user->canAny($permissions)) {
            redirect(route('home'))->withErrors(['message' => trans("You Don't have enough permissions")])->send();

            exit();
        }
    }

    /**
     * Require any of the specified permissions or send JSON error response
     *
     * @param array<int, string> $permissions
     */
    protected function requireAnyPermissionOrAbortJson(array $permissions): void
    {
        $user = Auth::user();

        if (!$user instanceof User || !$user->canAny($permissions)) {
            response()->json([
                'error' => true,
                'message' => trans("You Don't have enough permissions"),
                'data' => null,
                'code' => 403,
            ], 403)->send();

            exit();
        }
    }

    // =========================================================================
    // Error Logging
    // =========================================================================

    /**
     * Log an exception without sending a response
     */
    protected function logError(Throwable $e, string $context = 'Error occurred'): void
    {
        Log::error($context . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
