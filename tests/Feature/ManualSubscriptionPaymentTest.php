<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use App\Notifications\SubscriptionActivatedNotification;
use App\Notifications\ManualSubscriptionStatusNotification;
use Tests\TestCase;

final class ManualSubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
        Storage::fake('private');

        // Seed roles & permissions for testing
        foreach (['web', 'sanctum', 'api'] as $guard) {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => $guard]);
            foreach (['finance-list', 'finance-edit', 'subscription-plans-list'] as $permName) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permName, 'guard_name' => $guard]);
                $role->givePermissionTo($perm);
            }
        }

        \App\Models\Setting::updateOrCreate(['name' => 'manual_payments_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    public function test_user_can_submit_manual_subscription_payment(): void
    {
        Notification::fake();

        // 1. Create entities
        $user = User::factory()->create(['wallet_balance' => 0.00]);
        $plan = SubscriptionPlan::create([
            'name' => 'Premium Test Plan',
            'slug' => 'premium-test-plan',
            'price' => 150.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        
        $method = PaymentMethod::create([
            'name' => 'Instapay Transfer',
            'type' => 'instapay',
            'account_name' => 'Test recipient',
            'instapay_id' => 'test@instapay',
            'instructions' => 'Send money and upload receipt',
            'dynamic_fields' => [['key' => 'transfer_reference', 'label' => 'Transfer reference', 'type' => 'text', 'required' => true]],
            'is_active' => true,
        ]);

        // 2. Submit subscription request
        $receipt = UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg');
        $response = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'manual-submit-unique-' . uniqid())->post('/api/subscription/subscribe', [
            'plan_id' => $plan->id,
            'payment_method' => 'manual',
            'payment_method_id' => (string) $method->id,
            'payment_fields' => ['transfer_reference' => 'TXN-123456'],
            'receipt' => $receipt,
            'transaction_id' => 'TXN-123456',
        ]);

        // 3. Assert successful creation
        $response->assertStatus(200);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.subscription.status', Subscription::STATUS_PENDING_APPROVAL);
        $response->assertJsonPath('data.payment.payment_method', 'manual');
        $response->assertJsonPath('data.payment.total_amount', 150);
    }

    public function test_admin_can_approve_manual_subscription(): void
    {
        Notification::fake();

        // 1. Create entities
        $user = User::factory()->create(['wallet_balance' => 50.00]); // Partial wallet payment support
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Premium Test Plan',
            'slug' => 'premium-test-plan-2',
            'price' => 150.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        
        $method = PaymentMethod::create([
            'name' => 'Instapay Transfer',
            'type' => 'instapay',
            'account_name' => 'Test recipient',
            'instapay_id' => 'test@instapay',
            'instructions' => 'Send money and upload receipt',
            'is_active' => true,
        ]);

        // Create subscription & payment pending approval
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now(),
            'status' => Subscription::STATUS_PENDING_APPROVAL,
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 150.00,
            'wallet_amount' => 50.00,
            'gateway_amount' => 100.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'manual',
            'payment_method_id' => $method->id,
            'receipt' => 'subscriptions/receipts/fake.jpg',
        ]);

        // 2. Approve via admin API
        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/manual-subscriptions/{$subscription->id}/approve", [
            'admin_notes' => 'Approved manually by admin unit test.',
        ]);

        $response->assertStatus(200);

        // 3. Assert states after approval
        $subscription->refresh();
        $payment->refresh();
        $user->refresh();

        // Wallet balance deducted
        $this->assertEquals(0.00, (float) $user->wallet_balance);

        // Subscription activated
        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->ends_at);
        $this->assertTrue($subscription->ends_at->isAfter(now()));

        // Payment marked completed
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->paid_at);

        // Notification dispatched
        Notification::assertSentTo($user, SubscriptionActivatedNotification::class);
    }

    public function test_admin_can_reject_manual_subscription(): void
    {
        Notification::fake();

        // 1. Create entities
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Premium Test Plan',
            'slug' => 'premium-test-plan-3',
            'price' => 150.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now(),
            'status' => Subscription::STATUS_PENDING_APPROVAL,
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 150.00,
            'wallet_amount' => 0.00,
            'gateway_amount' => 150.00,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'manual',
            'receipt' => 'subscriptions/receipts/fake.jpg',
        ]);

        // 2. Reject via admin API
        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/manual-subscriptions/{$subscription->id}/reject", [
            'admin_notes' => 'Rejected due to invalid receipt.',
        ]);

        $response->assertStatus(200);

        // 3. Assert states after rejection
        $subscription->refresh();
        $payment->refresh();

        // Subscription cancelled
        $this->assertEquals(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertEquals('Rejected due to invalid receipt.', $subscription->cancellation_reason);

        // Payment failed
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->status);

        // Notification dispatched
        Notification::assertNotSentTo($user, SubscriptionActivatedNotification::class);
        Notification::assertSentTo($user, ManualSubscriptionStatusNotification::class);
    }

    public function test_user_can_submit_manual_subscription_with_manual_deposit_prefixed_id(): void
    {
        Notification::fake();

        $user = User::factory()->create(['wallet_balance' => 0.00]);
        $plan = SubscriptionPlan::create([
            'name' => 'Deposit Test Plan',
            'slug' => 'deposit-test-plan',
            'price' => 200.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $depositMethod = \App\Models\ManualDepositMethod::create([
            'name' => 'Vodafone Cash Direct',
            'account_details' => '01012345678',
            'instructions' => 'Transfer to 01012345678',
            'is_active' => true,
        ]);

        $receipt = UploadedFile::fake()->create('receipt.png', 150, 'image/png');
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'manual-deposit-prefix-' . uniqid())
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'manual',
                'payment_method_id' => "manual-deposit-{$depositMethod->id}",
                'receipt' => $receipt,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.subscription.status', Subscription::STATUS_PENDING_APPROVAL);
    }

    public function test_duplicate_pending_subscription_returns_409_conflict(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Duplicate Test Plan',
            'slug' => 'duplicate-test-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // Create an already pending subscription
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now(),
            'status' => Subscription::STATUS_PENDING_APPROVAL,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Instapay Test',
            'type' => 'instapay',
            'account_name' => 'Recipient',
            'instapay_id' => 'rec@instapay',
            'is_active' => true,
        ]);

        $receipt = UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg');
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'conflict-test-' . uniqid())
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'manual',
                'payment_method_id' => (string) $method->id,
                'receipt' => $receipt,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('errors.reason', 'DUPLICATE_SUBSCRIPTION_REQUEST');
    }

    public function test_unavailable_manual_method_returns_422(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Validation Test Plan',
            'slug' => 'validation-test-plan',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $receipt = UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg');
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'unavailable-test-' . uniqid())
            ->post('/api/subscription/subscribe', [
                'plan_id' => $plan->id,
                'payment_method' => 'manual',
                'payment_method_id' => 'non-existent-id-999',
                'receipt' => $receipt,
            ]);

        $response->assertStatus(422);
    }
}
