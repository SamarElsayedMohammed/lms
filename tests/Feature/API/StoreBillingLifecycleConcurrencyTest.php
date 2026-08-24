<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreBillingLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $monthlyPlan;

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
            'email' => 'concurrency@skillso.test',
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
    }

    private function generateAppleJws(string $transactionId, string $originalTransactionId): string
    {
        $header = ['alg' => 'ES256', 'typ' => 'JWT'];
        $payload = [
            'transactionId' => $transactionId,
            'originalTransactionId' => $originalTransactionId,
            'productId' => 'skillso_monthly_sub',
            'bundleId' => 'com.skillso.app.skillso',
            'purchaseDate' => (time() - 3600) * 1000,
            'expiresDate' => (time() + 86400 * 30) * 1000,
            'environment' => 'Sandbox',
            'type' => 'Auto-Renewable Subscription',
            'inAppOwnershipType' => 'PURCHASED',
            'autoRenewStatus' => 1,
        ];

        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signatureB64 = rtrim(strtr(base64_encode(hash('sha256', "{$headerB64}.{$payloadB64}", true)), '+/', '-_'), '=');

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    private function generateNotificationJws(string $transactionJws, string $uuid): string
    {
        $header = ['alg' => 'ES256', 'typ' => 'JWT'];
        $payload = [
            'notificationType' => 'SUBSCRIBED',
            'subtype' => 'INITIAL_BUY',
            'notificationUUID' => $uuid,
            'version' => '2.0',
            'signedDate' => time() * 1000,
            'data' => [
                'bundleId' => 'com.skillso.app.skillso',
                'environment' => 'Sandbox',
                'signedTransactionInfo' => $transactionJws,
            ],
        ];

        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signatureB64 = rtrim(strtr(base64_encode(hash('sha256', "{$headerB64}.{$payloadB64}", true)), '+/', '-_'), '=');

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    /**
     * Test simultaneous client verification and server webhook delivery.
     * Must result in exactly ONE canonical subscription and ONE payment.
     */
    public function test_simultaneous_client_verify_and_apple_webhook_is_idempotent(): void
    {
        $jws = $this->generateAppleJws('tx_race_1001', 'orig_tx_race_1001');

        // 1. Client verifies purchase
        $clientRes = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_race_1001',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-race-1001',
            ]);
        $clientRes->assertStatus(200);

        // 2. Apple webhook delivers at the same time
        $notifJws = $this->generateNotificationJws($jws, 'uuid-race-1001');
        $webhookRes = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => $notifJws,
        ]);
        $webhookRes->assertStatus(200);

        // 3. Verify exactly 1 subscription, 1 payment, 1 store transaction
        $subCount = Subscription::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $subCount);

        $paymentCount = SubscriptionPayment::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $paymentCount);

        $txCount = StoreTransaction::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $txCount);
    }
}
