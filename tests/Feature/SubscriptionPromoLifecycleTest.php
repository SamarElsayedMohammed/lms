<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionPromoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SubscriptionPromoLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected SubscriptionPromoService $promoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promoService = app(SubscriptionPromoService::class);
        Storage::fake('private');
        Notification::fake();
    }

    /**
     * PT-01 / LI-01: Promo validation does not consume quota.
     */
    public function test_promo_validation_does_not_consume_quota(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Gold Plan',
            'slug' => 'gold-plan',
            'price' => 200.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
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
    }

    /**
     * PT-02 / LI-15: Successful wallet payment consumes quota exactly once.
     */
    public function test_successful_wallet_payment_consumes_quota_exactly_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500.00]);
        $plan = SubscriptionPlan::create([
            'name' => 'Gold Plan 2',
            'slug' => 'gold-plan-2',
            'price' => 200.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
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
        $this->assertEquals(9, $promo->fresh()->no_of_users);

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
        $plan = SubscriptionPlan::create([
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

        $promo = PromoCode::create([
            'promo_code' => 'MANUAL20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 5,
            'status' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
        ]);

        $receipt = UploadedFile::fake()->image('receipt.jpg');
        $response = $this->actingAs($user, 'sanctum')->post('/api/subscription/subscribe', [
            'plan_id' => $plan->id,
            'payment_method' => 'manual',
            'payment_method_id' => $method->id,
            'promo_code' => 'MANUAL20',
            'receipt' => $receipt,
            'transaction_id' => 'WIRE-001',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(4, $promo->fresh()->no_of_users);
    }

    /**
     * PT-04 / LI-15, LI-19: Manual subscription approval consumes reservation exactly once.
     */
    public function test_manual_subscription_approval_consumes_reservation_exactly_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0.00]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Silver Plan 2',
            'slug' => 'silver-plan-2',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
            'promo_code' => 'APPROVE20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 4, // Already decremented by reservation
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

        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/approve", [
            'admin_notes' => 'Approved',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(4, $promo->fresh()->no_of_users); // Consumed: remains at 4
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->fresh()->status);
    }

    /**
     * PT-05, PT-06 / LI-12, LI-16, LI-20: Manual subscription rejection releases reservation exactly once without double increment.
     */
    public function test_manual_subscription_rejection_releases_reservation_exactly_once(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Silver Plan 3',
            'slug' => 'silver-plan-3',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
            'promo_code' => 'REJECT20',
            'message' => '20% off',
            'discount' => 20,
            'discount_type' => 'percentage',
            'no_of_users' => 4, // 1 held in reservation
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

        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/reject", [
            'admin_notes' => 'Invalid transaction slip',
        ]);

        $response->assertStatus(200);
        // Quota incremented from 4 to 5 exactly once (double increment bug fixed)
        $this->assertEquals(5, $promo->fresh()->no_of_users);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_CANCELLED, $sub->fresh()->status);

        // PT-06: Second rejection attempt is rejected or idempotent and does not increment again
        $response2 = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$sub->id}/reject", [
            'admin_notes' => 'Second reject call',
        ]);
        $this->assertEquals(5, $promo->fresh()->no_of_users);
    }

    /**
     * PT-08, PT-09 / LI-11, LI-13: Kashier checkout reserves quota; failure releases quota.
     */
    public function test_kashier_failure_releases_quota(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Kashier Plan',
            'slug' => 'kashier-plan',
            'price' => 150.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
            'promo_code' => 'KASHFAIL',
            'message' => '10% off',
            'discount' => 10,
            'discount_type' => 'percentage',
            'no_of_users' => 9, // 1 reserved
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

        $response = $this->post('/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'FAILED',
            'signature' => 'mock_signature',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $promo->fresh()->no_of_users);
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
        $plan = SubscriptionPlan::create([
            'name' => 'Kashier Plan 2',
            'slug' => 'kashier-plan-2',
            'price' => 300.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
            'promo_code' => 'KASHSUCCESS',
            'message' => '50 EGP off',
            'discount' => 50,
            'discount_type' => 'amount',
            'no_of_users' => 4, // 1 held in reservation
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

        // Explicitly clear cache to prove durable payment intent (PT-13)
        Cache::forget('kashier_pending_' . $orderId);

        // First webhook delivery
        $response1 = $this->post('/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'SUCCESS',
            'amount' => 250.00,
            'transactionId' => 'KASH-TXN-12345',
        ]);

        $response1->assertStatus(200);
        $this->assertEquals(4, $promo->fresh()->no_of_users); // Consumed: remains at 4
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $sub->fresh()->status);
        $this->assertEquals(50.00, (float) $payment->fresh()->discount_amount);
        $this->assertEquals('KASHSUCCESS', $payment->fresh()->promo_code);

        // PT-12: Duplicate webhook delivery is idempotent
        $response2 = $this->post('/webhooks/kashier', [
            'merchantOrderId' => $orderId,
            'paymentStatus' => 'SUCCESS',
            'amount' => 250.00,
            'transactionId' => 'KASH-TXN-12345',
        ]);
        $response2->assertStatus(200);
        $this->assertEquals(4, $promo->fresh()->no_of_users);
    }

    /**
     * PT-10 / LI-14: Abandoned / expired Kashier reservation is reclaimed.
     */
    public function test_abandoned_kashier_reservation_is_reclaimed(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Expire Plan',
            'slug' => 'expire-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
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
            'created_at' => now()->subHours(5), // older than 4 hour TTL
        ]);

        $reclaimed = $this->promoService->reclaimExpiredReservations(4);
        $this->assertGreaterThanOrEqual(1, $reclaimed);
        $this->assertEquals(2, $promo->fresh()->no_of_users);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
    }

    /**
     * PT-17, PT-18 / LI-09, LI-10: Non-repeatable promo cannot be reused by same user.
     */
    public function test_non_repeatable_promo_enforces_per_user_limit(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Single Use Plan',
            'slug' => 'single-use-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
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
        SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'amount' => 90.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 90.00,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'promo_code' => 'ONCEONLY',
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
        $plan = SubscriptionPlan::create([
            'name' => 'Date Test Plan',
            'slug' => 'date-test-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $promo = PromoCode::create([
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
        $planA = SubscriptionPlan::create(['name' => 'Plan A', 'slug' => 'plan-a', 'price' => 100, 'billing_cycle' => 'monthly', 'duration_days' => 30, 'is_active' => true]);
        $planB = SubscriptionPlan::create(['name' => 'Plan B', 'slug' => 'plan-b', 'price' => 200, 'billing_cycle' => 'monthly', 'duration_days' => 30, 'is_active' => true]);

        $promo = PromoCode::create([
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
}
