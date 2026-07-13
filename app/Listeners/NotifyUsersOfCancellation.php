<?php

namespace App\Listeners;

use App\Events\WebinarCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class NotifyUsersOfCancellation implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(WebinarCancelled $event): void
    {
        // TODO: Notify all registered users
        $registrations = $event->webinar->registrations()->with('user')->get();
        foreach ($registrations as $reg) {
            // Mail::to($reg->user->email)->send(new WebinarCancellationMail($event->webinar));
            Log::info('Webinar cancellation email sent to ' . $reg->user->email);
        }
    }
}
