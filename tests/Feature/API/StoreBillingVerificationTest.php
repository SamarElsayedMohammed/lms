<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreBillingVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private SubscriptionPlan $monthlyPlan;
    private SubscriptionPlan $yearlyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'store_billing.enabled' => true,
            'store_billing.apple_enabled' => true,
            'store_billing.google_enabled' => true,
            'store_billing.mock_verification_enabled' => true,
        ]);

        $this->user = User::factory()->create([
            'email' => 'student@skillso.test',
            'wallet_balance' => 0,
        ]);

        $this->otherUser = User::factory()->create([
            'email' => 'attacker@skillso.test',
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
     * Helper to generate mock Apple StoreKit 2 JWS
     */
    private function generateAppleJws(
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

    public function test_get_products_returns_mapped_store_products(): void
    {
        $response = $this->getJson('/api/billing/products');

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $productIds = array_column($data, 'store_product_id');
        $this->assertContains('skillso_monthly_sub', $productIds);
        $this->assertContains('skillso_yearly_sub', $productIds);
    }

    public function test_apple_valid_purchase_verification_activates_subscription_and_entitlement(): void
    {
        $jws = $this->generateAppleJws('tx_apple_1001', 'orig_tx_apple_1001', 'skillso_monthly_sub');

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_1001',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-1001',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.entitlement.status', 'active');

        // Check DB state
        $this->assertDatabaseHas('store_transactions', [
            'user_id' => $this->user->id,
            'store' => 'app_store',
            'transaction_id' => 'tx_apple_1001',
            'original_transaction_id' => 'orig_tx_apple_1001',
            'is_verified' => true,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => 'active',
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_1001',
        ]);

        $this->assertDatabaseHas('subscription_payments', [
            'user_id' => $this->user->id,
            'status' => 'completed',
            'payment_method' => 'app_store',
            'transaction_id' => 'tx_apple_1001',
        ]);
    }

    public function test_apple_expired_purchase_is_rejected(): void
    {
        $expiredTimeMs = (time() - 86400 * 2) * 1000;
        $jws = $this->generateAppleJws('tx_apple_expired', 'orig_tx_apple_expired', 'skillso_monthly_sub', $expiredTimeMs);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_expired',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-expired',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.error_code', 'expired_purchase');
    }

    public function test_apple_revoked_purchase_is_rejected(): void
    {
        $revocationTimeMs = (time() - 3600) * 1000;
        $jws = $this->generateAppleJws('tx_apple_revoked', 'orig_tx_apple_revoked', 'skillso_monthly_sub', null, $revocationTimeMs);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_revoked',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-revoked',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.error_code', 'revoked_purchase');
    }

    public function test_apple_invalid_jws_structure_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'signed_transaction' => 'invalid-jws-string',
            ], [
                'Idempotency-Key' => 'idemp-invalid-jws',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    public function test_google_valid_purchase_verification_activates_subscription_and_entitlement(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'google_play',
                'product_id' => 'skillso_monthly_sub',
                'purchase_token' => 'valid-google-token-xyz-12345',
            ], [
                'Idempotency-Key' => 'idemp-google-12345',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.is_premium', true);

        $this->assertDatabaseHas('store_transactions', [
            'user_id' => $this->user->id,
            'store' => 'google_play',
            'purchase_token' => 'valid-google-token-xyz-12345',
            'is_verified' => true,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => 'active',
            'store_provider' => 'google_play',
        ]);
    }

    public function test_google_expired_subscription_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'google_play',
                'product_id' => 'skillso_monthly_sub',
                'purchase_token' => 'expired-google-token-999',
            ], [
                'Idempotency-Key' => 'idemp-google-expired',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.error_code', 'expired_purchase');
    }

    public function test_google_revoked_subscription_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'google_play',
                'product_id' => 'skillso_monthly_sub',
                'purchase_token' => 'revoked-google-token-999',
            ], [
                'Idempotency-Key' => 'idemp-google-revoked',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.error_code', 'revoked_purchase');
    }

    /**
     * P0 Security: Prevent cross-account purchase token / transaction theft.
     */
    public function test_cross_account_purchase_theft_is_blocked_p0(): void
    {
        // 1. User A purchases and claims transaction
        $jws = $this->generateAppleJws('tx_apple_unique_1', 'orig_tx_apple_shared_root', 'skillso_monthly_sub');

        $resA = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_unique_1',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-unique-1',
            ]);

        $resA->assertStatus(200);

        // 2. User B tries to claim the SAME original transaction ID
        $jwsB = $this->generateAppleJws('tx_apple_unique_2', 'orig_tx_apple_shared_root', 'skillso_monthly_sub');

        $resB = $this->actingAs($this->otherUser)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_unique_2',
                'signed_transaction' => $jwsB,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-unique-2',
            ]);

        $resB->assertStatus(409)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.error_code', 'transaction_already_owned');

        // User B must NOT have an active subscription
        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $this->otherUser->id,
            'status' => 'active',
        ]);
    }

    /**
     * P0 Idempotency: Submitting the same transaction twice is safe and does not duplicate payments.
     */
    public function test_idempotent_duplicate_verification_returns_success_without_duplicate_payments(): void
    {
        $jws = $this->generateAppleJws('tx_apple_idempotent_1', 'orig_tx_apple_idempotent_1', 'skillso_monthly_sub');

        // First verification
        $res1 = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_idempotent_1',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-idemp-1',
            ]);
        $res1->assertStatus(200);

        $paymentCountBefore = SubscriptionPayment::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $paymentCountBefore);

        // Second verification (same user, same transaction, new request key simulating retry)
        $res2 = $this->actingAs($this->user)
            ->postJson('/api/billing/purchase/verify', [
                'provider' => 'app_store',
                'product_id' => 'skillso_monthly_sub',
                'transaction_id' => 'tx_apple_idempotent_1',
                'signed_transaction' => $jws,
            ], [
                'Idempotency-Key' => 'idemp-tx-apple-idemp-2',
            ]);
        $res2->assertStatus(200)
            ->assertJsonPath('data.is_premium', true);

        // Assert payment count did NOT increase
        $paymentCountAfter = SubscriptionPayment::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $paymentCountAfter);
    }

    public function test_restore_purchases_reconciles_active_receipts(): void
    {
        $jws = $this->generateAppleJws('tx_apple_restore_1', 'orig_tx_apple_restore_1', 'skillso_yearly_sub');

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/restore', [
                'receipts' => [
                    [
                        'provider' => 'app_store',
                        'product_id' => 'skillso_yearly_sub',
                        'transaction_id' => 'tx_apple_restore_1',
                        'signed_transaction' => $jws,
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.restored_count', 1)
            ->assertJsonPath('data.is_premium', true);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->yearlyPlan->id,
            'status' => 'active',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/billing/purchase/verify', [
            'provider' => 'app_store',
            'product_id' => 'skillso_monthly_sub',
        ], [
            'Idempotency-Key' => 'idemp-unauth',
        ]);

        $response->assertStatus(401);
    }
}
