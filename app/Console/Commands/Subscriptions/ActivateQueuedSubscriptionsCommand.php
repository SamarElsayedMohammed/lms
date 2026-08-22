<?php

namespace App\Console\Commands\Subscriptions;

use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $count = 0;

        // Find subscriptions that are pending/queued and their start date is now or in the past
        Subscription::where('status', Subscription::STATUS_PENDING)
            ->where('starts_at', '<=', now())
            ->chunkById(100, function ($queuedSubscriptions) use (&$count) {
                foreach ($queuedSubscriptions as $subscription) {
                    DB::transaction(function () use ($subscription, &$count) {
                        $lockedSub = Subscription::where('id', $subscription->id)
                            ->where('status', Subscription::STATUS_PENDING)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedSub) {
                            return;
                        }

                        // Ensure no other active subscription exists for this user
                        $activeSub = Subscription::forUser($lockedSub->user_id)
                            ->active()
                            ->where('id', '!=', $lockedSub->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $activeSub) {
                            $lockedSub->status = Subscription::STATUS_ACTIVE;
                            $lockedSub->save();
                            $count++;

                            $this->info("Activated subscription #{$lockedSub->id} for user #{$lockedSub->user_id}");
                            Log::info('Queued subscription activated', [
                                'subscription_id' => $lockedSub->id,
                                'user_id' => $lockedSub->user_id,
                            ]);
                        }
                    });
                }
            });

        if ($count === 0) {
            $this->info('No queued subscriptions to activate.');
            return;
        }

        $this->info('Activation process completed.');
    }
}
