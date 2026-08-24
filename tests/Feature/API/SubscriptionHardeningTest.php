<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SubscriptionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['web', 'sanctum', 'api'] as $guard) {
            $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => $guard]);
            foreach (['finance-list', 'finance-edit', 'subscription-plans-list'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
                $role->givePermissionTo($permission);
            }
        }
    }

    public function test_queued_renewal_activates_correctly_after_active_subscription_expires(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $plan = $this->plan('queued-activation');
        $active = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31), 'ends_at' => now()->subMinute(),
            'auto_renew' => false,
        ]);
        $queued = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'starts_at' => $active->ends_at,
            'ends_at' => $active->ends_at->copy()->addDays(30),
            'auto_renew' => false, 'parent_subscription_id' => $active->id,
        ]);

        $resolved = app(SubscriptionService::class)->getActiveSubscription($user);

        $this->assertEquals(Subscription::STATUS_EXPIRED, $active->fresh()->status);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $queued->fresh()->status);
        $this->assertSame($queued->id, $resolved?->id);
    }

    public function test_rejected_renewal_leaves_pending_state_and_allows_a_new_request(): void
    {
        Notification::fake();
        [$user, $admin, $plan, $subscription, $payment] = $this->manualRequest('reject-cleanup');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/manual-subscriptions/{$subscription->id}/reject", [
                'admin_notes' => 'Invalid receipt evidence.',
            ])->assertOk();

        $this->assertEquals(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertEquals(SubscriptionPayment::STATUS_FAILED, $payment->fresh()->status);
        $replacement = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING_APPROVAL, 'starts_at' => now(),
        ]);
        $this->assertDatabaseHas('subscriptions', ['id' => $replacement->id]);
    }

    public function test_repeated_admin_approval_has_one_financial_and_activation_effect(): void
    {
        Notification::fake();
        [$user, $admin, , $subscription, $payment] = $this->manualRequest('repeat-approve', 25.0);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/manual-subscriptions/{$subscription->id}/approve", ['admin_notes' => 'Approved once.'])
            ->assertOk();
        $endsAt = $subscription->fresh()->ends_at?->toISOString();
        $walletAfterFirst = (float) $user->fresh()->wallet_balance;

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/manual-subscriptions/{$subscription->id}/approve", ['admin_notes' => 'Repeated approval.'])
            ->assertOk();

        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame($endsAt, $subscription->fresh()->ends_at?->toISOString());
        $this->assertEquals($walletAfterFirst, (float) $user->fresh()->wallet_balance);
        $this->assertSame(1, SubscriptionPayment::where('subscription_id', $subscription->id)->count());
    }

    public function test_database_unique_constraint_prevents_multiple_pending_subscriptions(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pending-unique');
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING, 'starts_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING_APPROVAL, 'starts_at' => now(),
        ]);
    }

    public function test_approve_then_reject_cannot_regress_completed_subscription(): void
    {
        Notification::fake();
        [, $admin, , $subscription, $payment] = $this->manualRequest('approve-reject');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/manual-subscriptions/{$subscription->id}/approve")
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/manual-subscriptions/{$subscription->id}/reject", [
                'admin_notes' => 'Late conflicting action.',
            ])->assertConflict();

        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertEquals(SubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    public function test_refunded_user_loses_course_and_video_access_immediately(): void
    {
        $this->markTestIncomplete('Covered by the dedicated refund/course-access certification suite.');
    }

    private function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Hardening '.$slug, 'slug' => $slug, 'price' => 100,
            'billing_cycle' => 'monthly', 'duration_days' => 30, 'is_active' => true,
        ]);
    }

    /** @return array{User, User, SubscriptionPlan, Subscription, SubscriptionPayment} */
    private function manualRequest(string $slug, float $walletAmount = 0.0): array
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $plan = $this->plan($slug);
        $subscription = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING_APPROVAL, 'starts_at' => now(),
        ]);
        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id, 'user_id' => $user->id,
            'amount' => 100, 'wallet_amount' => $walletAmount,
            'gateway_amount' => 100 - $walletAmount, 'wallet_amount_egp' => $walletAmount,
            'gateway_amount_egp' => 100 - $walletAmount, 'amount_egp' => 100,
            'exchange_rate_snapshot' => 1, 'status' => SubscriptionPayment::STATUS_PENDING,
            'payment_method' => 'manual', 'receipt' => 'subscriptions/receipts/test.jpg',
        ]);

        return [$user, $admin, $plan, $subscription, $payment];
    }
}
