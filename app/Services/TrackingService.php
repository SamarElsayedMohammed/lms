<?php

namespace App\Services;

use App\Models\MarketingPixel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    /**
     * Send event to Facebook Conversion API (CAPI)
     */
    public static function sendFacebookEvent(string $eventName, array $userData = [], array $customData = [])
    {
        $pixel = MarketingPixel::where('platform', 'facebook')->where('is_active', true)->first();
        if (!$pixel || empty($pixel->pixel_id)) {
            return;
        }

        $config = $pixel->additional_config;
        $accessToken = $config['access_token'] ?? null;
        if (!$accessToken) {
            return;
        }

        $data = [
            'data' => [
                [
                    'event_name' => $eventName,
                    'event_time' => time(),
                    'action_source' => 'website',
                    'user_data' => array_merge([
                        'client_ip_address' => request()->ip(),
                        'client_user_agent' => request()->userAgent(),
                    ], $userData),
                    'custom_data' => $customData,
                ]
            ],
        ];

        if (isset($config['test_event_code'])) {
            $data['test_event_code'] = $config['test_event_code'];
        }

        try {
            $response = Http::timeout(3)->connectTimeout(1)->post("https://graph.facebook.com/v18.0/{$pixel->pixel_id}/events?access_token={$accessToken}", $data);
            if ($response->failed()) {
                Log::error('Facebook CAPI Error: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Facebook CAPI Exception: ' . $e->getMessage());
        }
    }

    /**
     * Send event to Google Analytics 4 (Measurement Protocol)
     */
    public static function sendGA4Event(string $eventName, array $params = [])
    {
        $pixel = MarketingPixel::where('platform', 'google_analytics')->where('is_active', true)->first();
        if (!$pixel || empty($pixel->pixel_id)) {
            return;
        }

        $config = $pixel->additional_config;
        $measurementId = $pixel->pixel_id;
        $apiSecret = $config['api_secret'] ?? null;

        if (!$apiSecret) {
            return;
        }

        $clientId = request()->cookie('_ga') ?? 'anonymous';
        // Clean clientId if it's from cookie (format: GA1.1.12345.6789)
        if (str_starts_with($clientId, 'GA')) {
            $parts = explode('.', $clientId);
            $clientId = ($parts[2] ?? '') . '.' . ($parts[3] ?? '');
        }

        $data = [
            'client_id' => $clientId ?: 'anonymous',
            'events' => [
                [
                    'name' => $eventName,
                    'params' => $params,
                ]
            ],
        ];

        try {
            $response = Http::timeout(3)->connectTimeout(1)->post("https://www.google-analytics.com/mp/collect?measurement_id={$measurementId}&api_secret={$apiSecret}", $data);
            if ($response->failed()) {
                Log::error('GA4 Measurement Protocol Error: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('GA4 Measurement Protocol Exception: ' . $e->getMessage());
        }
    }
}
