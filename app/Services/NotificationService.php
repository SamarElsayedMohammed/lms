<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\UserFcmToken;
use Google\Client;
use Google\Exception;
use RuntimeException;
use Throwable;

class NotificationService
{
    /**
     * @param array $registrationIDs
     * @param string|null $title
     * @param string|null $message
     * @param string $type
     * @param array $customBodyFields
     * @return string|array|bool
     */
    public static function sendFcmNotification(
        array $registrationIDs,
        string|null $title = '',
        string|null $message = '',
        string $type = 'default',
        array $customBodyFields = [],
    ): string|array|bool {
        try {
            //TODO : Use this from caching
            $project_id = Setting::select('value')->where('name', 'firebase_project_id')->first();
            if (empty($project_id->value)) {
                return [
                    'error' => true,
                    'message' => 'FCM configurations are not configured.',
                ];
            }

            $project_id = $project_id->value;
            $url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';

            //            $registrationIDs_chunks = array_chunk($registrationIDs, 1000);

            $access_token = self::getAccessToken();
            if ($access_token['error']) {
                return $access_token;
            }
            $result = [];

            $deviceInfo = UserFcmToken::select(['platform_type', 'fcm_token'])->whereIn(
                'fcm_token',
                $registrationIDs,
            )->get();

            //TODO : Add this process to queue for better performance
            $dataWithTitle = [
                ...$customBodyFields,
                'title' => $title,
                'body' => $message,
                'type' => $type,
            ];
            foreach ($registrationIDs as $registrationID) {
                $platform = $deviceInfo->first(static fn($q) => $q->fcm_token == $registrationID);
                $data = [
                    'message' => [
                        'token' => $registrationID,
                        'data' => self::convertToStringRecursively($dataWithTitle),
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10', // Set APNs priority to 10 (high) for immediate delivery
                            ],
                            'payload' => [
                                'aps' => [
                                    'alert' => [
                                        'title' => $title,
                                        'body' => $message,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
                if ($platform->platform_type != 'Android') {
                    $data['message']['notification'] = [
                        'title' => $title,
                        'body' => $message,
                    ];
                }

                $encodedData = json_encode($data);
                $headers = [
                    'Authorization: Bearer ' . $access_token['data'],
                    'Content-Type: application/json',
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

                // Disabling SSL Certificate support temporarily
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);

                // Execute post
                $result = curl_exec($ch);

                if (!$result) {
                    die('Curl failed: ' . curl_error($ch));
                }
                curl_close($ch);
            }
            return [
                'error' => false,
                'message' => 'Success',
                'data' => $result,
            ];
        } catch (Throwable $th) {
            throw new RuntimeException($th);
        }
    }

    public static function getAccessToken()
    {
        try {
            $file_name = Setting::select('value')->where('name', 'service_file')->first();
            if (empty($file_name)) {
                return [
                    'error' => true,
                    'message' => 'FCM Configuration not found',
                ];
            }
            $file_name = $file_name->value;
            $file_path = base_path('public/storage/' . $file_name);

            if (!file_exists($file_path)) {
                return [
                    'error' => true,
                    'message' => 'FCM Service File not found',
                ];
            }
            $client = new Client();
            $client->setAuthConfig($file_path);
            $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);

            return [
                'error' => false,
                'message' => 'Access Token generated successfully',
                'data' => $client->fetchAccessTokenWithAssertion()['access_token'],
            ];
        } catch (Exception $e) {
            throw new RuntimeException($e);
        }
    }

    public static function convertToStringRecursively($data, &$flattenedArray = [])
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::convertToStringRecursively($value, $flattenedArray);
            } elseif (is_null($value)) {
                $flattenedArray[$key] = '';
            } else {
                $flattenedArray[$key] = (string) $value;
            }
        }
        return $flattenedArray;
    }
}
