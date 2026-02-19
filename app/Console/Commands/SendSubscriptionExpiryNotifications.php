<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiryNotification;
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

        foreach ([7, 3, 1] as $days) {
            $subscriptions = match ($days) {
                7 => $subscriptionService->getSubscriptionsForNotification7Days(),
                3 => $subscriptionService->getSubscriptionsForNotification3Days(),
                1 => $subscriptionService->getSubscriptionsForNotification1Day(),
                default => collect(),
            };

            foreach ($subscriptions as $subscription) {
                try {
                    Mail::to($subscription->user->email)->send(
                        new SubscriptionExpiryNotification($subscription, $days)
                    );

                    $this->sendPushNotification($subscription, $days);

                    $subscriptionService->markNotified($subscription, $days);
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
