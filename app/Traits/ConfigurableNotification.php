<?php

namespace App\Traits;

use App\Services\NotificationSettingsService;

trait ConfigurableNotification
{
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
        return NotificationSettingsService::getChannelsFor(class_basename(self::class), $defaultChannels);
    }
}
