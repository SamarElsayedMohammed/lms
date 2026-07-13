<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionRenewalAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:subscription-renewals {--fix : Automatically fix the starts_at and ends_at dates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify subscriptions affected by the renewal overwrite bug (losing remaining days)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Subscription Renewal Audit...');

        // Find subscriptions where:
        // 1. starts_at was reset to updated_at (within a 5-second margin)
        // 2. They have more than 1 completed payment
        
        $affectedSubscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereColumn('starts_at', '>=', DB::raw('updated_at - INTERVAL 5 SECOND'))
            ->whereColumn('starts_at', '<=', DB::raw('updated_at + INTERVAL 5 SECOND'))
            ->whereHas('payments', function ($query) {
                $query->where('status', 'completed');
            }, '>', 1)
            ->with(['payments' => function ($query) {
                $query->where('status', 'completed')->orderBy('paid_at', 'asc');
            }, 'plan', 'user'])
            ->get();

        if ($affectedSubscriptions->isEmpty()) {
            $this->info('No affected subscriptions found. The system is clean!');
            return 0;
        }

        $this->warn('Found ' . $affectedSubscriptions->count() . ' potentially affected subscriptions.');

        $headers = ['Sub ID', 'User ID', 'User Email', 'Current Starts At', 'Original Paid At', 'Days Lost'];
        $rows = [];

        foreach ($affectedSubscriptions as $sub) {
            $firstPayment = $sub->payments->first();
            $latestPayment = $sub->payments->last();
            
            if (!$firstPayment || !$latestPayment || $firstPayment->id === $latestPayment->id) {
                continue;
            }

            // Estimate lost days
            $originalPaidAt = $firstPayment->paid_at ?? $firstPayment->created_at;
            $renewedAt = $latestPayment->paid_at ?? $latestPayment->created_at;
            
            if (!$originalPaidAt || !$renewedAt) continue;

            $durationDays = $sub->plan ? $sub->plan->getDurationDays() : null;
            if (!$durationDays) continue;

            $expectedOriginalEndsAt = $originalPaidAt->copy()->addDays($durationDays);
            
            // If the renewal happened BEFORE the original ended, they lost days
            if ($renewedAt->lt($expectedOriginalEndsAt)) {
                $daysLost = $expectedOriginalEndsAt->diffInDays($renewedAt);
                
                $rows[] = [
                    $sub->id,
                    $sub->user_id,
                    $sub->user->email ?? 'N/A',
                    $sub->starts_at->format('Y-m-d H:i:s'),
                    $originalPaidAt->format('Y-m-d H:i:s'),
                    $daysLost
                ];

                if ($this->option('fix')) {
                    // Restore the original starts_at or add the lost days to ends_at
                    if ($sub->ends_at) {
                        $sub->ends_at = $sub->ends_at->addDays($daysLost);
                        $sub->save();
                    }
                    $this->info("Fixed subscription ID {$sub->id} by adding {$daysLost} lost days.");
                }
            }
        }

        if (count($rows) > 0) {
            $this->table($headers, $rows);
        } else {
            $this->info('No lost days detected among the queried subscriptions.');
        }

        if (!$this->option('fix') && count($rows) > 0) {
            $this->info('Run with --fix to automatically add the lost days to their current expires_at date.');
        }

        return 0;
    }
}
