<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WebinarRegistered;
use App\Notifications\WebinarRegisteredNotification;
use Illuminate\Support\Facades\Log;

class SendWebinarRegisteredNotification
{
    public function handle(WebinarRegistered $event): void
    {
        $user = $event->registration->user;
        if (!$user) {
            return;
        }

        try {
            $user->notify(new WebinarRegisteredNotification($event->registration->loadMissing('webinar')));
        } catch (\Throwable $e) {
            Log::warning('Webinar registration notification failed', [
                'registration_id' => $event->registration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
