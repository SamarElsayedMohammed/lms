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
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        if (!$idempotencyKey) {
            // Depending on strictness, we might abort or just continue
            // For now, if it's strictly required by spec, we might abort
            // But let's allow if not provided, or strictly require it?
            // "A middleware must check the Idempotency-Key header on all registrations to prevent double-charging a wallet on network retries."
            return response()->json(['message' => 'Idempotency-Key header is required'], 400);
        }

        if (strlen($idempotencyKey) > 255) {
            return response()->json(['message' => 'Idempotency-Key is too long'], 422);
        }

        // Scope keys to user and route. A raw, global cache key lets two users
        // interfere with one another and the has()/put() pair is race-prone.
        $cacheKey = 'idempotency:' . hash('sha256', implode('|', [
            (string) optional($request->user())->getAuthIdentifier(),
            $request->method(),
            $request->path(),
            $idempotencyKey,
        ]));

        if (!Cache::add($cacheKey, ['state' => 'processing'], now()->addHours(24))) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['state'] ?? null) === 'completed') {
                return response(
                    (string) ($cached['content'] ?? ''),
                    (int) ($cached['status'] ?? 200),
                    is_array($cached['headers'] ?? null) ? $cached['headers'] : [],
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'Request is already being processed.',
                'reason' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
            ], 409); // Conflict
        }

        $response = $next($request);

        // If the request fails (e.g. 500 error), we might want to release the lock
        // so the user can retry.
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 409) {
             Cache::forget($cacheKey);
        } elseif ($response->getStatusCode() < 400) {
            // Store the completed JSON response so a browser retry receives the
            // original outcome instead of a false duplicate-payment error.
            Cache::put($cacheKey, [
                'state' => 'completed',
                'status' => $response->getStatusCode(),
                'content' => $response->getContent(),
                'headers' => [
                    'Content-Type' => $response->headers->get('Content-Type', 'application/json'),
                ],
            ], now()->addHours(24));
        }

        return $response;
    }
}
