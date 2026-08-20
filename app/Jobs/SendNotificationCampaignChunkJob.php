<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ManualCustomNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

final class SendNotificationCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 45;

    public int $tries = 3;

    /**
     * @param array<int, int> $userIds
     * @param array<string, mixed> $notificationData
     * @param array<int, string>|null $internalChannels
     */
    public function __construct(
        public int $campaignId,
        public array $userIds,
        public array $notificationData,
        public ?array $internalChannels,
    ) {}

    public function handle(): void
    {
        $users = User::query()
            ->whereIn('id', $this->userIds)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send(
            $users,
            new ManualCustomNotification($this->notificationData, $this->internalChannels),
        );
    }
}
