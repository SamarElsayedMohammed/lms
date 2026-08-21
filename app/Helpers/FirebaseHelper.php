<?php

namespace App\Helpers;

use App\Models\Setting;
use App\Models\UserFcmToken;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class FirebaseHelper
{
    /**
     * @param string $platform
     * @param string $registration_ids FCM device token
     * @param array<string, mixed> $fcm_msg
     * @param mixed $notification unused legacy argument
     */
    public static function send($platform, $registration_ids, $fcm_msg, $notification = null)
    {
        $dataPayload = self::stringifyData($fcm_msg);
        $title = (string) ($dataPayload['title'] ?? '');
        $body = (string) ($dataPayload['body'] ?? '');

        $message = [
            'token' => $registration_ids,
            'data' => $dataPayload,
        ];

        $platformKey = strtolower((string) $platform);

        // Visible system tray on iOS and web; Android uses data + client display.
        if ($platformKey !== 'android') {
            $message['notification'] = [
                'title' => $title,
                'body' => $body,
            ];
        }

        if ($platformKey === 'ios') {
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'sound' => isset($dataPayload['type'])
                            && ($dataPayload['type'] == 'new_order' || $dataPayload['type'] == 'assign_order')
                            ? 'order_sound.aiff'
                            : 'default',
                    ],
                ],
            ];
        } elseif (!in_array($platformKey, ['android', 'web', 'ios'], true)) {
            Log::error('Invalid platform specified for Firebase push notification.');
            return false;
        }

        return self::sendPushNotification(['message' => $message]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function sendPushNotification($fields)
    {
        $data1 = json_encode($fields);

        $access_token = self::getAccessToken();

        if ($access_token === null) {
            Log::info('Firebase not configured - skipping push notification');
            return false;
        }

        $projectID = optional(Setting::where('name', 'firebase_project_id')->first())->value;

        if (!$projectID) {
            Log::error('Firebase project ID not found in settings.');
            return false;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $projectID . '/messages:send';

        $headers = [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $result = curl_exec($ch);

        if ($result === false) {
            Log::error('FCM request failed: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $response = is_string($result) ? json_decode($result, true) : null;
        $token = $fields['message']['token'] ?? null;
        $errorCode = is_array($response) ? ($response['error']['code'] ?? null) : null;
        $errorStatus = is_array($response) ? (string) ($response['error']['status'] ?? '') : '';

        $isInvalidToken = $errorCode === 404
            || in_array($errorStatus, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true);

        if ($isInvalidToken && is_string($token) && $token !== '') {
            UserFcmToken::where('fcm_token', $token)->delete();
            Log::warning('Deleted expired FCM token', [
                'token_prefix' => substr($token, 0, 12),
            ]);
        }

        if (is_array($response) && isset($response['error'])) {
            Log::warning('Firebase push rejected', [
                'status' => $errorStatus,
                'code' => $errorCode,
            ]);
        }

        return $response;
    }

    /**
     * FCM data payload values must be strings.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $out[(string) $key] = $value ? '1' : '0';
            } elseif ($value === null) {
                $out[(string) $key] = '';
            } else {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    private static function getAccessToken()
    {
        $filePath = app(\App\Services\FirebaseConfigService::class)->getCredentialsPath();

        if ($filePath === null || !file_exists($filePath)) {
            Log::warning('Firebase service account file not found - Firebase notifications disabled');

            return null;
        }

        $client = new Client();
        $client->setHttpClient(new GuzzleClient([
            'timeout' => 10,
            'connect_timeout' => 3,
        ]));
        $client->setAuthConfig($filePath);
        $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);

        $accessToken = $client->fetchAccessTokenWithAssertion();

        return $accessToken['access_token'] ?? null;
    }
}
