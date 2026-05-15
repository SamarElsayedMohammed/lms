<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionExpiryNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:check-expiry';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check subscriptions and send expiry notifications at 15, 7, and 3 days before expiry';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Subscription expiry check started.');

        $expiryDays = [15, 7, 3];

        foreach ($expiryDays as $days) {
            $targetDate = Carbon::today()->addDays($days);
            
            $subscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)
                ->whereDate('ends_at', $targetDate)
                ->with('user', 'plan')
                ->get();

            foreach ($subscriptions as $subscription) {
                // Check if already notified for this specific interval (optional logic to prevent duplicate)
                // Using flags already in the model if they exist
                $flagField = "notified_{$days}_days";
                
                // If the model has these flags (we checked Subscription model earlier), use them
                if (isset($subscription->$flagField) && $subscription->$flagField) {
                    continue;
                }

                if ($subscription->user) {
                    $subscription->user->notify(new SubscriptionExpiryNotification($subscription, $days));
                    
                    // Update flag if exists
                    if (isset($subscription->$flagField)) {
                        $subscription->update([$flagField => true]);
                    }
                    
                    $this->info("Notified User ID {$subscription->user_id} about expiry in {$days} days.");
                }
            }
        }

        Log::info('Subscription expiry check completed.');
    }
}
