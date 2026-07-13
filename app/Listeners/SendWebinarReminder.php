<?php

namespace App\Listeners;

use App\Events\WebinarStartingSoon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWebinarReminder implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(WebinarStartingSoon $event): void
    {
        // TODO: Send webinar reminder
        $registrations = $event->webinar->registrations()->with('user')->get();
        foreach ($registrations as $reg) {
            // Mail::to($reg->user->email)->send(new WebinarReminderMail($event->webinar));
            Log::info('Webinar reminder email sent to ' . $reg->user->email);
        }
    }
}
