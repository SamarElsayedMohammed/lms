<?php

namespace App\Helpers;

use App\Models\Setting;
use App\Models\UserFcmToken;
use Google\Client;
use Illuminate\Support\Facades\Log;

class FirebaseHelper
{
    public static function send($platform, $registration_ids, $fcm_msg, $notification)
    {
        if ($platform == 'android' || $platform == 'web') {
            $fields = [
                'message' => [
                    'token' => $registration_ids,
                    'data' => $fcm_msg,
                ],
            ];
        } elseif ($platform == 'ios') {
            $fields = [
                'message' => [
                    'token' => $registration_ids,
                    'data' => $fcm_msg,
                    'notification' => [
                        'title' => $fcm_msg['title'] ?? '',
                        'body' => $fcm_msg['body'] ?? '',
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => isset($fcm_msg['type'])
                                && ($fcm_msg['type'] == 'new_order' || $fcm_msg['type'] == 'assign_order')
                                    ? 'order_sound.aiff'
                                    : 'default',
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            Log::error('Invalid platform specified for Firebase push notification.');
            return false;
        }

        return self::sendPushNotification($fields);
    }

    public static function sendPushNotification($fields)
    {
        $data1 = json_encode($fields);

        $access_token = self::getAccessToken();

        // If Firebase is not configured, return false without error
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

        $result = curl_exec($ch);

        if ($result === false) {
            Log::error('FCM request failed: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $response = json_decode($result, true);

        if (isset($response['error']['code']) && in_array($response['error']['code'], [404])) {
            $token = $fields['message']['token'];
            UserFcmToken::where('fcm_token', $token)->delete();

            Log::warning('Deleted expired FCM token: ' . $token);
        }

        Log::info('Firebase Push Notification Sent', ['response' => $response]);

        return $response;
    }

    private static function getAccessToken()
    {
        // Get the firebase_service_file value from settings
        $firebaseServiceFile = \App\Models\Setting::where('name', 'firebase_service_file')->value('value');
        $filePath = null;

        if ($firebaseServiceFile) {
            $filePath = \App\Services\FileService::getFilePath($firebaseServiceFile);
        }
        // $filePath = base_path('config/firebase.json');

        if (!$filePath || !file_exists($filePath)) {
            // Log the error but don't throw exception - make Firebase optional
            Log::warning('Firebase service account file not found - Firebase notifications disabled', [
                'file_path' => $filePath ?? 'not set',
                'setting_value' => $firebaseServiceFile,
            ]);
            return null; // Return null to indicate Firebase is not configured
        }

        $client = new Client();

        $client->setAuthConfig($filePath);
        $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);

        $accessToken = $client->fetchAccessTokenWithAssertion();

        return $accessToken['access_token'] ?? null;
    }
}
