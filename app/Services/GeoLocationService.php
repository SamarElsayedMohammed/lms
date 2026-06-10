<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class GeoLocationService
{
    /**
     * Get country code from request (Secure IP detection with signed proxy header support)
     */
    public function getCountryCodeFromRequest(Request $request): null|string
    {
        // 1. Manual override for testing purposes ONLY in non-production
        if (!app()->environment('production') && $request->has('test_country')) {
            return strtoupper($request->query('test_country'));
        }

        // 2. High Priority: Signed Internal Proxy Header (e.g. Next.js proxy)
        $proxyCountry = $this->getSignedProxyCountry($request);
        if ($proxyCountry) {
            return $proxyCountry;
        }

        // 3. Cloudflare Country Header (Only trusted if behind Cloudflare)
        // Note: For production, ensure Cloudflare Authenticated Origin Pulls are enabled
        // or configure trusted proxies in App\Http\Middleware\TrustProxies
        $cfCountry = $request->server('HTTP_CF_IPCOUNTRY');
        if ($cfCountry && strlen($cfCountry) === 2 && $cfCountry !== 'XX' && $cfCountry !== 'T1') {
            // Check if request came through Cloudflare (simple check, robust check requires IP range validation)
            if ($request->server('HTTP_CF_CONNECTING_IP')) {
                return strtoupper($cfCountry);
            }
        }

        // 4. Detect Real IP Address
        $ipAddress = $this->getRealIpAddress($request);
        
        // --- Debug Logging ---
        if (config('app.debug')) {
            Log::info('Detecting country for IP', [
                'ip' => $ipAddress,
                'cf_country' => $cfCountry,
                'user_agent' => $request->userAgent(),
                'headers' => collect($request->server())->filter(fn($v, $k) => str_starts_with($k, 'HTTP_'))->toArray()
            ]);
        }

        if ($ipAddress) {
            $countryCode = $this->getCountryCodeFromIp($ipAddress);
            if ($countryCode) {
                return $countryCode;
            }
        }

        // 5. Fallback to user's country code if IP detection fails
        $authUser = auth('sanctum')->user();
        if ($authUser?->country_code) {
            return strtoupper($authUser->country_code);
        }

        return null;
    }

    /**
     * Verify internal signed proxy headers
     */
    private function getSignedProxyCountry(Request $request): ?string
    {
        $country = $request->header('X-Skillso-Resolved-Country');
        $signature = $request->header('X-Skillso-Country-Signature');
        $timestamp = $request->header('X-Skillso-Country-Timestamp');

        if (!$country || !$signature || !$timestamp) {
            return null;
        }

        // Reject if older than 5 minutes
        if (abs(time() - (int)$timestamp) > 300) {
            Log::warning('Expired proxy country timestamp', ['timestamp' => $timestamp]);
            return null;
        }

        $secret = config('app.proxy_secret', config('app.key')); // Fallback to app key if no specific secret
        $expectedSignature = hash_hmac('sha256', $country . $timestamp, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid proxy country signature', [
                'country' => $country,
                'provided' => $signature,
                'expected' => $expectedSignature
            ]);
            return null;
        }

        return strtoupper($country);
    }

    /**
     * Get real IP address from request securely (strips user-controlled headers unless trusted)
     */
    public function getRealIpAddress(Request $request): null|string
    {
        // Laravel's $request->ip() already respects trusted proxies configured in TrustProxies middleware.
        // It's safer to rely on it than manually parsing headers which can be spoofed.
        $ip = $request->ip();
        
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }

        return null;
    }

    /**
     * Get country code from IP address using geolocation APIs
     */
    public function getCountryCodeFromIp(null|string $ipAddress): null|string
    {
        if (!$ipAddress) {
            return null;
        }

        if (app()->environment('testing')) {
            return null;
        }

        try {
            if (
                filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                === false
            ) {
                return null;
            }

            // Try ipapi.co first (free, 1000 requests/day)
            $countryCode = $this->fetchFromIpApiCo($ipAddress);
            if ($countryCode) {
                return $countryCode;
            }

            // Fallback to ip-api.com (free, 45 requests/minute)
            return $this->fetchFromIpApiCom($ipAddress);
        } catch (\Exception $e) {
            Log::warning('Failed to get country from IP: ' . $e->getMessage(), [
                'ip' => $ipAddress,
            ]);
        }

        return null;
    }

    /**
     * Fetch country code from ipapi.co
     */
    private function fetchFromIpApiCo(string $ipAddress): null|string
    {
        $url = "https://ipapi.co/{$ipAddress}/country/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response) && !$curlError) {
            $countryCode = trim($response);
            if (strlen($countryCode) === 2 && ctype_alpha($countryCode)) {
                return strtoupper($countryCode);
            }
        }

        return null;
    }

    /**
     * Fetch country code from ip-api.com
     */
    private function fetchFromIpApiCom(string $ipAddress): null|string
    {
        $url = "http://ip-api.com/json/{$ipAddress}?fields=countryCode";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && !$curlError) {
            $data = json_decode($response, true);
            if (isset($data['countryCode']) && !empty($data['countryCode'])) {
                $countryCode = strtoupper((string) $data['countryCode']);
                if (strlen($countryCode) === 2 && ctype_alpha($countryCode)) {
                    return $countryCode;
                }
            }
        }

        return null;
    }
}
