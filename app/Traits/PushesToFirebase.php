<?php

namespace App\Traits;

use App\Jobs\SendFcmNotificationJob;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;

trait PushesToFirebase
{
    /**
     * Queue FCM push to the user's registered devices.
     *
     * @param object $notifiable The user receiving the notification
     * @param array<string, mixed> $fcmData Data to send (title, body, type, etc.)
     */
    protected function sendFcmNotification(object $notifiable, array $fcmData): void
    {
        try {
            $userId = $notifiable->id ?? null;
            if (!$userId) {
                return;
            }

            $tokens = UserFcmToken::query()
                ->where('user_id', $userId)
                ->whereNotNull('fcm_token')
                ->pluck('fcm_token')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($tokens === []) {
                return;
            }

            SendFcmNotificationJob::dispatch(
                $tokens,
                (string) ($fcmData['title'] ?? ''),
                (string) ($fcmData['body'] ?? ''),
                (string) ($fcmData['type'] ?? 'default'),
                $fcmData,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to queue FCM notification', [
                'user_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
