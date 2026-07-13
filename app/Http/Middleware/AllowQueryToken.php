<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        // Security Fix: Do not allow tokens in query string to prevent token leakage

        return $next($request);
    }
}
