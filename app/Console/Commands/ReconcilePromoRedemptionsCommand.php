<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SubscriptionPromoService;
use Illuminate\Console\Command;

final class ReconcilePromoRedemptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promo:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile and backfill historical completed orders and subscription payments into promo_redemptions table';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionPromoService $promoService): int
    {
        $this->info('Starting Promo Redemptions Historical Reconciliation...');

        $results = $promoService->backfillHistoricalRedemptions();

        $this->info("Subscription Payments Backfilled: {$results['subscription_payments_backfilled']}");
        $this->info("Orders Backfilled: {$results['orders_backfilled']}");
        $this->info('Reconciliation completed successfully.');

        return self::SUCCESS;
    }
}
