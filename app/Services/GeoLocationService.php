<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class GeoLocationService
{
    /**
     * Get country code from request (IP detection with user fallback)
     */
    public function getCountryCodeFromRequest(Request $request): null|string
    {
        $ipAddress = $this->getRealIpAddress($request);
        if ($ipAddress) {
            $countryCode = $this->getCountryCodeFromIp($ipAddress);
            if ($countryCode) {
                return $countryCode;
            }
        }

        // Fallback to user's country code if IP detection fails
        $authUser = auth('sanctum')->user();

        return $authUser?->country_code ?? null;
    }

    /**
     * Get real IP address from request (handles proxies and load balancers)
     */
    public function getRealIpAddress(Request $request): null|string
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP', // Nginx proxy
            'HTTP_X_FORWARDED_FOR', // Standard proxy header
            'HTTP_X_FORWARDED', // Alternative proxy header
            'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
            'HTTP_CLIENT_IP', // Some proxies
        ];

        foreach ($ipHeaders as $header) {
            $ip = $request->server($header);
            if ($ip) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

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
