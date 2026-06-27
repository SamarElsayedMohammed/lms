<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('token') && !$request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
        }

        return $next($request);
    }
}
