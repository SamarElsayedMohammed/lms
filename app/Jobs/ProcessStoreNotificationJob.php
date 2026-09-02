<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StoreNotificationEvent;
use App\Services\Payment\StoreSubscriptionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessStoreNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $eventId
    ) {
    }

    public function handle(StoreSubscriptionLifecycleService $lifecycleService): void
    {
        if (! config('store_billing.enabled', false) || ! config('store_billing.lifecycle_processing_enabled', false)) {
            Log::info('ProcessStoreNotificationJob: Store lifecycle processing is disabled, skipping.', ['event_id' => $this->eventId]);
            return;
        }

        $event = StoreNotificationEvent::find($this->eventId);
        if (!$event) {
            Log::warning('ProcessStoreNotificationJob: Event not found', ['event_id' => $this->eventId]);
            return;
        }

        if ($event->processing_status === StoreNotificationEvent::STATUS_PROCESSED) {
            Log::info('ProcessStoreNotificationJob: Event already processed', ['event_id' => $this->eventId]);
            return;
        }

        if ($event->store === StoreNotificationEvent::STORE_APPLE) {
            $lifecycleService->processAppleEvent($event);
        } elseif ($event->store === StoreNotificationEvent::STORE_GOOGLE) {
            $lifecycleService->processGoogleEvent($event);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $event = StoreNotificationEvent::find($this->eventId);
        if ($event) {
            $event->markFailed('queue_job_failed', $exception ? $exception->getMessage() : 'Queue job failed.');
        }

        Log::error('ProcessStoreNotificationJob failed permanently', [
            'event_id' => $this->eventId,
            'error' => $exception ? $exception->getMessage() : 'Unknown error',
        ]);
    }
}
