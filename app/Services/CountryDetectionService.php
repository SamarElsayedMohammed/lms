<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CountryDetectionService - Robust, spoof-resistant country detection
 *
 * This service detects the user's country from trusted sources with the following priority:
 * 1. CF-IPCountry (Cloudflare edge header - highest priority)
 * 2. X-User-Country / X-Country (Edge proxy headers)
 * 3. X-Vercel-IP-Country (Vercel automatic geo header)
 * 4. GeoIP lookup using client IP from $request->ip()
 * 5. System default fallback country (EG)
 *
 * Security Considerations:
 * - Query and body parameter tampering (e.g. ?country_code=XX) is strictly ignored.
 * - Country codes are strictly validated against ISO 3166-1 alpha-2 format (/^[A-Z]{2}$/).
 * - Placeholder / anonymous codes (XX, T1, A1, etc.) are discarded.
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
     * 2. X-User-Country / X-Country (Edge proxy header)
     * 3. X-Vercel-IP-Country (Vercel edge)
     * 4. GeoIP lookup using client IP
     * 5. System default fallback country (EG)
     *
     * @param Request $request The incoming HTTP request
     * @return string ISO 3166-1 alpha-2 country code (uppercase)
     */
    public function detect(Request $request): string
    {
        // For testing purposes in non-production environments only
        if ($this->isTestingOverride($request)) {
            return $this->getTestCountry($request);
        }

        // Priority 1: Cloudflare CF-IPCountry header
        $country = $this->getCloudflareCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'cloudflare_cf_ipcountry');
            return $country;
        }

        // Priority 2: X-User-Country / X-Country from trusted edge proxy
        $country = $this->getCustomProxyCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'edge_x_user_country');
            return $country;
        }

        // Priority 3: X-Vercel-IP-Country from Vercel edge
        $country = $this->getVercelCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'vercel_x_vercel_ip_country');
            return $country;
        }

        // Priority 4: Direct GeoIP lookup using client IP
        $country = $this->getCountryFromGeoIP($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'geoip_lookup');
            return $country;
        }

        // Priority 5: System default fallback country (EG)
        $default = $this->getDefaultCountry();
        $this->logDetection($request, $default, 'fallback_default');
        return $default;
    }

    /**
     * Get country from Cloudflare's CF-IPCountry header
     */
    private function getCloudflareCountry(Request $request): ?string
    {
        $country = $request->header('CF-IPCountry')
            ?? $request->server('HTTP_CF_IPCOUNTRY');

        return $this->validateAndNormalize($country);
    }

    /**
     * Get country from custom edge proxy headers
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
     */
    private function getVercelCountry(Request $request): ?string
    {
        $country = $request->header('X-Vercel-IP-Country');
        return $this->validateAndNormalize($country);
    }

    /**
     * Get country from GeoIP lookup using client IP
     */
    private function getCountryFromGeoIP(Request $request): ?string
    {
        $isTesting = (getenv('APP_ENV') === 'testing')
            || (function_exists('app') && app()->bound('env') && app()->environment('testing'));

        if ($isTesting) {
            return null;
        }

        $ip = $this->getClientIp($request);
        if ($ip === null) {
            return null;
        }

        $cacheKey = "geoip_country:{$ip}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $country = $this->lookupGeoIP($ip);
        Cache::put($cacheKey, $country ?? '', self::GEOIP_CACHE_TTL);

        return $country;
    }

    /**
     * Get the actual client IP address
     */
    private function getClientIp(Request $request): ?string
    {
        $ip = $request->ip();

        if ($ip === null) {
            return null;
        }

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * Perform GeoIP lookup using external APIs
     */
    private function lookupGeoIP(string $ip): ?string
    {
        $country = $this->lookupIpApiCo($ip);
        if ($country !== null) {
            return $country;
        }

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
     * - Exactly 2 uppercase letters (ISO 3166-1 alpha-2 format: /^[A-Z]{2}$/)
     * - Not in the invalid codes list
     *
     * @param mixed $code The country code to validate
     * @return string|null Normalized uppercase country code or null if invalid
     */
    public function validateAndNormalize(mixed $code): ?string
    {
        if (!is_string($code) || $code === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        if (in_array($code, self::INVALID_COUNTRY_CODES, true)) {
            return null;
        }

        return $code;
    }

    /**
     * Get the default fallback country
     */
    public function getDefaultCountry(): string
    {
        $default = function_exists('app') && app()->bound('config')
            ? (string) config('app.default_country', 'EG')
            : 'EG';

        return strtoupper($default ?: 'EG');
    }

    /**
     * Check if testing override is enabled
     */
    private function isTestingOverride(Request $request): bool
    {
        $isProd = (getenv('APP_ENV') === 'production')
            || (function_exists('app') && app()->bound('config') && config('app.env') === 'production');

        return !$isProd && $request->has('test_country');
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
        $isDebug = function_exists('app') && app()->bound('config') ? (bool) config('app.debug') : false;
        $isProd = (getenv('APP_ENV') === 'production')
            || (function_exists('app') && app()->bound('config') && config('app.env') === 'production');

        if (!$isDebug && $isProd) {
            return;
        }

        if (function_exists('app') && app()->bound('log')) {
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
    }

    /**
     * Get debug information about headers and detection
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
