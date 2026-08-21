<?php

namespace App\Listeners;

use App\Events\WebinarStartingSoon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\WebinarRegistrationNotification;
use Illuminate\Support\Facades\Log;

class SendWebinarReminder implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public int|array $backoff = [10, 30];

    /**
     * Handle the event.
     */
    public function handle(WebinarStartingSoon $event): void
    {
        $webinar = $event->webinar;

        // Strictly notify only confirmed registrants (paid or free) using chunkById streaming
        $webinar->registrations()
            ->whereIn('payment_status', ['paid', 'free'])
            ->with('user')
            ->chunkById(100, function ($registrations) use ($webinar) {
                foreach ($registrations as $reg) {
                    if ($reg->user) {
                        try {
                            $reg->user->notify(new WebinarRegistrationNotification($webinar, isReminder: true));
                        } catch (\Throwable $e) {
                            Log::error("Failed sending starting soon reminder to user #{$reg->user->id}: " . $e->getMessage());
                        }
                    }
                }
            });
    }
}
