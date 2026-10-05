<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class ValidateSignedHeartbeatToken
{
    /**
     * Maximum valid lifetime of a signed heartbeat token in seconds.
     */
    public const TOKEN_TTL_SECONDS = 60;

    /**
     * Generate a short-lived, single-purpose signed heartbeat token bound to user and lesson.
     */
    public static function generateToken(int $userId, int|string $lessonId, int $ttlSeconds = self::TOKEN_TTL_SECONDS): string
    {
        $timestamp = now()->timestamp;
        $appKey = (string) config('app.key');
        $payload = "{$userId}:{$lessonId}:{$timestamp}";
        $signature = hash_hmac('sha256', $payload, $appKey);

        return base64_encode(json_encode([
            'uid' => $userId,
            'lid' => (string) $lessonId,
            'ts'  => $timestamp,
            'sig' => $signature,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. If user is already authenticated or Authorization Bearer header is present,
        // let standard Sanctum handle it and ensure no query tokens leak.
        $hasBearer = $request->bearerToken() !== null && trim((string) $request->bearerToken()) !== '';

        $rawQueryToken = $request->query('heartbeat_token')
            ?? $request->query('token')
            ?? $request->query('api_token')
            ?? $request->query('auth_token');

        // Always scrub query tokens immediately from request query and server arrays
        // to prevent leakage into access logs, error trackers, or exception traces.
        if ($rawQueryToken !== null) {
            $request->query->remove('token');
            $request->query->remove('heartbeat_token');
            $request->query->remove('api_token');
            $request->query->remove('auth_token');

            if ($request->server->has('QUERY_STRING')) {
                $cleanedQuery = preg_replace(
                    '/(?:&|^)(?:heartbeat_token|token|api_token|auth_token)=[^&]*/',
                    '',
                    (string) $request->server->get('QUERY_STRING')
                );
                $request->server->set('QUERY_STRING', ltrim((string) $cleanedQuery, '&'));
            }
        }

        // If a query token is supplied, strictly validate the signed token
        if ($rawQueryToken === null || trim((string) $rawQueryToken) === '') {
            // No query token present: standard header/session auth path
            if (Auth::check()) {
                $response = $next($request);
                $response->headers->set('Referrer-Policy', 'no-referrer');
                return $response;
            }

            if ($hasBearer) {
                $user = auth('sanctum')->user();
                if ($user !== null) {
                    Auth::setUser($user);
                    $request->setUserResolver(static fn () => $user);
                    $response = $next($request);
                    $response->headers->set('Referrer-Policy', 'no-referrer');
                    return $response;
                }
            }

            return response()->json([
                'success' => false,
                'error'   => true,
                'message' => 'Unauthenticated.',
                'code'    => 401,
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        // 2. Validate single-purpose signed token
        $tokenString = trim((string) $rawQueryToken);
        $decoded = null;
        try {
            $json = base64_decode($tokenString, true);
            if ($json !== false) {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable) {
            $decoded = null;
        }

        if (!is_array($decoded) || !isset($decoded['uid'], $decoded['lid'], $decoded['ts'], $decoded['sig'])) {
            Log::warning('Heartbeat token rejected: Malformed or unsigned plain bearer token passed in query string', [
                'ip'     => $request->ip(),
                'path'   => $request->path(),
                'reason' => 'UNSIGNED_QUERY_TOKEN',
            ]);

            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'INVALID_HEARTBEAT_TOKEN',
                'message' => 'Query bearer tokens are disabled. Use Authorization Bearer header or valid signed heartbeat token.',
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        $userId = (int) $decoded['uid'];
        $tokenLessonId = (string) $decoded['lid'];
        $timestamp = (int) $decoded['ts'];
        $providedSignature = (string) $decoded['sig'];

        // Validate HMAC signature
        $appKey = (string) config('app.key');
        $expectedSignature = hash_hmac('sha256', "{$userId}:{$tokenLessonId}:{$timestamp}", $appKey);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            Log::warning('Heartbeat token rejected: Invalid signature', [
                'user_id' => $userId,
                'lesson'  => $tokenLessonId,
                'reason'  => 'INVALID_SIGNATURE',
            ]);

            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'INVALID_HEARTBEAT_TOKEN',
                'message' => 'Heartbeat token signature verification failed.',
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        // Validate expiry (<= 60 seconds)
        $nowTs = now()->timestamp;
        if (($nowTs - $timestamp) > self::TOKEN_TTL_SECONDS || ($timestamp - $nowTs) > 10) {
            Log::warning('Heartbeat token rejected: Token expired', [
                'user_id' => $userId,
                'lesson'  => $tokenLessonId,
                'age_sec' => $nowTs - $timestamp,
                'reason'  => 'TOKEN_EXPIRED',
            ]);

            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'HEARTBEAT_TOKEN_EXPIRED',
                'message' => 'Heartbeat token has expired (TTL 60s).',
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        // Validate lesson binding: must match route lesson parameter
        $routeLesson = $request->route('lesson') ?? $request->route('lectureId') ?? $request->route('lessonId');
        $routeLessonParam = $routeLesson instanceof \Illuminate\Database\Eloquent\Model
            ? (string) $routeLesson->getKey()
            : (string) ($routeLesson ?? '');

        if ($routeLessonParam !== '' && $routeLessonParam !== $tokenLessonId) {
            Log::warning('Heartbeat token rejected: Foreign lesson mismatch', [
                'user_id'         => $userId,
                'token_lesson'    => $tokenLessonId,
                'route_lesson'    => $routeLessonParam,
                'reason'          => 'FOREIGN_LESSON_TOKEN',
            ]);

            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'FOREIGN_LESSON_TOKEN',
                'message' => 'Heartbeat token is not valid for this lesson.',
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        // Authenticate the user for this request
        $user = User::find($userId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'USER_NOT_FOUND',
                'message' => 'User associated with heartbeat token does not exist.',
            ], 401)->header('Referrer-Policy', 'no-referrer');
        }

        Auth::setUser($user);
        try {
            auth('sanctum')->setUser($user);
        } catch (\Throwable) {
            // Guard not initialized yet
        }
        $request->setUserResolver(static fn () => $user);

        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
