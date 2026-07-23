<?php

namespace App\Listeners;

use App\Events\WebinarStartingSoon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\WebinarRegistrationNotification;

class SendWebinarReminder implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(WebinarStartingSoon $event): void
    {
        $registrations = $event->webinar->registrations()->with('user')->get();
        foreach ($registrations as $reg) {
            if ($reg->user) {
                $reg->user->notify(new WebinarRegistrationNotification($event->webinar, true));
            }
        }
    }
}
