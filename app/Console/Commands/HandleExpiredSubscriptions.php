<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class HandleExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:handle-expired';

    protected $description = 'Mark subscriptions as expired when ends_at has passed';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $count = $subscriptionService->handleExpiredSubscriptions();
        $this->info("Marked {$count} subscriptions as expired");
        return 0;
    }
}
