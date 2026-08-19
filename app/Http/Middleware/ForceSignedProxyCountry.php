<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Next.js HMAC country header when present.
 * Missing header is allowed (caller falls back to EG). Invalid signature is rejected.
 */
class ForceSignedProxyCountry
{
    protected $except = [
        'api/webhook/razorpay',
        'api/webhooks/kashier',
        'api/health',
        'api/health/*',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        foreach ($this->except as $except) {
            if ($request->is($except) || $request->is(ltrim($except, '/'))) {
                return $next($request);
            }
        }

        $headerValue = $request->header('X-Skillso-Resolved-Country');
        if (!$headerValue) {
            return $next($request);
        }

        $parts = explode('.', $headerValue);
        if (count($parts) !== 3) {
            Log::warning('ForceSignedProxyCountry: Invalid proxy signature format');
            $request->headers->remove('X-Skillso-Resolved-Country');
            return $next($request);
        }

        [$country, $timestamp, $signature] = $parts;

        if (!preg_match('/^[A-Z]{2}$/i', (string) $country) || !ctype_digit((string) $timestamp)) {
            $request->headers->remove('X-Skillso-Resolved-Country');
            return $next($request);
        }

        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('ForceSignedProxyCountry: Expired proxy signature', ['timestamp' => $timestamp]);
            $request->headers->remove('X-Skillso-Resolved-Country');
            return $next($request);
        }

        $secret = (string) (config('app.proxy_secret') ?: config('app.key'));
        if ($secret === '') {
            $request->headers->remove('X-Skillso-Resolved-Country');
            return $next($request);
        }

        $expectedSignature = hash_hmac('sha256', $country . '.' . $timestamp, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('ForceSignedProxyCountry: Invalid proxy signature', [
                'country' => $country,
            ]);
            $request->headers->remove('X-Skillso-Resolved-Country');
            return $next($request);
        }

        $request->attributes->set('verified_country_code', strtoupper($country));

        return $next($request);
    }
}
