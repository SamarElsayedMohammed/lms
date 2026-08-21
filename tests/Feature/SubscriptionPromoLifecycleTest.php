<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionPromoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SubscriptionPromoLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPromoService $promoService;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promoService = app(SubscriptionPromoService::class);
        $this->admin = User::factory()->create();
        \App\Models\FeatureFlag::updateOrCreate(['key' => 'affiliate_system'], ['name' => 'Affiliate System', 'is_enabled' => true]);
        \Illuminate\Support\Facades\Cache::flush();
        app(\App\Services\FeatureFlagService::class)->clearCache('affiliate_system');
        Storage::fake('private');
        Notification::fake();
    }

    protected function createPlan(array $attributes = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'name' => 'Plan ' . uniqid(),
            'slug' => 'plan-' . uniqid(),
            'price' => 100.00,
            'usd_price' => 10.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ], $attributes));
    }

    protected function createPromo(array $attributes = []): PromoCode
    {
        return PromoCode::create(array_merge([
            'user_id' => $this->admin->id,
            'message' => 'Default promo message',
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'repeat_usage' => true,
        ], $attributes));
    }

    /**
     * PT-01 / LI-01: Promo validation does not consume quota.
     */
    public function test_promo_validation_does_not_consume_quota(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Gold Plan',
            'slug' => 'gold-plan',
            'price' => 200.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'SAVE50',
            'message' => '50% discount',
            'discount' => 50,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'repeat_usage' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/subscription/validate-promo', [
            'promo_code' => 'SAVE50',
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(5, $promo->fresh()->no_of_users);
        $this->assertEquals(0, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(5, $promo->fresh()->remaining_uses);
    }

    /**
     * PT-02 / LI-15: Successful wallet payment consumes quota exactly once.
     */
    public function test_successful_wallet_payment_consumes_quota_exactly_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500.00]);
        $plan = $this->createPlan([
            'name' => 'Gold Plan 2',
            'slug' => 'gold-plan-2',
            'price' => 200.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'WALLET50',
            'message' => '50% discount',
            'discount' => 50,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'repeat_usage' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'wallet-sub-1')
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'wallet',
                'use_wallet' => true,
                'promo_code' => 'WALLET50',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $promo->fresh()->no_of_users); // Cap remains 10
        $this->assertEquals(1, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(9, $promo->fresh()->remaining_uses);

        $payment = SubscriptionPayment::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->status);
        $this->assertEquals('WALLET50', $payment->promo_code);
        $this->assertEquals(100.00, (float) $payment->final_amount);
    }

    /**
     * PT-03 / LI-11: Manual subscription pending reserves quota.
     */
    public function test_manual_subscription_pending_reserves_quota(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Silver Plan',
            'slug' => 'silver-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Bank Wire',
            'type' => 'bank_transfer',
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'MANUAL20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
        ]);

        $receipt = UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg');
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'manual-sub-1')
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'manual',
                'payment_method_id' => (string) $method->id,
                'promo_code' => 'MANUAL20',
                'receipt' => $receipt,
                'transaction_id' => 'WIRE-001',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(5, $promo->fresh()->no_of_users);
        $this->assertEquals(0, $promo->fresh()->used_count);
        $this->assertEquals(1, $promo->fresh()->reserved_count);
        $this->assertEquals(4, $promo->fresh()->remaining_uses);

        $redemption = PromoRedemption::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($redemption);
        $this->assertEquals(PromoRedemption::STATUS_RESERVED, $redemption->status);
    }

    /**
     * PT-04 / LI-15, LI-19: Manual subscription approval consumes reservation exactly once.
     */
    public function test_manual_subscription_approval_consumes_reservation_exactly_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0.00]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = $this->createPlan([
            'name' => 'Silver Plan 2',
            'slug' => 'silver-plan-2',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'APPROVE20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING_APPROVAL,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 80.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 80.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'manual',
            'promo_code' => 'APPROVE20',
            'discount_amount' => 20.00,
            'final_amount' => 80.00,
        ]);

        $redemption = PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'APPROVE20',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_RESERVED,
            'currency' => 'EGP',
            'original_amount' => 100.00,
            'discount_amount' => 20.00,
            'final_amount' => 80.00,
            'discount_type_snapshot' => 'percentage',
            'discount_value_snapshot' => 20,
            'reserved_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/approve", [
            'admin_notes' => 'Approved',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(5, $promo->fresh()->no_of_users);
        $this->assertEquals(1, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(4, $promo->fresh()->remaining_uses);
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->fresh()->status);
        $this->assertEquals(PromoRedemption::STATUS_CONSUMED, $redemption->fresh()->status);
    }

    /**
     * PT-05, PT-06 / LI-12, LI-16, LI-20: Manual subscription rejection releases reservation exactly once.
     */
    public function test_manual_subscription_rejection_releases_reservation_exactly_once(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = $this->createPlan([
            'name' => 'Silver Plan 3',
            'slug' => 'silver-plan-3',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'REJECT20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING_APPROVAL,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 80.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 80.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'manual',
            'promo_code' => 'REJECT20',
            'discount_amount' => 20.00,
            'final_amount' => 80.00,
        ]);

        $redemption = PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'REJECT20',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_RESERVED,
            'currency' => 'EGP',
            'original_amount' => 100.00,
            'discount_amount' => 20.00,
            'final_amount' => 80.00,
            'discount_type_snapshot' => 'percentage',
            'discount_value_snapshot' => 20,
            'reserved_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/reject", [
            'admin_notes' => 'Invalid transaction slip',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(5, $promo->fresh()->no_of_users);
        $this->assertEquals(0, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(5, $promo->fresh()->remaining_uses);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_CANCELLED, $sub->fresh()->status);
        $this->assertEquals(PromoRedemption::STATUS_RELEASED, $redemption->fresh()->status);

        // PT-06: Second rejection attempt is idempotent
        $response2 = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/reject", [
            'admin_notes' => 'Second reject call',
        ]);
        $response2->assertStatus(200);
        $this->assertEquals(5, $promo->fresh()->remaining_uses);
    }

    /**
     * PT-08, PT-09 / LI-11, LI-13: Kashier checkout reserves quota; failure releases quota.
     */
    public function test_kashier_failure_releases_quota(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Kashier Plan',
            'slug' => 'kashier-plan',
            'price' => 150.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'KASHFAIL',
            'message' => '10% off',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
        ]);

        $orderId = "sub_{$plan->id}_{$user->id}_123456_test";
        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 135.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 135.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'kashier',
            'promo_code' => 'KASHFAIL',
            'discount_amount' => 15.00,
            'final_amount' => 135.00,
            'transaction_id' => $orderId,
        ]);

        $redemption = PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'KASHFAIL',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_RESERVED,
            'currency' => 'EGP',
            'original_amount' => 150.00,
            'discount_amount' => 15.00,
            'final_amount' => 135.00,
            'reserved_at' => now(),
        ]);

        $mock = $this->mock(\App\Services\Payment\KashierCheckoutService::class);
        $mock->shouldReceive('verifyPayment')->andReturn(true);
        $mock->shouldReceive('getPaymentDetails')->andReturn(null);
        $mock->shouldReceive('getPaymentDetailsByOrderId')->andReturn(null);

        $response = $this->post('/api/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'FAILED',
            'signature' => 'mock_signature',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(10, $promo->fresh()->remaining_uses);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_CANCELLED, $sub->fresh()->status);
    }

    /**
     * PT-11, PT-12, PT-13, PT-14 / LI-15, LI-18, LI-21, LI-26:
     * Kashier success consumes reservation, works even if cache missing, preserves snapshot, and duplicate webhook is idempotent.
     */
    public function test_kashier_success_consumes_reservation_and_is_idempotent_without_cache(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Kashier Plan 2',
            'slug' => 'kashier-plan-2',
            'price' => 300.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'KASHSUCCESS',
            'message' => '50 EGP off',
            'discount' => 50,
            'discount_type' => 'amount',
            'no_of_users' => 5,
            'status' => 1,
        ]);

        $orderId = "sub_{$plan->id}_{$user->id}_654321_succ";
        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 250.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 250.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'kashier',
            'promo_code' => 'KASHSUCCESS',
            'original_amount' => 300.00,
            'discount_amount' => 50.00,
            'final_amount' => 250.00,
            'currency_code' => 'EGP',
            'transaction_id' => $orderId,
        ]);

        $redemption = PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'KASHSUCCESS',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_RESERVED,
            'currency' => 'EGP',
            'original_amount' => 300.00,
            'discount_amount' => 50.00,
            'final_amount' => 250.00,
            'reserved_at' => now(),
        ]);

        // Explicitly clear cache to prove durable payment intent
        Cache::forget('kashier_pending_' . $orderId);

        $mock = $this->mock(\App\Services\Payment\KashierCheckoutService::class);
        $mock->shouldReceive('verifyPayment')->andReturn(true);
        $mock->shouldReceive('getPaymentDetails')->andReturn(null);
        $mock->shouldReceive('getPaymentDetailsByOrderId')->andReturn(null);

        // First webhook delivery
        $response1 = $this->post('/api/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'SUCCESS',
            'amount' => 250.00,
            'transactionId' => 'KASH-TXN-12345',
            'signature' => 'mock_signature',
        ]);

        $response1->assertStatus(200);
        $this->assertEquals(1, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(4, $promo->fresh()->remaining_uses);
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->fresh()->status);
        $this->assertEquals(50.00, (float) $payment->fresh()->discount_amount);
        $this->assertEquals('KASHSUCCESS', $payment->fresh()->promo_code);

        // Duplicate webhook delivery is idempotent
        $response2 = $this->post('/api/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'SUCCESS',
            'amount' => 250.00,
            'transactionId' => 'KASH-TXN-12345',
            'signature' => 'mock_signature',
        ]);
        $response2->assertStatus(200);
        $this->assertEquals(1, $promo->fresh()->used_count);
    }

    /**
     * PT-10 / LI-14: Abandoned / expired Kashier reservation is reclaimed.
     */
    public function test_abandoned_kashier_reservation_is_reclaimed(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Expire Plan',
            'slug' => 'expire-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'RECLAIM10',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 1,
            'status' => 1,
        ]);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 90.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 90.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'kashier',
            'promo_code' => 'RECLAIM10',
        ]);

        $redemption = PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'RECLAIM10',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_RESERVED,
            'currency' => 'EGP',
            'original_amount' => 100.00,
            'discount_amount' => 10.00,
            'final_amount' => 90.00,
            'reserved_at' => now()->subHours(5),
        ]);

        DB::table('subscription_payments')->where('id', $payment->id)->update([
            'created_at' => now()->subHours(5),
        ]);

        $reclaimed = $this->promoService->reclaimExpiredReservations(4);
        $this->assertGreaterThanOrEqual(1, $reclaimed);
        $this->assertEquals(0, $promo->fresh()->used_count);
        $this->assertEquals(0, $promo->fresh()->reserved_count);
        $this->assertEquals(1, $promo->fresh()->remaining_uses);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertEquals(PromoRedemption::STATUS_EXPIRED, $redemption->fresh()->status);
    }

    /**
     * PT-17, PT-18 / LI-09, LI-10: Non-repeatable promo cannot be reused by same user.
     */
    public function test_non_repeatable_promo_enforces_per_user_limit(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan([
            'name' => 'Single Use Plan',
            'slug' => 'single-use-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'ONCEONLY',
            'message' => 'One time only',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 100,
            'repeat_usage' => false,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(10),
        ]);

        // First validation succeeds
        $res1 = $this->promoService->validatePromo('ONCEONLY', $plan->id, $user);
        $this->assertTrue($res1['valid']);

        // User reserves and completes a payment
        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);
        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 90.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 90.00,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'promo_code' => 'ONCEONLY',
        ]);
        PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'promo_code' => 'ONCEONLY',
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'subscription_payment_id' => $payment->id,
            'status' => PromoRedemption::STATUS_CONSUMED,
            'currency' => 'EGP',
            'original_amount' => 100.00,
            'discount_amount' => 10.00,
            'final_amount' => 90.00,
            'consumed_at' => now(),
        ]);

        // Second validation fails
        $res2 = $this->promoService->validatePromo('ONCEONLY', $plan->id, $user);
        $this->assertFalse($res2['valid']);
        $this->assertStringContainsString('من قبل', $res2['message']);
    }

    /**
     * PT-20, PT-21 / LI-07: Date validity through 23:59:59 of end_date.
     */
    public function test_promo_date_validity_through_end_of_day(): void
    {
        $plan = $this->createPlan([
            'name' => 'Date Test Plan',
            'slug' => 'date-test-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'DATETEST',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
            'start_date' => now()->subDays(2)->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'), // Today
            'repeat_usage' => true,
        ]);

        // Valid on the final day
        $res = $this->promoService->validatePromo('DATETEST', $plan->id);
        $this->assertTrue($res['valid']);

        // Expired yesterday
        $promo->end_date = now()->subDay()->format('Y-m-d');
        $promo->save();

        $resExpired = $this->promoService->validatePromo('DATETEST', $plan->id);
        $this->assertFalse($resExpired['valid']);
        $this->assertStringContainsString('منتهي', $resExpired['message']);
    }

    /**
     * PT-22 / LI-03: Plan restriction enforcement during validation and reservation.
     */
    public function test_plan_restriction_enforcement(): void
    {
        $planA = $this->createPlan(['name' => 'Plan A', 'slug' => 'plan-a', 'price' => 100, 'billing_cycle' => 'monthly', 'duration_days' => 30, 'is_active' => true]);
        $planB = $this->createPlan(['name' => 'Plan B', 'slug' => 'plan-b', 'price' => 200, 'billing_cycle' => 'monthly', 'duration_days' => 30, 'is_active' => true]);

        $promo = $this->createPromo([
            'promo_code' => 'PLANALIMITED',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'repeat_usage' => true,
        ]);
        $promo->subscriptionPlans()->sync([$planA->id]);

        $validOnA = $this->promoService->validatePromo('PLANALIMITED', $planA->id);
        $this->assertTrue($validOnA['valid']);

        $invalidOnB = $this->promoService->validatePromo('PLANALIMITED', $planB->id);
        $this->assertFalse($invalidOnB['valid']);
        $this->assertStringContainsString('غير صالح لهذه الباقة', $invalidOnB['message']);
    }

    /**
     * PT-23, PT-24 / LI-05, LI-06: Code normalization and duplicate prevention.
     */
    public function test_code_normalization_and_duplicate_prevention(): void
    {
        $this->assertEquals('SUMMER2026', SubscriptionPromoService::normalizeCode('  summer2026  '));

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $res1 = $this->actingAs($admin)->postJson('/api/admin/promo-codes', [
            'promo_code' => '  normalize_me  ',
            'message' => 'Normalized code test',
            'discount' => 15,
            'discount_type' => 'percentage',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $res1->assertStatus(201);
        $this->assertEquals('NORMALIZE_ME', $res1->json('data.promo_code'));

        // PT-24: Duplicate normalized code rejected
        $res2 = $this->actingAs($admin)->postJson('/api/admin/promo-codes', [
            'promo_code' => 'normalize_me',
            'message' => 'Duplicate code test',
            'discount' => 15,
            'discount_type' => 'percentage',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $res2->assertStatus(422);
    }

    /**
     * Adversarial Test: Global Limit Concurrency Test.
     * Promo max_uses = 1. First reservation succeeds, second is rejected.
     */
    public function test_concurrency_race_condition_on_global_limit(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $plan = $this->createPlan(['price' => 100.00]);

        $promo = $this->createPromo([
            'promo_code' => 'CONCUR1',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 1,
            'status' => 1,
        ]);

        $resA = $this->promoService->reservePromo('CONCUR1', $userA->id, $plan->id);
        $this->assertTrue($resA['success']);

        $resB = $this->promoService->reservePromo('CONCUR1', $userB->id, $plan->id);
        $this->assertFalse($resB['success']);
        $this->assertEquals(1, $promo->fresh()->reserved_count);
        $this->assertEquals(0, $promo->fresh()->remaining_uses);
    }

    /**
     * Adversarial Test: Immutable Price Snapshots.
     * Changing plan price after purchase does not corrupt historical transaction record.
     */
    public function test_immutable_price_snapshots(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan(['price' => 2000.00]);

        $promo = $this->createPromo([
            'promo_code' => 'SNAP10',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
        ]);

        $reserve = $this->promoService->reservePromo('SNAP10', $user->id, $plan->id);
        $this->assertTrue($reserve['success']);

        $this->assertEquals(2000.00, $reserve['original_amount']);
        $this->assertEquals(200.00, $reserve['discount_amount']);
        $this->assertEquals(1800.00, $reserve['final_amount']);

        // Admin subsequently changes plan price to 3000
        $plan->update(['price' => 3000.00]);

        // Historical redemption snapshot remains unchanged
        $redemption = $reserve['redemption']->fresh();
        $this->assertEquals(2000.00, (float) $redemption->original_amount);
        $this->assertEquals(200.00, (float) $redemption->discount_amount);
        $this->assertEquals(1800.00, (float) $redemption->final_amount);
    }

    /**
     * Adversarial Test: Legacy usage is never dropped when a new redemption is created.
     */
    public function test_legacy_usage_is_preserved_when_new_redemptions_are_created(): void
    {
        $plan = $this->createPlan(['price' => 200.00]);
        $promo = $this->createPromo([
            'promo_code' => 'LEGACY5',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
            'repeat_usage' => true,
        ]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // 2 Legacy completed subscription payments (without PromoRedemption rows)
        $sub1 = Subscription::create(['user_id' => $user1->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE, 'starts_at' => now()]);
        $sub2 = Subscription::create(['user_id' => $user2->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE, 'starts_at' => now()]);

        SubscriptionPayment::create([
            'subscription_id' => $sub1->id,
            'user_id' => $user1->id,
            'amount' => 180.00,
            'final_amount' => 180.00,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'promo_code' => 'LEGACY5',
        ]);
        SubscriptionPayment::create([
            'subscription_id' => $sub2->id,
            'user_id' => $user2->id,
            'amount' => 180.00,
            'final_amount' => 180.00,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'promo_code' => 'LEGACY5',
        ]);

        // Prior to any new redemptions: used = 2, remaining = 3
        $this->assertEquals(2, $promo->fresh()->used_count);
        $this->assertEquals(3, $promo->fresh()->remaining_uses);

        // User 3 reserves and consumes 1 new redemption
        $reserve = $this->promoService->reservePromo('LEGACY5', $user3->id, $plan->id);
        $this->assertTrue($reserve['success']);

        // Active count must be 2 legacy + 1 reserved = 3
        $this->assertEquals(3, $this->promoService->getActiveUsageCount($promo));
        $this->assertEquals(2, $promo->fresh()->remaining_uses);

        // Consume user 3's redemption
        $reserve['redemption']->markAsConsumed();

        // Total used count must now be 3 (2 legacy + 1 new redemption), NOT 1!
        $this->assertEquals(3, $promo->fresh()->used_count);
        $this->assertEquals(2, $promo->fresh()->remaining_uses);
    }

    /**
     * Adversarial Test: Historical backfill populates PromoRedemption and maintains idempotent counts.
     */
    public function test_backfill_historical_redemptions_creates_canonical_records_without_double_counting(): void
    {
        $promo = $this->createPromo([
            'promo_code' => 'BACKFILLTEST',
            'discount' => 20,
            'discount_type' => 'amount',
            'no_of_users' => 10,
            'status' => 1,
            'repeat_usage' => true,
        ]);

        $user = User::factory()->create();
        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->createPlan()->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 80.00,
            'final_amount' => 80.00,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'promo_code' => 'BACKFILLTEST',
        ]);

        // Before backfill
        $this->assertEquals(1, $promo->fresh()->used_count);
        $this->assertEquals(0, PromoRedemption::where('promo_code', 'BACKFILLTEST')->count());

        // Run backfill
        $res = $this->promoService->backfillHistoricalRedemptions();
        $this->assertGreaterThanOrEqual(1, $res['subscription_payments_backfilled']);

        // After backfill: 1 PromoRedemption created, used_count remains exactly 1 (no double counting)
        $this->assertEquals(1, PromoRedemption::where('promo_code', 'BACKFILLTEST')->count());
        $this->assertEquals(1, $promo->fresh()->used_count);
        $this->assertEquals(9, $promo->fresh()->remaining_uses);

        // Running backfill again is idempotent
        $res2 = $this->promoService->backfillHistoricalRedemptions();
        $this->assertEquals(0, $res2['subscription_payments_backfilled']);
        $this->assertEquals(1, $promo->fresh()->used_count);
    }

    /**
     * Adversarial Test: Server authority ignores manipulated client payment amounts.
     */
    public function test_server_authority_ignores_client_manipulated_amounts(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500.00]);
        $plan = $this->createPlan([
            'name' => 'Tamper Plan',
            'slug' => 'tamper-plan',
            'price' => 200.00,
            'usd_price' => 20.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = $this->createPromo([
            'promo_code' => 'TAMPER10',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 10,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'repeat_usage' => true,
        ]);

        // Client attempts to send fake amount = 1.00 and discount = 199.00
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'tamper-key-1')
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'wallet',
                'use_wallet' => true,
                'promo_code' => 'TAMPER10',
                'amount' => 1.00,
                'discount_amount' => 199.00,
                'final_amount' => 1.00,
                'price' => 1.00,
            ]);

        $response->assertStatus(200);

        // Server authoritative calculation: 200 - 10% = 180
        $payment = SubscriptionPayment::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(200.00, (float) $payment->original_amount);
        $this->assertEquals(20.00, (float) $payment->discount_amount);
        $this->assertEquals(180.00, (float) $payment->final_amount);
        $this->assertEquals(180.00, (float) $payment->amount);
    }
}

