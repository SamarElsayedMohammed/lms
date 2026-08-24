<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\StoreNotificationEvent;
use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AppleStoreServerNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $monthlyPlan;
    private SubscriptionPlan $yearlyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'store_billing.enabled' => true,
            'store_billing.apple_enabled' => true,
            'store_billing.notifications_enabled' => true,
            'store_billing.lifecycle_processing_enabled' => true,
            'store_billing.mock_verification_enabled' => true,
        ]);

        $this->user = User::factory()->create([
            'email' => 'student@skillso.test',
            'wallet_balance' => 0,
        ]);

        $this->monthlyPlan = SubscriptionPlan::create([
            'name' => 'Monthly Plan',
            'slug' => 'monthly',
            'price' => 500,
            'usd_price' => 20,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $this->yearlyPlan = SubscriptionPlan::create([
            'name' => 'Yearly Plan',
            'slug' => 'yearly',
            'price' => 5000,
            'usd_price' => 200,
            'billing_cycle' => 'yearly',
            'duration_days' => 365,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to generate mock Apple StoreKit 2 compact JWS for transactions
     */
    private function generateTransactionJws(
        string $transactionId,
        string $originalTransactionId,
        string $productId = 'skillso_monthly_sub',
        ?int $expiresDateMs = null,
        ?int $revocationDateMs = null,
        string $environment = 'Sandbox'
    ): string {
        $header = ['alg' => 'ES256', 'typ' => 'JWT'];
        $payload = [
            'transactionId' => $transactionId,
            'originalTransactionId' => $originalTransactionId,
            'productId' => $productId,
            'bundleId' => 'com.skillso.app.skillso',
            'purchaseDate' => (time() - 3600) * 1000,
            'expiresDate' => $expiresDateMs ?? (time() + 86400 * 30) * 1000,
            'environment' => $environment,
            'type' => 'Auto-Renewable Subscription',
            'inAppOwnershipType' => 'PURCHASED',
            'autoRenewStatus' => 1,
        ];

        if ($revocationDateMs !== null) {
            $payload['revocationDate'] = $revocationDateMs;
            $payload['revocationReason'] = 1;
        }

        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signatureB64 = rtrim(strtr(base64_encode(hash('sha256', "{$headerB64}.{$payloadB64}", true)), '+/', '-_'), '=');

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    /**
     * Helper to generate mock App Store Server Notifications V2 signedPayload JWS
     */
    private function generateNotificationJws(
        string $notificationType,
        ?string $subtype = null,
        ?string $transactionJws = null,
        ?string $notificationUuid = null,
        string $bundleId = 'com.skillso.app.skillso'
    ): string {
        $uuid = $notificationUuid ?? ('notif_uuid_' . uniqid());
        $header = ['alg' => 'ES256', 'typ' => 'JWT'];
        $payload = [
            'notificationType' => $notificationType,
            'subtype' => $subtype,
            'notificationUUID' => $uuid,
            'version' => '2.0',
            'signedDate' => time() * 1000,
            'data' => [
                'appAppleId' => '1234567890',
                'bundleId' => $bundleId,
                'bundleVersion' => '1.0.0',
                'environment' => 'Sandbox',
                'signedTransactionInfo' => $transactionJws,
            ],
        ];

        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signatureB64 = rtrim(strtr(base64_encode(hash('sha256', "{$headerB64}.{$payloadB64}", true)), '+/', '-_'), '=');

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    public function test_valid_signed_test_notification(): void
    {
        $jws = $this->generateNotificationJws('TEST');

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $jws,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('store_notification_events', [
            'store' => 'app_store',
            'event_type' => 'TEST',
            'processing_status' => 'processed',
        ]);
    }

    public function test_subscribed_initial_buy_creates_and_activates_subscription(): void
    {
        // First create a baseline store transaction from user initial sync
        $txJws = $this->generateTransactionJws('tx_apple_2001', 'orig_tx_apple_2001', 'skillso_monthly_sub');

        // Create baseline initial transaction for user
        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_2001',
            'original_transaction_id' => 'orig_tx_apple_2001',
            'purchased_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $notifJws = $this->generateNotificationJws('SUBSCRIBED', 'INITIAL_BUY', $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => 'active',
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_2001',
        ]);
    }

    public function test_did_renew_advances_subscription_expiry(): void
    {
        // 1. Initial subscription setup
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->addMinutes(5),
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_3001',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_3001',
            'original_transaction_id' => 'orig_tx_apple_3001',
            'purchased_at' => now()->subDays(30),
            'expires_at' => now()->addMinutes(5),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        // 2. Renewal notification arrives with new transaction ID and new future expiration (30 days from now)
        $newExpiryMs = (time() + 86400 * 30) * 1000;
        $renewalTxJws = $this->generateTransactionJws('tx_apple_3002', 'orig_tx_apple_3001', 'skillso_monthly_sub', $newExpiryMs);
        $notifJws = $this->generateNotificationJws('DID_RENEW', null, $renewalTxJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        // Assert canonical subscription ends_at was updated to the new authoritative store expiry
        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->ends_at->isFuture());
        $this->assertTrue($sub->ends_at->greaterThan(now()->addDays(28)));

        // Assert new renewal store transaction recorded
        $this->assertDatabaseHas('store_transactions', [
            'user_id' => $this->user->id,
            'transaction_id' => 'tx_apple_3002',
            'original_transaction_id' => 'orig_tx_apple_3001',
            'status' => 'active',
        ]);

        // Assert new payment record created for renewal
        $this->assertDatabaseHas('subscription_payments', [
            'user_id' => $this->user->id,
            'payment_method' => 'app_store',
            'transaction_id' => 'tx_apple_3002',
        ]);
    }

    public function test_did_change_renewal_status_disabled_preserves_entitlement_until_expiry(): void
    {
        $futureDate = now()->addDays(20);
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(10),
            'ends_at' => $futureDate,
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_4001',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_4001',
            'original_transaction_id' => 'orig_tx_apple_4001',
            'purchased_at' => now()->subDays(10),
            'expires_at' => $futureDate,
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $txJws = $this->generateTransactionJws('tx_apple_4001', 'orig_tx_apple_4001', 'skillso_monthly_sub');
        $notifJws = $this->generateNotificationJws('DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED', $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        $sub->refresh();
        // auto_renew disabled, BUT status is STILL active and ends_at is NOT changed!
        $this->assertFalse($sub->auto_renew);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->is_active);
    }

    public function test_did_fail_to_renew_grace_period_retains_entitlement(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_5001',
        ]);

        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_5001',
            'original_transaction_id' => 'orig_tx_apple_5001',
            'purchased_at' => now()->subDays(30),
            'expires_at' => now()->subHour(),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $txJws = $this->generateTransactionJws('tx_apple_5001', 'orig_tx_apple_5001', 'skillso_monthly_sub');
        $notifJws = $this->generateNotificationJws('DID_FAIL_TO_RENEW', 'GRACE_PERIOD', $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        $storeTx->refresh();
        $this->assertEquals('in_grace_period', $storeTx->status);
    }

    public function test_expired_notification_expires_canonical_subscription(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDay(),
            'auto_renew' => false,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_6001',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_6001',
            'original_transaction_id' => 'orig_tx_apple_6001',
            'purchased_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
            'status' => 'active',
            'auto_renew' => false,
            'is_verified' => true,
        ]);

        $expiredMs = (time() - 86400) * 1000;
        $txJws = $this->generateTransactionJws('tx_apple_6001', 'orig_tx_apple_6001', 'skillso_monthly_sub', $expiredMs);
        $notifJws = $this->generateNotificationJws('EXPIRED', 'VOLUNTARY', $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_EXPIRED, $sub->status);
    }

    public function test_refund_current_active_transaction_revokes_entitlement(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_7001',
        ]);

        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_7001',
            'original_transaction_id' => 'orig_tx_apple_7001',
            'purchased_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $revocationTimeMs = time() * 1000;
        $txJws = $this->generateTransactionJws('tx_apple_7001', 'orig_tx_apple_7001', 'skillso_monthly_sub', null, $revocationTimeMs);
        $notifJws = $this->generateNotificationJws('REFUND', null, $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        $storeTx->refresh();
        $this->assertTrue($storeTx->is_refunded);
        $this->assertEquals('refunded', $storeTx->status);

        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_CANCELLED, $sub->status);
    }

    public function test_refund_historical_transaction_does_not_revoke_newer_active_renewal(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->addDays(20),
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_8001',
        ]);

        // Old historical transaction (Cycle 1)
        $oldTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_8001_old',
            'original_transaction_id' => 'orig_tx_apple_8001',
            'purchased_at' => now()->subDays(40),
            'expires_at' => now()->subDays(10),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        // Newer active renewal transaction (Cycle 2)
        $activeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_8002_active',
            'original_transaction_id' => 'orig_tx_apple_8001',
            'purchased_at' => now()->subDays(10),
            'expires_at' => now()->addDays(20),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        // Refund arrives specifically targeting the OLD transaction (tx_apple_8001_old)
        $revocationTimeMs = time() * 1000;
        $txJws = $this->generateTransactionJws('tx_apple_8001_old', 'orig_tx_apple_8001', 'skillso_monthly_sub', null, $revocationTimeMs);
        $notifJws = $this->generateNotificationJws('REFUND', null, $txJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(200);

        $oldTx->refresh();
        $this->assertTrue($oldTx->is_refunded);

        // Crucial: Active subscription must NOT be revoked!
        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->is_active);
    }

    public function test_duplicate_notification_uuid_is_idempotent_and_harmless(): void
    {
        $txJws = $this->generateTransactionJws('tx_apple_idemp', 'orig_tx_apple_idemp');
        $uuid = 'fixed-uuid-12345';
        $notifJws = $this->generateNotificationJws('TEST', null, $txJws, $uuid);

        // First delivery
        $res1 = $this->postJson('/api/webhooks/apple/app-store', ['signedPayload' => $notifJws]);
        $res1->assertStatus(200);

        $eventCountBefore = StoreNotificationEvent::where('external_event_id', $uuid)->count();
        $this->assertEquals(1, $eventCountBefore);

        // Second delivery of same UUID
        $res2 = $this->postJson('/api/webhooks/apple/app-store', ['signedPayload' => $notifJws]);
        $res2->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_acknowledged');

        // Assert event count did not increase
        $eventCountAfter = StoreNotificationEvent::where('external_event_id', $uuid)->count();
        $this->assertEquals(1, $eventCountAfter);
    }

    public function test_wrong_bundle_id_is_rejected(): void
    {
        $txJws = $this->generateTransactionJws('tx_apple_wrong_bundle', 'orig_tx_apple_wrong_bundle');
        $notifJws = $this->generateNotificationJws('TEST', null, $txJws, null, 'com.othercompany.unrelatedapp');

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);

        $response->assertStatus(400);
    }

    public function test_out_of_order_stale_expired_after_newer_renewal_does_not_regress_state(): void
    {
        $futureExpiry = now()->addDays(28);
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(2),
            'ends_at' => $futureExpiry,
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_stale',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'app_store',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'tx_apple_newer_active',
            'original_transaction_id' => 'orig_tx_apple_stale',
            'purchased_at' => now()->subDays(2),
            'expires_at' => $futureExpiry,
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        // Stale EXPIRED event arrives referring to an older past period (ends_at = yesterday)
        $pastExpiryMs = (time() - 86400) * 1000;
        $staleTxJws = $this->generateTransactionJws('tx_apple_older_period', 'orig_tx_apple_stale', 'skillso_monthly_sub', $pastExpiryMs);
        $staleNotifJws = $this->generateNotificationJws('EXPIRED', 'VOLUNTARY', $staleTxJws);

        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $staleNotifJws,
        ]);

        $response->assertStatus(200);

        // Subscription must NOT have regressed to expired!
        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->is_active);
    }
}
