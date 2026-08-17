<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CountryDetectionService - Robust country detection for proxy environments
 *
 * This service detects the user's country from various sources with the following priority:
 * 1. CF-IPCountry (Cloudflare header - most reliable when behind Cloudflare)
 * 2. X-User-Country (Custom header forwarded by our Next.js frontend proxy)
 * 3. X-Vercel-IP-Country (Vercel's automatic geo header)
 * 4. GeoIP lookup using client IP from $request->ip()
 * 5. Fallback to configured default country
 *
 * Security Considerations:
 * - All proxy headers can be spoofed if not properly secured
 * - We trust these headers because our backend is only accessible through our frontend proxy
 * - The TrustProxies middleware must be configured to accept X-Forwarded-* headers
 * - Country codes are validated against ISO 3166-1 alpha-2 format
 *
 * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/#cf-ipcountry
 * @see https://vercel.com/docs/edge-network/headers#x-vercel-ip-country
 */
final class CountryDetectionService
{
    /**
     * ISO 3166-1 alpha-2 country codes that should be ignored
     * XX = Unknown (Cloudflare)
     * T1 = Tor exit node (Cloudflare)
     * A1 = Anonymous Proxy (MaxMind)
     * A2 = Satellite Provider (MaxMind)
     */
    private const INVALID_COUNTRY_CODES = ['XX', 'T1', 'A1', 'A2', 'O1', 'EU', 'AP'];

    /**
     * Cache TTL for GeoIP lookups (in seconds)
     */
    private const GEOIP_CACHE_TTL = 3600; // 1 hour

    /**
     * Detect user's country from the request
     *
     * Priority order:
     * 1. CF-IPCountry (Cloudflare)
     * 2. X-User-Country (Frontend proxy custom header)
     * 3. X-Vercel-IP-Country (Vercel)
     * 4. GeoIP lookup using client IP
     * 5. Fallback to default country
     *
     * @param Request $request The incoming HTTP request
     * @return string ISO 3166-1 alpha-2 country code (uppercase)
     */
    public function detect(Request $request): string
    {
        // For testing purposes in non-production environments
        if ($this->isTestingOverride($request)) {
            return $this->getTestCountry($request);
        }

        // Priority 1: Cloudflare CF-IPCountry header
        // This is the most reliable when traffic goes through Cloudflare
        $country = $this->getCloudflareCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'cloudflare_cf_ipcountry');
            return $country;
        }

        // Priority 2: X-User-Country - Custom header from our frontend proxy
        // Our Next.js frontend reads geo headers and forwards them
        $country = $this->getCustomProxyCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'frontend_x_user_country');
            return $country;
        }

        // Priority 3: X-Vercel-IP-Country - Vercel's automatic geo header
        $country = $this->getVercelCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'vercel_x_vercel_ip_country');
            return $country;
        }

        // Priority 4: GeoIP lookup using client IP
        // This uses external APIs as fallback when headers are not available
        $country = $this->getCountryFromGeoIP($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'geoip_lookup');
            return $country;
        }

        // Priority 5: Fallback to configured default country
        $default = $this->getDefaultCountry();
        $this->logDetection($request, $default, 'fallback_default');
        return $default;
    }

    /**
     * Get country from Cloudflare's CF-IPCountry header
     *
     * CF-IPCountry contains the ISO 3166-1 alpha-2 code of the country
     * where Cloudflare determines the request originated.
     *
     * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/#cf-ipcountry
     */
    private function getCloudflareCountry(Request $request): ?string
    {
        // Try both header() and server() methods for compatibility
        // Some proxies/servers may convert headers to HTTP_* format
        $country = $request->header('CF-IPCountry')
            ?? $request->server('HTTP_CF_IPCOUNTRY');

        return $this->validateAndNormalize($country);
    }

    /**
     * Get country from our custom X-User-Country header
     *
     * This header is set by our Next.js frontend proxy which reads
     * the original geo headers from Cloudflare/Vercel and forwards them.
     */
    private function getCustomProxyCountry(Request $request): ?string
    {
        $country = $request->header('X-User-Country')
            ?? $request->header('X-Country')
            ?? $request->header('X-Country-Code');

        return $this->validateAndNormalize($country);
    }

    /**
     * Get country from Vercel's X-Vercel-IP-Country header
     *
     * When deployed on Vercel, this header contains the country code
     * determined by Vercel's edge network.
     *
     * @see https://vercel.com/docs/edge-network/headers#x-vercel-ip-country
     */
    private function getVercelCountry(Request $request): ?string
    {
        $country = $request->header('X-Vercel-IP-Country');
        return $this->validateAndNormalize($country);
    }

    /**
     * Get country from GeoIP lookup using client IP
     *
     * This method uses external GeoIP APIs to determine the country
     * based on the client's IP address. Results are cached to reduce
     * API calls and improve performance.
     */
    private function getCountryFromGeoIP(Request $request): ?string
    {
        // Skip GeoIP lookups in testing environment
        if (app()->environment('testing')) {
            return null;
        }

        $ip = $this->getClientIp($request);
        if ($ip === null) {
            return null;
        }

        // Check cache first to avoid excessive API calls
        $cacheKey = "geoip_country:{$ip}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null; // Handle empty string cache values
        }

        // Try external GeoIP services
        $country = $this->lookupGeoIP($ip);

        // Cache the result (even if null, to prevent repeated failed lookups)
        Cache::put($cacheKey, $country ?? '', self::GEOIP_CACHE_TTL);

        return $country;
    }

    /**
     * Get the actual client IP address
     *
     * Laravel's $request->ip() method respects the TrustProxies middleware
     * configuration and properly handles X-Forwarded-For headers.
     */
    private function getClientIp(Request $request): ?string
    {
        $ip = $request->ip();

        // Validate IP is public (not private/reserved range)
        if ($ip === null) {
            return null;
        }

        // Skip localhost IPs
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        // Validate it's a proper public IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * Perform GeoIP lookup using external APIs
     *
     * We use multiple providers with fallback:
     * 1. ipapi.co (1000 requests/day free)
     * 2. ip-api.com (45 requests/minute free)
     */
    private function lookupGeoIP(string $ip): ?string
    {
        // Try ipapi.co first
        $country = $this->lookupIpApiCo($ip);
        if ($country !== null) {
            return $country;
        }

        // Fallback to ip-api.com
        return $this->lookupIpApiCom($ip);
    }

    /**
     * Lookup country using ipapi.co
     */
    private function lookupIpApiCo(string $ip): ?string
    {
        try {
            $url = "https://ipapi.co/{$ip}/country/";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'SkillsoLMS/1.0',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                return $this->validateAndNormalize(trim($response));
            }
        } catch (\Throwable $e) {
            Log::warning('CountryDetectionService: ipapi.co lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Lookup country using ip-api.com
     */
    private function lookupIpApiCom(string $ip): ?string
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=countryCode";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT => 'SkillsoLMS/1.0',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                if (isset($data['countryCode'])) {
                    return $this->validateAndNormalize($data['countryCode']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CountryDetectionService: ip-api.com lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Validate and normalize a country code
     *
     * Ensures the country code is:
     * - Exactly 2 characters
     * - Only alphabetic characters (A-Z)
     * - Not in the invalid codes list
     * - Uppercase
     *
     * @param mixed $code The country code to validate
     * @return string|null Normalized uppercase country code or null if invalid
     */
    private function validateAndNormalize(mixed $code): ?string
    {
        // Must be a non-empty string
        if (!is_string($code) || $code === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        // Must be exactly 2 characters
        if (strlen($code) !== 2) {
            return null;
        }

        // Must be alphabetic only
        if (!ctype_alpha($code)) {
            return null;
        }

        // Must not be a known invalid/placeholder code
        if (in_array($code, self::INVALID_COUNTRY_CODES, true)) {
            return null;
        }

        return $code;
    }

    /**
     * Get the default fallback country
     */
    private function getDefaultCountry(): string
    {
        return strtoupper(config('app.default_country', 'EG'));
    }

    /**
     * Check if testing override is enabled
     */
    private function isTestingOverride(Request $request): bool
    {
        return !app()->environment('production')
            && $request->has('test_country');
    }

    /**
     * Get test country from request
     */
    private function getTestCountry(Request $request): string
    {
        $country = $this->validateAndNormalize($request->query('test_country'));
        return $country ?? $this->getDefaultCountry();
    }

    /**
     * Log country detection for debugging
     */
    private function logDetection(Request $request, string $country, string $source): void
    {
        // Only log in debug mode or non-production environments
        if (!config('app.debug') && app()->environment('production')) {
            return;
        }

        Log::info('CountryDetectionService: Country detected', [
            'detected_country' => $country,
            'detection_source' => $source,
            'headers_received' => [
                'CF-IPCountry' => $request->header('CF-IPCountry'),
                'X-User-Country' => $request->header('X-User-Country'),
                'X-Vercel-IP-Country' => $request->header('X-Vercel-IP-Country'),
                'X-Forwarded-For' => $request->header('X-Forwarded-For'),
            ],
            'client_ip' => $request->ip(),
            'remote_addr' => $request->server('REMOTE_ADDR'),
        ]);
    }

    /**
     * Get debug information about headers and detection
     *
     * Useful for troubleshooting in staging/development environments
     */
    public function debug(Request $request): array
    {
        return [
            'detected_country' => $this->detect($request),
            'detection_priority' => [
                '1_cloudflare' => $this->getCloudflareCountry($request),
                '2_x_user_country' => $this->getCustomProxyCountry($request),
                '3_vercel' => $this->getVercelCountry($request),
                '4_geoip' => $this->getCountryFromGeoIP($request),
                '5_default' => $this->getDefaultCountry(),
            ],
            'headers' => [
                'CF-IPCountry' => $request->header('CF-IPCountry'),
                'X-User-Country' => $request->header('X-User-Country'),
                'X-Vercel-IP-Country' => $request->header('X-Vercel-IP-Country'),
                'X-Forwarded-For' => $request->header('X-Forwarded-For'),
                'X-Real-IP' => $request->header('X-Real-IP'),
            ],
            'ip_info' => [
                'request_ip' => $request->ip(),
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'is_valid_public_ip' => $this->getClientIp($request) !== null,
            ],
            'config' => [
                'default_country' => $this->getDefaultCountry(),
                'environment' => app()->environment(),
            ],
        ];
    }
}
