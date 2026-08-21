<?php

namespace App\Listeners;

use App\Events\WebinarRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\WebinarRegistrationNotification;

class SendWebinarConfirmationMail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public int|array $backoff = [10, 30];

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
