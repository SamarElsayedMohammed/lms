<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StoreNotificationEvent;
use App\Models\StoreTransaction;
use App\Services\Payment\StoreSubscriptionLifecycleService;
use Illuminate\Console\Command;

final class StoreBillingStatusCommand extends Command
{
    protected $signature = 'store-billing:status {--retry-failed : Retry processing all failed notification events}';

    protected $description = 'Inspect Store Billing and Webhook Event Lifecycle status';

    public function handle(StoreSubscriptionLifecycleService $lifecycleService): int
    {
        $this->info('=== SKILLSO NATIVE STORE BILLING & WEBHOOK STATUS ===');
        $this->newLine();

        // 1. Configuration Readiness
        $isStoreBillingEnabled = config('store_billing.enabled', false) ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED (Web-Managed Mode)</>';
        $isNotificationsEnabled = config('store_billing.notifications_enabled', false) ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>';
        $appleBundleId = config('store_billing.apple.bundle_id');
        $appleEnv = config('store_billing.apple.environment');
        $googlePackage = config('store_billing.google.package_name');
        $googleEnv = config('store_billing.google.environment');
        $mockMode = config('store_billing.mock_verification_enabled') ? 'ENABLED (Testing/Staging)' : 'DISABLED (Live)';

        $this->line("• Master Store Billing: {$isStoreBillingEnabled}");
        $this->line("• Webhook Notifications: {$isNotificationsEnabled}");
        $this->newLine();

        $this->table(
            ['Provider', 'Identifier / Package', 'Environment', 'Driver Mode'],
            [
                ['Apple App Store', $appleBundleId, $appleEnv, $mockMode],
                ['Google Play', $googlePackage, $googleEnv, $mockMode],
            ]
        );
        $this->newLine();

        // 2. Notification Event Ledger Stats & Transactions
        try {
            $pendingCount = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_PENDING)->count();
            $processingCount = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_PROCESSING)->count();
            $processedCount = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_PROCESSED)->count();
            $ignoredCount = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_IGNORED)->count();
            $failedCount = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_FAILED)->count();
            $totalEvents = StoreNotificationEvent::count();

            $this->info('--- Webhook Notification Ledger Metrics ---');
            $this->table(
                ['Total Events', 'Processed', 'Pending', 'Processing', 'Ignored/No-Action', 'Failed'],
                [[$totalEvents, $processedCount, $pendingCount, $processingCount, $ignoredCount, $failedCount]]
            );
            $this->newLine();

            // 3. Transactions Count
            $appleTxCount = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)->count();
            $googleTxCount = StoreTransaction::where('store', StoreTransaction::STORE_GOOGLE)->count();
            $activeStoreTx = StoreTransaction::where('status', 'active')->where('expires_at', '>', now())->count();

            $this->info('--- Store Transactions Ledger ---');
            $this->table(
                ['Apple Transactions', 'Google Transactions', 'Active Store Subscriptions'],
                [[$appleTxCount, $googleTxCount, $activeStoreTx]]
            );
            $this->newLine();
        } catch (\Throwable $e) {
            $this->warn('Database connection unavailable for ledger metrics: ' . $e->getMessage());
            $this->newLine();
        }

        // 4. Retry Failed Events if flag is present
        if ($this->option('retry-failed')) {
            $failedEvents = StoreNotificationEvent::where('processing_status', StoreNotificationEvent::STATUS_FAILED)->get();
            if ($failedEvents->isEmpty()) {
                $this->info('No failed events to retry.');
                return 0;
            }

            $this->warn("Retrying {$failedEvents->count()} failed events...");
            $retriedCount = 0;
            foreach ($failedEvents as $event) {
                $success = $event->store === StoreNotificationEvent::STORE_APPLE
                    ? $lifecycleService->processAppleEvent($event)
                    : $lifecycleService->processGoogleEvent($event);

                if ($success) {
                    $retriedCount++;
                }
            }

            $this->info("Retried {$failedEvents->count()} events. Success: {$retriedCount}.");
        }

        return 0;
    }
}
