<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            // Depending on strictness, we might abort or just continue
            // For now, if it's strictly required by spec, we might abort
            // But let's allow if not provided, or strictly require it?
            // "A middleware must check the Idempotency-Key header on all registrations to prevent double-charging a wallet on network retries."
            return response()->json(['message' => 'Idempotency-Key header is required'], 400);
        }

        // Use cache to lock this idempotency key for 24 hours (1440 minutes)
        $cacheKey = 'idempotency_' . $idempotencyKey;

        if (Cache::has($cacheKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate request detected.',
            ], 409); // Conflict
        }

        // Lock the key
        Cache::put($cacheKey, true, now()->addHours(24));

        $response = $next($request);

        // If the request fails (e.g. 500 error), we might want to release the lock
        // so the user can retry.
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 409) {
             Cache::forget($cacheKey);
        }

        return $response;
    }
}
