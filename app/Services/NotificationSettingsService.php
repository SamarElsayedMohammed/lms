<?php

namespace App\Services;

class NotificationSettingsService
{
    /**
     * Get the enabled channels for a given notification class based on system settings.
     *
     * @param string $notificationClass
     * @param array $defaultChannels Default channels to fallback to if not configured (e.g., ['mail', 'database'])
     * @return array
     */
    public static function getChannelsFor(string $notificationClass, array $defaultChannels = ['mail', 'database']): array
    {
        $configJson = CachingService::getSystemSettings('notification_channels_config');
        $config = $configJson ? json_decode($configJson, true) : [];

        // If no config exists for this class, fallback to default channels (true by default)
        if (!isset($config[$notificationClass])) {
            return $defaultChannels;
        }

        $classConfig = $config[$notificationClass];
        $enabledChannels = [];

        foreach ($defaultChannels as $channel) {
            // Check if the channel is explicitly set to false. If missing, assume true.
            $isEnabled = isset($classConfig[$channel]) ? (bool) $classConfig[$channel] : true;
            if ($isEnabled) {
                $enabledChannels[] = $channel;
            }
        }

        return $enabledChannels;
    }
}
