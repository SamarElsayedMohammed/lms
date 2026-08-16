<?php

namespace App\Listeners;

use App\Events\WebinarCancelled;
use App\Notifications\WebinarRegistrationNotification;
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
        $webinar = $event->webinar;

        // Notify all confirmed users (paid or free)
        $registrations = $webinar->registrations()
            ->whereIn('payment_status', ['paid', 'free'])
            ->with('user')
            ->get();

        foreach ($registrations as $reg) {
            if ($reg->user) {
                try {
                    $reg->user->notify(new WebinarRegistrationNotification($webinar, isReminder: false, isCancelled: true));
                    Log::info("Webinar cancellation notification sent to user #{$reg->user->id} ({$reg->user->email}) for webinar #{$webinar->id}");
                } catch (\Throwable $e) {
                    Log::error("Failed sending webinar cancellation notification to user #{$reg->user->id}: " . $e->getMessage());
                }
            }
        }
    }
}
