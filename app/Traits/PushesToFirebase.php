<?php

namespace App\Traits;

use App\Helpers\FirebaseHelper;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;

trait PushesToFirebase
{
    /**
     * Send FCM push notification to the user's devices
     *
     * @param object $notifiable The user receiving the notification
     * @param array $fcmData Data to send (title, body, type, etc.)
     * @return void
     */
    protected function sendFcmNotification(object $notifiable, array $fcmData): void
    {
        try {
            $tokens = UserFcmToken::where('user_id', $notifiable->id)
                ->select('fcm_token', 'platform_type')
                ->get();

            if ($tokens->isEmpty()) {
                return;
            }

            foreach ($tokens as $token) {
                try {
                    $platform = match (strtolower((string) $token->platform_type)) {
                        'ios' => 'ios',
                        'android' => 'android',
                        default => 'web',
                    };

                    FirebaseHelper::send($platform, $token->fcm_token, $fcmData, [
                        'title' => $fcmData['title'] ?? '',
                        'body' => $fcmData['body'] ?? '',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM notification via Trait', [
                        'user_id' => $notifiable->id,
                        'token_prefix' => substr((string) $token->fcm_token, 0, 20),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to process FCM notifications via Trait', [
                'user_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
