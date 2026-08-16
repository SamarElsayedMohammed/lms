<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccessToken
{
    /**
     * Handle an incoming request.
     *
     * Enforces strict token type isolation:
     * - Refresh tokens ('token_type' === 'refresh') can ONLY be used on the refresh-token endpoint.
     * - Refresh tokens cannot be used to access normal protected API endpoints.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user !== null) {
            $token = $user->currentAccessToken();

            if ($token !== null) {
                $isRefreshRoute = $request->is('api/refresh-token') || $request->is('refresh-token');

                if (!$isRefreshRoute && ($token->token_type ?? 'access') === 'refresh') {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invalid token type. Refresh tokens cannot be used to access regular API endpoints.',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
