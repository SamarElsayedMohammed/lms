<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken()) {
            $token = $request->query('token') ?? $request->query('api_token') ?? $request->query('auth_token');
            if ($token && is_string($token) && trim($token) !== '') {
                $request->headers->set('Authorization', 'Bearer ' . trim($token));
            }
        }

        return $next($request);
    }
}
