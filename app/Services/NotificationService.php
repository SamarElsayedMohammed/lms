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
            \App\Jobs\SendFcmNotificationJob::dispatch(
                $registrationIDs,
                $title,
                $message,
                $type,
                $customBodyFields
            );
            return true;
        } catch (Throwable $th) {
            return [
                'error' => true,
                'message' => $th->getMessage(),
            ];
        }
    }

    public static function getAccessToken()
    {
        try {
            $file_name = Setting::select('value')->where('name', 'service_file')->first();
            if ($file_name === null || empty($file_name->value)) {
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
            $client->setHttpClient(new \GuzzleHttp\Client([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]));
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
