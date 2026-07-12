<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ApiResponseService
{
    public static function noPermissionThenRedirect(string $permission): Application|RedirectResponse|Redirector|true
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->can($permission)) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions"),
            ])->send();
        }

        return true;
    }

    public static function noPermissionThenSendJson(string $permission): true
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->can($permission)) {
            self::errorResponse("You Don't have enough permissions");
        }

        return true;
    }

    /**
     * If user doesn't have any of the permissions specified in array, send JSON error response
     *
     * @param array<int, string> $permissions
     */
    public static function noAnyPermissionThenSendJson(array $permissions): true
    {
        $user = Auth::user();
        if (!$user instanceof User || !$user->canany($permissions)) {
            self::errorResponse("You Don't have enough permissions");
        }

        return true;
    }

    /**
     * Send a successful JSON response using the standard API envelope.
     *
     * Shape: { status: true, message, data }
     *
     * @param array<string, mixed> $customData
     * @param array<string, string> $headers
     */
    public static function successResponse(
        string|null $message = 'Success',
        mixed $data = null,
        array $customData = [],
        int|null $code = null,
        string|null $redirectUrl = null,
        array $headers = [],
    ): void {
        $code ??= (int) config('constants.RESPONSE_CODE.SUCCESS');
        $response = [
            'status'  => true,
            'message' => trans($message),
            'data'    => $data ?? null,
        ];

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        $jsonResponse = response()->json([...$response, ...$customData], $code, [], JSON_UNESCAPED_UNICODE);

        foreach ($headers as $headerName => $headerValue) {
            $jsonResponse->header($headerName, $headerValue);
        }

        // Preserve CORS headers when raising the response from a service.
        try {
            $origin = request()->header('Origin');
            if ($origin !== null && $origin !== '') {
                $jsonResponse->header('Access-Control-Allow-Origin', $origin);
                $jsonResponse->header('Access-Control-Allow-Credentials', 'true');
            }
        } catch (Throwable) {
            // Ignore CORS header errors - don't break the response
        }

        throw new HttpResponseException($jsonResponse);
    }

    /**
     * Send an error JSON response using the standard API envelope.
     *
     * Shape: { status: false, message, data? }
     */
    public static function errorResponse(
        string $message = 'Error Occurred',
        mixed $data = null,
        int|null $code = null,
        Throwable|null $exception = null,
        string|null $redirectUrl = null,
    ): void {
        // Preserve responses intentionally raised via successResponse/validationError
        // instead of wrapping them as an unrelated error response.
        if ($exception instanceof HttpResponseException) {
            throw $exception;
        }

        $code ??= (int) config('constants.RESPONSE_CODE.ERROR');
        $response = [
            'status'  => false,
            'message' => trans($message),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        if (config('app.debug') === true && $exception instanceof Throwable) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'trace'   => $exception->getTrace(),
            ];
        }

        $jsonResponse = response()->json($response, $code, [], JSON_UNESCAPED_UNICODE);

        // Preserve CORS headers when raising the response from a service.
        try {
            $origin = request()->header('Origin');
            if ($origin !== null && $origin !== '') {
                $jsonResponse->header('Access-Control-Allow-Origin', $origin);
                $jsonResponse->header('Access-Control-Allow-Credentials', 'true');
            }
        } catch (Throwable) {
            // Ignore CORS header errors - don't break the response
        }

        throw new HttpResponseException($jsonResponse);
    }

    /**
     * Send a validation error response.
     *
     * Shape: { status: false, message, errors: {...} }
     */
    public static function validationError(string $message = 'Validation Failed', mixed $data = null): void
    {
        self::errorResponse($message, $data, (int) config('constants.RESPONSE_CODE.VALIDATION_ERROR'));
    }

    /**
     * Log an exception to the system logs.
     * Intentional API responses raised via HttpResponseException are re-thrown.
     */
    public static function logErrorResponse(Throwable $e, string $logMessage = 'Error occurred'): void
    {
        if ($e instanceof HttpResponseException) {
            throw $e;
        }

        Log::error($logMessage . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Use in controller catch blocks: rethrow success/validation responses, otherwise return API error.
     */
    public static function fail(Throwable $e, string $message, mixed $data = null): void
    {
        if ($e instanceof HttpResponseException) {
            throw $e;
        }

        self::logErrorResponse($e, $message);
        self::errorResponse($message, $data, exception: $e);
    }

    public static function unauthorizedResponse(string $message = 'Unauthorized.'): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }
}
