<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTrackingEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 10;
    public int $backoff = 5;

    /**
     * @param string $platform 'facebook' | 'ga4'
     * @param string $eventName
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $platform,
        public string $eventName,
        public array $payload = []
    ) {}

    public function handle(): void
    {
        if ($this->platform === 'facebook') {
            TrackingService::sendFacebookEvent(
                $this->eventName,
                $this->payload['user_data'] ?? [],
                $this->payload['custom_data'] ?? []
            );
        } elseif ($this->platform === 'ga4') {
            TrackingService::sendGA4Event(
                $this->eventName,
                $this->payload['params'] ?? []
            );
        }
    }
}
