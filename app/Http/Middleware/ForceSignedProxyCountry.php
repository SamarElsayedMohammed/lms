<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ForceSignedProxyCountry
{
    protected $except = [
        'api/webhook/razorpay',
        'api/webhooks/kashier',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
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
            Log::warning('ForceSignedProxyCountry: Missing proxy signature header');
            return response()->json(['error' => 'Missing proxy signature headers'], 401);
        }

        $parts = explode('.', $headerValue);
        if (count($parts) !== 3) {
            Log::warning('ForceSignedProxyCountry: Invalid proxy signature format');
            return response()->json(['error' => 'Invalid proxy signature format'], 401);
        }

        [$country, $timestamp, $signature] = $parts;

        // Check timestamp expiration (5 minutes max)
        if (abs(time() - (int)$timestamp) > 300) {
            Log::warning('ForceSignedProxyCountry: Expired proxy signature', ['timestamp' => $timestamp]);
            return response()->json(['error' => 'Expired proxy signature'], 401);
        }

        $secret = config('app.proxy_secret', config('app.key'));
        
        $expectedSignature = hash_hmac('sha256', $country . '.' . $timestamp, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('ForceSignedProxyCountry: Invalid proxy signature', [
                'country' => $country,
                'provided' => $signature,
                'expected' => $expectedSignature
            ]);
            return response()->json(['error' => 'Invalid proxy signature'], 401);
        }

        // Store the verified country in request attributes so GeoLocationService
        // can use it as the single authoritative source — bypassing all unsigned headers.
        $request->attributes->set('verified_country_code', strtoupper($country));

        return $next($request);
    }
}
