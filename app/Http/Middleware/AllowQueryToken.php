<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated Legacy middleware replaced by ValidateSignedHeartbeatToken.
 * Query tokens are strictly disabled globally to prevent Authorization header leakage.
 */
class AllowQueryToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Unsafe query token injection is disabled. Forward directly to next handler.
        return $next($request);
    }
}
