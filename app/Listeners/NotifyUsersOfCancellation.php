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

    public int $tries = 3;

    public int $timeout = 60;

    public int|array $backoff = [10, 30];

    /**
     * Handle the event.
     */
    public function handle(WebinarCancelled $event): void
    {
        $webinar = $event->webinar;

        // Notify all confirmed users (paid or free) using chunkById streaming
        $webinar->registrations()
            ->whereIn('payment_status', ['paid', 'free'])
            ->with('user')
            ->chunkById(100, function ($registrations) use ($webinar) {
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
            });
    }
}
