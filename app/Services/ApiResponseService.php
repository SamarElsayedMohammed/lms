<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
     * @param array<string, mixed> $customData
     */
    public static function successResponse(
        string|null $message = 'Success',
        mixed $data = null,
        array $customData = [],
        int|null $code = null,
        string|null $redirectUrl = null,
    ): void {
        $code ??= (int) config('constants.RESPONSE_CODE.SUCCESS');
        $response = [
            'error' => false,
            'message' => trans($message),
            'data' => $data ?? (object) [],
            'code' => $code,
        ];

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        $jsonResponse = response()->json([...$response, ...$customData], $code);

        // Add CORS headers manually since we're bypassing middleware with exit()
        try {
            $origin = request()->header('Origin');
            if ($origin !== null && $origin !== '') {
                $jsonResponse->header('Access-Control-Allow-Origin', $origin);
                $jsonResponse->header('Access-Control-Allow-Credentials', 'true');
            }
        } catch (Throwable) {
            // Ignore CORS header errors - don't break the response
        }

        $jsonResponse->send();
        exit();
    }

    public static function errorResponse(
        string $message = 'Error Occurred',
        mixed $data = null,
        int|null $code = null,
        Throwable|null $exception = null,
        string|null $redirectUrl = null,
    ): void {
        $code ??= (int) config('constants.RESPONSE_CODE.ERROR');
        $response = [
            'error' => true,
            'message' => trans($message),
            'data' => $data ?? (object) [],
            'code' => $code,
        ];

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        if (config('app.debug') === true && $exception instanceof Throwable) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
            ];
        }

        $jsonResponse = response()->json($response, $code);

        // Add CORS headers manually since we're bypassing middleware with exit()
        try {
            $origin = request()->header('Origin');
            if ($origin !== null && $origin !== '') {
                $jsonResponse->header('Access-Control-Allow-Origin', $origin);
                $jsonResponse->header('Access-Control-Allow-Credentials', 'true');
            }
        } catch (Throwable) {
            // Ignore CORS header errors - don't break the response
        }

        $jsonResponse->send();
        exit();
    }

    public static function validationError(string $message = 'Error Occurred', mixed $data = null): void
    {
        self::errorResponse($message, $data, (int) config('constants.RESPONSE_CODE.VALIDATION_ERROR'));
    }

    /**
     * Log an exception to the system logs
     */
    public static function logErrorResponse(Throwable $e, string $logMessage = 'Error occurred'): void
    {
        Log::error($logMessage . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public static function unauthorizedResponse(string $message = 'Unauthorized.'): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }
}
