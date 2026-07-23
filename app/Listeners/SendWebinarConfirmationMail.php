<?php

namespace App\Listeners;

use App\Events\WebinarRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\WebinarRegistrationNotification;

class SendWebinarConfirmationMail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(WebinarRegistered $event): void
    {
        $registration = $event->registration->loadMissing(['user', 'webinar']);
        if ($registration->user && $registration->webinar) {
            $registration->user->notify(new WebinarRegistrationNotification($registration->webinar));
        }
    }
}
