<?php

namespace App\Traits;

use App\Services\NotificationSettingsService;

trait ConfigurableNotification
{
    public int $tries = 3;

    public int $timeout = 60;

    public int|array $backoff = [10, 30];

    /**
     * Determine which channels the notification should be sent on.
     * Overrides the default via() method.
     *
     * @param  object  $notifiable
     * @return array
     */
    public function via(object $notifiable): array
    {
        $defaultChannels = property_exists($this, 'defaultChannels') ? $this->defaultChannels : ['mail', 'database'];
        return NotificationSettingsService::getChannelsFor(
            class_basename(self::class),
            $defaultChannels,
            $notifiable,
        );
    }
}
