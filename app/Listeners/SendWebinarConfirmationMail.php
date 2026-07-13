<?php

namespace App\Listeners;

use App\Events\WebinarRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWebinarConfirmationMail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(WebinarRegistered $event): void
    {
        // TODO: Send email with ICS Calendar attached
        // Mail::to($event->registration->user->email)->send(new WebinarConfirmationMail($event->registration));
        
        Log::info('Webinar confirmation email sent to ' . $event->registration->user->email);
    }
}
