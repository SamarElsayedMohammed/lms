<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CountryDetectionService - Robust, spoof-resistant country detection
 *
 * Priority:
 * 0. HMAC-signed X-Skillso-Resolved-Country (Next.js proxy)
 * 1. CF-IPCountry + CF-Connecting-IP (real Cloudflare)
 * 2. X-User-Country / X-Country (Next.js proxy when HMAC is unset)
 * 3. X-Vercel-IP-Country
 * 4. GeoIP lookup
 * 5. Default EG (config app.default_country)
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
     * Priority order (unknown country → EG):
     * 0. Signed proxy header
     * 1. CF-IPCountry with CF-Connecting-IP
     * 2. X-User-Country / X-Country
     * 3. X-Vercel-IP-Country
     * 4. GeoIP
     * 5. Default EG
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

        $verified = $request->attributes->get('verified_country_code');
        if (is_string($verified)) {
            $normalizedVerified = $this->validateAndNormalize($verified);
            if ($normalizedVerified !== null) {
                $this->logDetection($request, $normalizedVerified, 'signed_proxy');
                return $normalizedVerified;
            }
        }

        // Priority 0: HMAC-signed proxy country (Next.js → Laravel). Authoritative when present.
        $country = $this->getSignedProxyCountry($request);
        if ($country !== null) {
            $this->logDetection($request, $country, 'signed_proxy');
            return $country;
        }

        // Unsigned CF / X-User-Country / X-Vercel / X-Forwarded-For are client-spoofable
        // when trustProxies=* or the API is hit directly. Ignore them.
        // Country for pricing comes from the signed proxy or EG.

        // Priority 1: System default fallback country (EG)
        $default = $this->getDefaultCountry();
        $this->logDetection($request, $default, 'fallback_default');
        return $default;
    }

    /**
     * Get country from Cloudflare's CF-IPCountry header.
     * Require CF-Connecting-IP so a bare CF-IPCountry cannot be forged by a client.
     */
    private function getCloudflareCountry(Request $request): ?string
    {
        $connectingIp = $request->header('CF-Connecting-IP')
            ?? $request->server('HTTP_CF_CONNECTING_IP');
        if (!$connectingIp) {
            return null;
        }

        $country = $request->header('CF-IPCountry')
            ?? $request->server('HTTP_CF_IPCOUNTRY');

        return $this->validateAndNormalize($country);
    }

    /**
     * Accept either combined `CC.timestamp.signature` or separate Skillso proxy headers.
     */
    private function getSignedProxyCountry(Request $request): ?string
    {
        $headerValue = $request->header('X-Skillso-Resolved-Country');
        if (!$headerValue) {
            return null;
        }

        $parts = explode('.', $headerValue);
        if (count($parts) === 3) {
            [$country, $timestamp, $signature] = $parts;
            if ($this->verifyProxySignature($country, $timestamp, $signature, $country . '.' . $timestamp)) {
                return $this->validateAndNormalize($country);
            }
        }

        $timestamp = $request->header('X-Skillso-Country-Timestamp');
        $signature = $request->header('X-Skillso-Country-Signature');
        if ($timestamp && $signature) {
            $payloadWithDot = $headerValue . '.' . $timestamp;
            $payloadNoDot = $headerValue . $timestamp;
            if (
                $this->verifyProxySignature($headerValue, $timestamp, $signature, $payloadWithDot)
                || $this->verifyProxySignature($headerValue, $timestamp, $signature, $payloadNoDot)
            ) {
                return $this->validateAndNormalize($headerValue);
            }
        }

        return null;
    }

    private function verifyProxySignature(string $country, string $timestamp, string $signature, string $payload): bool
    {
        if ($this->validateAndNormalize($country) === null) {
            return false;
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $secret = (string) (config('app.proxy_secret') ?: config('app.key'));
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
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
        if (!$isDebug) {
            return;
        }

        if (function_exists('app') && app()->bound('log')) {
            Log::debug('CountryDetectionService: Country detected', [
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
                '0_signed_proxy' => $this->getSignedProxyCountry($request),
                '1_default' => $this->getDefaultCountry(),
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
