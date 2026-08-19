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
    public function handle(Request $request, Closure $next, string $onReplay = 'replay')
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

        $existing = Cache::get($cacheKey);
        if (is_array($existing) && ($existing['state'] ?? null) === 'completed') {
            if ($onReplay === 'conflict') {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => 'طلب مكرر: تم تسجيل هذا الويبنار مسبقاً.',
                    'reason' => 'IDEMPOTENCY_CONFLICT',
                ], 409)->header('Idempotent-Replay', 'true');
            }

            return response($existing['content'] ?? '', (int) ($existing['status'] ?? 200))
                ->header('Content-Type', $existing['headers']['Content-Type'] ?? 'application/json')
                ->header('Idempotent-Replay', 'true');
        }

        if (is_array($existing) && ($existing['state'] ?? null) === 'processing') {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'Duplicate request detected with the same Idempotency-Key.',
                'reason' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        }

        if (!Cache::add($cacheKey, ['state' => 'processing'], now()->addHours(24))) {
            $race = Cache::get($cacheKey);
            if (is_array($race) && ($race['state'] ?? null) === 'completed') {
                if ($onReplay === 'conflict') {
                    return response()->json([
                        'success' => false,
                        'status' => false,
                        'message' => 'طلب مكرر: تم تسجيل هذا الويبنار مسبقاً.',
                        'reason' => 'IDEMPOTENCY_CONFLICT',
                    ], 409)->header('Idempotent-Replay', 'true');
                }

                return response($race['content'] ?? '', (int) ($race['status'] ?? 200))
                    ->header('Content-Type', $race['headers']['Content-Type'] ?? 'application/json')
                    ->header('Idempotent-Replay', 'true');
            }

            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'Duplicate request detected with the same Idempotency-Key.',
                'reason' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
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
