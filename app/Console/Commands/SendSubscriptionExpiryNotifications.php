<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserFcmToken;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionExpiryNotifications extends Command
{
    protected $signature = 'subscriptions:send-expiry-notifications';

    protected $description = 'Send push/email notifications at 7d, 3d, 24h before subscription expiry';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $count = 0;

        // Get configured days or default to [7, 3, 1]
        $daysConfig = \App\Services\CachingService::getSystemSettings('subscription_expiry_days');
        $expiryDays = [7, 3, 1];
        
        if (!empty($daysConfig)) {
            // Split by comma, trim whitespace, remove empty values, convert to int
            $expiryDays = array_map('intval', array_filter(array_map('trim', explode(',', $daysConfig))));
            if (empty($expiryDays)) {
                $expiryDays = [7, 3, 1];
            }
        }

        foreach ($expiryDays as $days) {
            $subscriptions = $subscriptionService->getSubscriptionsForNotificationDays($days);

            foreach ($subscriptions as $subscription) {
                try {
                    // Send Multi-channel Notification (Mail + Database)
                    // The channels will be determined dynamically by NotificationSettingsService inside the notification's via() method
                    $subscription->user->notify(
                        new \App\Notifications\SubscriptionExpiryNotification($subscription, $days)
                    );

                    // Note: FCM push is now handled automatically inside toDatabase if Database is enabled.
                    // But we keep sendPushNotification here if they are handled separately, 
                    // though typically the notification's toDatabase would send the FCM.
                    // To respect admin toggles accurately without double sending, we'll comment this out
                    // if it's already integrated into the notification class.
                    // $this->sendPushNotification($subscription, $days);

                    $subscriptionService->markNotifiedDynamic($subscription, $days);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('Subscription expiry notification failed', [
                        'subscription_id' => $subscription->id,
                        'days' => $days,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Sent {$count} expiry notifications");
        return 0;
    }

    private function sendPushNotification($subscription, int $days): void
    {
        $tokens = UserFcmToken::where('user_id', $subscription->user_id)
            ->pluck('fcm_token')
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        $title = __('Subscription Expiring Soon');
        $body = $days === 1
            ? __('Your subscription expires in 24 hours. Renew now to avoid losing access.')
            : __('Your subscription expires in :days days. Renew now to keep your access.', ['days' => $days]);

        try {
            NotificationService::sendFcmNotification(
                $tokens,
                $title,
                $body,
                'subscription_expiry',
                ['subscription_id' => (string) $subscription->id, 'days_remaining' => (string) $days]
            );
        } catch (\Throwable $e) {
            Log::warning('FCM push notification failed for subscription expiry', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
