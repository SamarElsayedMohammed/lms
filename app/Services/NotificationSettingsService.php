<?php

namespace App\Services;

use App\Models\NotificationSetting;

class NotificationSettingsService
{
    /**
     * Get the enabled channels for a given notification class based on system settings.
     *
     * @param string $notificationClass
     * @param array $defaultChannels Default channels to fallback to if not configured (e.g., ['mail', 'database'])
     * @return array
     */
    public static function getChannelsFor(
        string $notificationClass,
        array $defaultChannels = ['mail', 'database'],
        ?object $notifiable = null,
    ): array
    {
        $configJson = CachingService::getSystemSettings('notification_channels_config');
        $config = $configJson ? json_decode($configJson, true) : [];

        // Missing system configuration means all default channels remain enabled.
        $classConfig = $config[$notificationClass] ?? [];
        $enabledChannels = [];

        foreach ($defaultChannels as $channel) {
            // Check if the channel is explicitly set to false. If missing, assume true.
            $isEnabled = isset($classConfig[$channel]) ? (bool) $classConfig[$channel] : true;
            if ($isEnabled) {
                $enabledChannels[] = $channel;
            }
        }

        if ($notifiable && in_array('mail', $enabledChannels, true)
            && !self::isUserChannelEnabled($notifiable, $notificationClass, 'email')) {
            $enabledChannels = array_values(array_diff($enabledChannels, ['mail']));
        }

        return $enabledChannels;
    }

    /**
     * Apply the authenticated user's delivery preference without disabling the
     * in-app database notification feed.
     */
    public static function isUserChannelEnabled(
        object $notifiable,
        string $notificationClass,
        string $channel,
    ): bool {
        $userId = $notifiable->id ?? null;
        $category = self::categoryFor($notificationClass);

        if (!$userId || !$category || !in_array($channel, ['email', 'push'], true)) {
            return true;
        }

        $setting = NotificationSetting::query()
            ->where('user_id', $userId)
            ->where('setting_key', $category)
            ->first();

        if (!$setting) {
            return true;
        }

        return $channel === 'email'
            ? (bool) $setting->email_enabled
            : (bool) $setting->push_enabled;
    }

    private static function categoryFor(string $notificationClass): ?string
    {
        return match (class_basename($notificationClass)) {
            'NewCourseNotification' => 'new_courses',
            'PaymentStatusNotification',
            'WithdrawalStatusNotification',
            'ManualDepositStatusNotification',
            'CommissionPaidNotification',
            'SubscriptionActivatedNotification',
            'SubscriptionRenewedNotification',
            'SubscriptionExpiryNotification',
            'ManualSubscriptionStatusNotification',
            'ManualRenewalRequestedNotification' => 'wallet_activity',
            'WebinarRegistrationNotification',
            'CertificateNotification',
            'ReviewStatusNotification' => 'course_updates',
            'ContactReplyNotification',
            'TeamInvitationNotification',
            'TeamInvitationResponseNotification' => 'new_messages',
            'WelcomeNotification',
            'InstructorStatusUpdateNotification' => 'security_alerts',
            'ManualCustomNotification' => 'promotions',
            default => null,
        };
    }
}
