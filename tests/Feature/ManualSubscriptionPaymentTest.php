<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ManualDepositMethod;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use App\Notifications\ManualSubscriptionStatusNotification;
use Tests\TestCase;

final class ManualSubscriptionPaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
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
        
        $method = ManualDepositMethod::create([
            'name' => 'Instapay Transfer',
            'account_details' => 'Instapay Address: test@instapay',
            'instructions' => 'Send money and upload receipt',
            'countries' => ['EG'],
            'is_active' => true,
        ]);

        // 2. Submit subscription request
        $receipt = UploadedFile::fake()->image('receipt.jpg');
        $response = $this->actingAs($user)->postJson('/api/subscription/subscribe', [
            'plan_id' => $plan->id,
            'payment_method' => 'manual',
            'manual_deposit_method_id' => $method->id,
            'receipt' => $receipt,
            'transaction_id' => 'TXN-123456',
        ]);

        // 3. Assert successful creation
        $response->assertStatus(200);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('message', 'تم إنشاء طلب الدفع بنجاح وجاري مراجعة الطلب من قبل الإدارة.');

        // 4. Assert subscription is pending approval
        $subscription = Subscription::where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertEquals(Subscription::STATUS_PENDING_APPROVAL, $subscription->status);

        // 5. Assert pending payment record
        $payment = SubscriptionPayment::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(SubscriptionPayment::STATUS_PENDING, $payment->status);
        $this->assertEquals('manual', $payment->payment_method);
        $this->assertEquals($method->id, $payment->manual_deposit_method_id);
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
        
        $method = ManualDepositMethod::create([
            'name' => 'Instapay Transfer',
            'account_details' => 'Instapay Address: test@instapay',
            'instructions' => 'Send money and upload receipt',
            'countries' => ['EG'],
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
            'manual_deposit_method_id' => $method->id,
            'receipt' => 'subscriptions/receipts/fake.jpg',
        ]);

        // 2. Approve via admin API
        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$subscription->id}/approve", [
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
        Notification::assertSentTo($user, ManualSubscriptionStatusNotification::class);
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
        $response = $this->actingAs($admin)->postJson("/api/admin/manual-subscriptions/{$subscription->id}/reject", [
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
        Notification::assertSentTo($user, ManualSubscriptionStatusNotification::class);
    }
}
