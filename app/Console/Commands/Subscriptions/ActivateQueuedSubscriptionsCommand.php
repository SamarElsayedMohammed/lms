<?php

namespace App\Console\Commands\Subscriptions;

use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivateQueuedSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:activate-queued';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate pending/queued subscriptions that are due to start';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for queued subscriptions to activate...');

        // Find subscriptions that are pending/queued and their start date is now or in the past
        $queuedSubscriptions = Subscription::where('status', Subscription::STATUS_PENDING)
            ->where('starts_at', '<=', now())
            ->get();

        if ($queuedSubscriptions->isEmpty()) {
            $this->info('No queued subscriptions to activate.');
            return;
        }

        foreach ($queuedSubscriptions as $subscription) {
            // Ensure no other active subscription exists for this user
            $activeSub = Subscription::forUser($subscription->user_id)
                ->active()
                ->where('id', '!=', $subscription->id)
                ->first();

            if (!$activeSub) {
                $subscription->status = Subscription::STATUS_ACTIVE;
                $subscription->save();

                $this->info("Activated subscription #{$subscription->id} for user #{$subscription->user_id}");
                Log::info('Queued subscription activated', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id
                ]);
            }
        }

        $this->info('Activation process completed.');
    }
}
