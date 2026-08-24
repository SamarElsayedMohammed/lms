<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\StoreNotificationEvent;
use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreBillingDisabledModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        // Default frozen configuration (Web-Managed Mode)
        config([
            'store_billing.enabled' => false,
            'store_billing.apple_enabled' => false,
            'store_billing.google_enabled' => false,
            'store_billing.notifications_enabled' => false,
            'store_billing.lifecycle_processing_enabled' => false,
            // Clear credentials to prove optionality in frozen mode
            'store_billing.apple.key_id' => '',
            'store_billing.apple.issuer_id' => '',
            'store_billing.apple.private_key' => '',
            'store_billing.google.service_account_path' => '',
            'store_billing.google.service_account_json' => '',
        ]);

        $this->user = User::factory()->create([
            'email' => 'disabled_mode@skillso.test',
            'wallet_balance' => 100,
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Monthly Plan',
            'slug' => 'monthly',
            'price' => 500,
            'usd_price' => 20,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
    }

    public function test_verify_purchase_returns_403_store_billing_disabled(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/purchase/verify', [
            'provider' => 'app_store',
            'product_id' => 'skillso_monthly_sub',
            'signed_transaction' => 'dummy_jws',
        ], [
            'Idempotency-Key' => 'disabled-verify-key-1',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('error', true)
            ->assertJsonPath('data.reason', 'STORE_BILLING_DISABLED');

        // Assert no transactions created
        $this->assertEquals(0, StoreTransaction::count());
    }

    public function test_restore_purchases_returns_403_store_billing_disabled(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/restore', [
            'provider' => 'google_play',
            'product_id' => 'skillso_monthly_sub',
            'purchase_token' => 'dummy_token',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('error', true)
            ->assertJsonPath('data.reason', 'STORE_BILLING_DISABLED');
    }

    public function test_get_products_returns_empty_when_store_billing_disabled(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/billing/products');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data', []);
    }

    public function test_apple_webhook_returns_disabled_without_db_mutation(): void
    {
        $response = $this->postJson('/api/webhooks/apple/app-store', [
            'signedPayload' => 'any_payload',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'disabled');

        // Assert no notification ledger entries created
        $this->assertEquals(0, StoreNotificationEvent::count());
    }

    public function test_google_rtdn_returns_disabled_without_db_mutation(): void
    {
        $response = $this->postJson('/api/webhooks/google-play/rtdn', [
            'message' => [
                'messageId' => 'msg_disabled_123',
                'data' => base64_encode('{}'),
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'disabled');

        $this->assertEquals(0, StoreNotificationEvent::count());
    }

    public function test_entitlements_endpoint_remains_active_and_operational_in_disabled_mode(): void
    {
        // User has a web-created active subscription
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
            'auto_renew' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/billing/entitlements/me');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.status', 'active');
    }
}
