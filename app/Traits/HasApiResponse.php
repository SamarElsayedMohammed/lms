<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HasApiResponse
{
    /**
     * @param array<string, mixed> $meta
     */
    protected function ok(
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

    protected function error(
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

    /**
     * @param array<string, mixed> $meta
     */
    protected function created(mixed $data = null, string $message = 'Created', array $meta = []): JsonResponse
    {
        return $this->ok($data, $message, 201, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function accepted(mixed $data = null, string $message = 'Accepted', array $meta = []): JsonResponse
    {
        return $this->ok($data, $message, 202, $meta);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function badRequest(string $message = 'Bad Request', mixed $data = null): JsonResponse
    {
        return $this->error($message, $data, 400);
    }

    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, null, 401);
    }

    protected function paymentRequired(string $message = 'Payment Required'): JsonResponse
    {
        return $this->error($message, null, 402);
    }

    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, null, 403);
    }

    protected function notFound(string $message = 'Not Found'): JsonResponse
    {
        return $this->error($message, null, 404);
    }

    protected function requestTimeout(string $message = 'Request Timeout'): JsonResponse
    {
        return $this->error($message, null, 408);
    }

    protected function conflict(string $message = 'Conflict'): JsonResponse
    {
        return $this->error($message, null, 409);
    }

    protected function unprocessableEntity(string $message = 'Unprocessable Entity', mixed $data = null): JsonResponse
    {
        return $this->error($message, $data, 422);
    }

    protected function tooManyRequests(string $message = 'Too Many Requests'): JsonResponse
    {
        return $this->error($message, null, 429);
    }

    protected function serverError(
        string $message = 'Internal Server Error',
        null|Throwable $exception = null,
    ): JsonResponse {
        return $this->error($message, null, 500, $exception);
    }

    /**
     * Log an exception without sending a response
     */
    protected function logError(Throwable $e, string $context = '[API Error]'): void
    {
        Log::error($context . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
