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
            ]);
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
        $meta = [];

        if ($data instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $meta = [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ];
            $data = $data->items();
        } elseif ($data instanceof \Illuminate\Http\Resources\Json\ResourceCollection && $data->resource instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $meta = [
                'current_page' => $data->resource->currentPage(),
                'last_page' => $data->resource->lastPage(),
                'per_page' => $data->resource->perPage(),
                'total' => $data->resource->total(),
            ];
            $data = $data->resolve();
        } elseif (is_array($data) && isset($data['data']) && isset($data['current_page'])) {
            $meta = [
                'current_page' => $data['current_page'],
                'last_page' => $data['last_page'] ?? null,
                'per_page' => $data['per_page'] ?? null,
                'total' => $data['total'] ?? null,
            ];
            if (array_key_exists('unread_count', $data)) {
                $meta['unread_count'] = $data['unread_count'];
            }
            $standardPaginationKeys = [
                'current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url',
                'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total', 'unread_count'
            ];
            $extraKeys = array_diff(array_keys($data), $standardPaginationKeys);
            if (empty($extraKeys)) {
                $data = $data['data'];
            }
        }

        $code ??= (int) config('constants.RESPONSE_CODE.SUCCESS');
        $response = [
            'success' => true,
            'status' => true,
            'error' => false,
            'message' => trans($message),
            'data' => $data ?? (object) [],
            'code' => $code,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        $jsonResponse = response()->json([...$response, ...$customData], $code);

        foreach ($headers as $headerName => $headerValue) {
            $jsonResponse->header($headerName, $headerValue);
        }

        self::applySafeCorsHeaders($jsonResponse);

        throw new HttpResponseException($jsonResponse);
    }

    public static function errorResponse(
        string $message = 'Error Occurred',
        mixed $data = null,
        int|null $code = null,
        Throwable|null $exception = null,
        string|null $redirectUrl = null,
    ): void {
        // Controllers commonly catch Throwable around the whole action. Preserve
        // responses intentionally raised by successResponse/validationError
        // instead of wrapping them as an unrelated HTTP 500 response.
        if ($exception instanceof HttpResponseException) {
            throw $exception;
        }

        $code ??= (int) config('constants.RESPONSE_CODE.ERROR');
        $response = [
            'success' => false,
            'status' => false,
            'error' => true,
            'message' => trans($message),
            'data' => $data ?? (object) [],
            'code' => $code,
        ];

        // Ensure errors object is present for validation structure
        if (is_array($data) || is_object($data)) {
            $response['errors'] = $data;
        }

        if ($redirectUrl) {
            $response['redirect_url'] = $redirectUrl;
        }

        if (app()->environment('local') && config('app.debug') === true && $exception instanceof Throwable) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
            ];
        }

        $jsonResponse = response()->json($response, $code);

        self::applySafeCorsHeaders($jsonResponse);

        throw new HttpResponseException($jsonResponse);
    }

    public static function validationError(string $message = 'Error Occurred', mixed $data = null): void
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

        try {
            Log::error($logMessage . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (Throwable) {
            // Suppress logging error to prevent crash
        }
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

    public static function forbidden(string $message = 'Forbidden.', mixed $data = null): void
    {
        self::errorResponse($message, $data, 403);
    }

    public static function unauthorizedResponse(string $message = 'Unauthorized.'): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    private static function applySafeCorsHeaders($jsonResponse): void
    {
        try {
            $origin = request()->header('Origin');
            if ($origin === null || $origin === '') {
                return;
            }

            $allowed = config('cors.allowed_origins', []);
            if (!is_array($allowed) || $allowed === []) {
                return;
            }

            if (in_array('*', $allowed, true) || in_array($origin, $allowed, true)) {
                $allowAny = in_array('*', $allowed, true);
                $jsonResponse->header('Access-Control-Allow-Origin', $allowAny ? '*' : $origin);
                if (!$allowAny) {
                    $jsonResponse->header('Access-Control-Allow-Credentials', 'true');
                }
            }
        } catch (Throwable) {
            // Ignore CORS header errors - don't break the response
        }
    }
}
