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

final class GooglePlayRtdnWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $monthlyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'store_billing.enabled' => true,
            'store_billing.google_enabled' => true,
            'store_billing.notifications_enabled' => true,
            'store_billing.lifecycle_processing_enabled' => true,
            'store_billing.mock_verification_enabled' => true,
        ]);

        $this->user = User::factory()->create([
            'email' => 'google_user@skillso.test',
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

    /**
     * Helper to generate mock Cloud Pub/Sub Push payload
     */
    private function generatePubSubPayload(array $developerNotification, string $messageId = 'msg_1001'): array
    {
        return [
            'message' => [
                'messageId' => $messageId,
                'publishTime' => now()->toIso8601String(),
                'data' => base64_encode(json_encode($developerNotification)),
            ],
            'subscription' => 'projects/skillso-prod/subscriptions/play-store-rtdn-push',
        ];
    }

    public function test_valid_test_notification(): void
    {
        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'testNotification' => ['version' => '1.0'],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('store_notification_events', [
            'store' => 'google_play',
            'event_type' => 'TEST',
            'processing_status' => 'processed',
        ]);
    }

    public function test_subscription_renewed_extends_canonical_subscription(): void
    {
        $token = 'token_google_renew_12345';
        $tokenHash = StoreTransaction::hashToken($token);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->addMinutes(10),
            'auto_renew' => true,
            'store_provider' => 'google_play',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'google_play',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'GPA.OLD-CYCLE-1',
            'original_transaction_id' => $token,
            'purchase_token' => $token,
            'purchase_token_hash' => $tokenHash,
            'purchased_at' => now()->subDays(30),
            'expires_at' => now()->addMinutes(10),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => 2, // SUBSCRIPTION_RENEWED
                'purchaseToken' => $token,
                'subscriptionId' => 'skillso_monthly_sub',
            ],
        ], 'msg_renew_999');

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->ends_at->isFuture());
        $this->assertTrue($sub->ends_at->greaterThan(now()->addDays(28)));

        // Assert payment record was created
        $this->assertDatabaseHas('subscription_payments', [
            'user_id' => $this->user->id,
            'payment_method' => 'google_play',
        ]);
    }

    public function test_subscription_canceled_preserves_entitlement_until_expiry(): void
    {
        $token = 'token_google_cancel_12345';
        $tokenHash = StoreTransaction::hashToken($token);
        $futureExpiry = now()->addDays(20);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(10),
            'ends_at' => $futureExpiry,
            'auto_renew' => true,
            'store_provider' => 'google_play',
        ]);

        StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'google_play',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'GPA.CANCEL-TEST',
            'original_transaction_id' => $token,
            'purchase_token' => $token,
            'purchase_token_hash' => $tokenHash,
            'purchased_at' => now()->subDays(10),
            'expires_at' => $futureExpiry,
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => 3, // SUBSCRIPTION_CANCELED
                'purchaseToken' => $token,
                'subscriptionId' => 'skillso_monthly_sub',
            ],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertFalse($sub->auto_renew);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->is_active);
    }

    public function test_subscription_in_grace_period_retains_access(): void
    {
        $token = 'token_google_grace_12345';
        $tokenHash = StoreTransaction::hashToken($token);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
            'store_provider' => 'google_play',
        ]);

        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'google_play',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'GPA.GRACE-TEST',
            'original_transaction_id' => $token,
            'purchase_token' => $token,
            'purchase_token_hash' => $tokenHash,
            'purchased_at' => now()->subDays(30),
            'expires_at' => now()->subHour(),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => 6, // SUBSCRIPTION_IN_GRACE_PERIOD
                'purchaseToken' => $token,
                'subscriptionId' => 'skillso_monthly_sub',
            ],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200);

        $storeTx->refresh();
        $this->assertEquals('in_grace_period', $storeTx->status);
    }

    public function test_subscription_on_hold_suspends_access(): void
    {
        $token = 'token_google_on_hold_12345';
        $tokenHash = StoreTransaction::hashToken($token);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
            'store_provider' => 'google_play',
        ]);

        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'google_play',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'GPA.HOLD-TEST',
            'original_transaction_id' => $token,
            'purchase_token' => $token,
            'purchase_token_hash' => $tokenHash,
            'purchased_at' => now()->subDays(30),
            'expires_at' => now()->subHour(),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => 5, // SUBSCRIPTION_ON_HOLD
                'purchaseToken' => $token,
                'subscriptionId' => 'skillso_monthly_sub',
            ],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_EXPIRED, $sub->status);
        $this->assertFalse($sub->is_active);
    }

    public function test_subscription_revoked_cancels_subscription_immediately(): void
    {
        $token = 'token_google_revoked_12345';
        $tokenHash = StoreTransaction::hashToken($token);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->monthlyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'auto_renew' => true,
            'store_provider' => 'google_play',
        ]);

        $storeTx = StoreTransaction::create([
            'user_id' => $this->user->id,
            'subscription_id' => $sub->id,
            'plan_id' => $this->monthlyPlan->id,
            'store' => 'google_play',
            'environment' => 'sandbox',
            'store_product_id' => 'skillso_monthly_sub',
            'transaction_id' => 'GPA.REVOKE-TEST',
            'original_transaction_id' => $token,
            'purchase_token' => $token,
            'purchase_token_hash' => $tokenHash,
            'purchased_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
            'status' => 'active',
            'auto_renew' => true,
            'is_verified' => true,
        ]);

        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => 12, // SUBSCRIPTION_REVOKED
                'purchaseToken' => $token,
                'subscriptionId' => 'skillso_monthly_sub',
            ],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_CANCELLED, $sub->status);
    }

    public function test_duplicate_pubsub_message_id_is_idempotent_and_harmless(): void
    {
        $messageId = 'fixed-google-msg-id-123';
        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.skillso.app',
            'eventTimeMillis' => time() * 1000,
            'testNotification' => ['version' => '1.0'],
        ], $messageId);

        // First delivery
        $res1 = $this->postJson('/api/webhooks/google-play/rtdn', $payload);
        $res1->assertStatus(200);

        $eventCountBefore = StoreNotificationEvent::where('external_event_id', $messageId)->count();
        $this->assertEquals(1, $eventCountBefore);

        // Second delivery
        $res2 = $this->postJson('/api/webhooks/google-play/rtdn', $payload);
        $res2->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_acknowledged');

        $eventCountAfter = StoreNotificationEvent::where('external_event_id', $messageId)->count();
        $this->assertEquals(1, $eventCountAfter);
    }

    public function test_wrong_package_name_is_rejected(): void
    {
        $payload = $this->generatePubSubPayload([
            'version' => '1.0',
            'packageName' => 'com.unrelated.foreignapp',
            'eventTimeMillis' => time() * 1000,
            'testNotification' => ['version' => '1.0'],
        ]);

        $response = $this->postJson('/api/webhooks/google-play/rtdn', $payload);

        $response->assertStatus(400);
    }
}
