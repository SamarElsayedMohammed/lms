<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchNotificationCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int|array $backoff = [10, 30, 60];

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $notificationData
     * @param array<int, string>|null $internalChannels
     */
    public function __construct(
        public int $campaignId,
        public array $filters,
        public array $notificationData,
        public ?array $internalChannels,
    ) {}

    public function handle(): void
    {
        $query = User::query()->whereDoesntHave('roles', function ($q) {
            $q->whereIn('name', ['Super Admin', 'Admin', 'Supervisor', 'Staff']);
        });

        $targetType = (string) ($this->filters['target_type'] ?? 'all');

        switch ($targetType) {
            case 'free_users':
                $query->whereDoesntHave('subscriptions');
                break;
            case 'expired_subscriptions':
                $query->whereHas('subscriptions', function ($q) {
                    $q->expired();
                })->whereDoesntHave('subscriptions', function ($q) {
                    $q->active();
                });
                break;
            case 'any_plan':
                $query->whereHas('subscriptions', function ($q) {
                    $q->active();
                });
                break;
            case 'by_plan':
                $planId = $this->filters['plan_id'] ?? null;
                $query->whereHas('subscriptions', function ($q) use ($planId) {
                    $q->active()->where('plan_id', $planId);
                });
                break;
            case 'by_plans':
                $planIds = $this->filters['plan_ids'] ?? [];
                $query->whereHas('subscriptions', function ($q) use ($planIds) {
                    $q->active()->whereIn('plan_id', $planIds);
                });
                break;
            case 'students':
                $query->whereDoesntHave('roles', function ($q) {
                    $q->whereIn('name', ['Instructor']);
                });
                break;
            case 'instructors':
                $query->whereHas('roles', function ($q) {
                    $q->where('name', 'Instructor');
                });
                break;
        }

        $queued = 0;
        $query->select('users.id')->chunkById(100, function ($users) use (&$queued) {
            $userIds = $users->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            SendNotificationCampaignChunkJob::dispatch(
                $this->campaignId,
                $userIds,
                $this->notificationData,
                $this->internalChannels,
            );

            $queued += count($userIds);
        });

        Log::info('Notification campaign queued to recipients', [
            'campaign_id' => $this->campaignId,
            'queued_count' => $queued,
        ]);
    }
}
