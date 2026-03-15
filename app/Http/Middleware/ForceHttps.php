<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    /**
     * Force HTTPS for all generated URLs when behind a reverse proxy.
     * Fixes Mixed Content errors (CSS/JS blocked) when page loads over HTTPS.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isSecure = $request->isSecure()
            || $request->header('X-Forwarded-Proto') === 'https'
            || str_starts_with((string) config('app.url'), 'https://');

        if ($isSecure) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
