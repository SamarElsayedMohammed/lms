<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            try {
                // Explicitly authenticate via Sanctum so Auth::user() is populated
                Auth::guard('sanctum')->authenticate();
            } catch (\Throwable $e) {
                // Invalid or expired token — treat as guest (optional auth)
            }
        }
        return $next($request);
    }
}
