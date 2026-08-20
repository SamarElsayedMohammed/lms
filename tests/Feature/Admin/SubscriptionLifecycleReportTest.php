<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SubscriptionLifecycleReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscription-plans-list', 'guard_name' => 'web']);
        $role->givePermissionTo('subscription-plans-list');
        $this->admin = User::factory()->create(['email' => 'admin-lifecycle@skillso.test']);
        $this->admin->assignRole($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cohort_snapshot_and_event_metrics_are_not_interchangeable(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Lifecycle Plan',
            'slug' => 'lifecycle-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $startedBefore = User::factory()->create();
        $startedDuring = User::factory()->create();
        $expiredDuring = User::factory()->create();
        $cancelledDuring = User::factory()->create();
        $renewed = User::factory()->create();

        // Started before June, still covering June and July.
        Subscription::create([
            'user_id' => $startedBefore->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-05-01 00:00:00',
            'ends_at' => '2026-08-01 00:00:00',
        ]);

        // New in June, expired after period end but before "now".
        Subscription::create([
            'user_id' => $startedDuring->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => '2026-06-10 00:00:00',
            'ends_at' => '2026-07-10 00:00:00',
        ]);

        // Started before June, expired during June.
        Subscription::create([
            'user_id' => $expiredDuring->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => '2026-05-01 00:00:00',
            'ends_at' => '2026-06-20 00:00:00',
        ]);

        // Started before June, cancelled during June.
        Subscription::create([
            'user_id' => $cancelledDuring->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_CANCELLED,
            'starts_at' => '2026-05-01 00:00:00',
            'ends_at' => '2026-08-01 00:00:00',
            'cancelled_at' => '2026-06-15 00:00:00',
        ]);

        // Immediate renewal: previous term ended 1 June, new term started 1 June.
        Subscription::create([
            'user_id' => $renewed->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => '2026-05-01 00:00:00',
            'ends_at' => '2026-06-01 00:00:00',
        ]);
        Subscription::create([
            'user_id' => $renewed->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-08-01 00:00:00',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/subscriptions?preset=custom&date_from=2026-06-01&date_to=2026-06-30'
        );

        $response->assertOk();
        $summary = $response->json('data.summary');

        $this->assertSame(2, $summary['new_unique_subscribers'], 'Only June start users: startedDuring + renewed');
        $this->assertSame(2, $summary['subscription_records_started']);
        $this->assertSame(2, $summary['total_subscribers']);
        $this->assertSame(5, $summary['active_during_period']);
        $this->assertSame(2, $summary['active_at_period_end'], 'startedBefore and renewed still covering 30 June');
        $this->assertSame(2, $summary['active_now'], 'startedBefore and renewed still covering 15 July');
        $this->assertSame(2, $summary['expired_events'], 'expiredDuring 20 June + renewed previous term 1 June');
        $this->assertSame(1, $summary['cancelled_events']);
        $this->assertNull($summary['churned_subscribers'], 'No reliable lost-subscriber metric without a gap rule');
        $this->assertNotEquals($summary['new_unique_subscribers'], $summary['active_during_period']);
        $this->assertNotEquals($summary['expired_events'], $summary['started_cohort_expired_unique']);
        $this->assertSame(
            'unique_users_active_at_period_end',
            $response->json('data.meta.metric_grains.total_active_subscribers')
        );
        $this->assertSame('UTC', $response->json('data.meta.timezone'));
        $this->assertNotEmpty($response->json('data.meta.generated_at'));
    }

    public function test_current_expired_status_of_a_june_start_is_not_an_expiry_event(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Status Trap',
            'slug' => 'status-trap',
            'price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => '2026-06-10 00:00:00',
            'ends_at' => '2026-07-20 00:00:00',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/subscriptions?preset=custom&date_from=2026-06-01&date_to=2026-06-30'
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.summary.new_unique_subscribers'));
        $this->assertSame(0, $response->json('data.summary.expired_events'));
        $this->assertSame(1, $response->json('data.summary.started_cohort_expired_unique'));
        $this->assertSame(1, $response->json('data.status_distribution.expired_count'));
    }

    public function test_subscription_settled_revenue_matches_sales_subscription_component(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Pay Plan',
            'slug' => 'pay-plan',
            'price' => 300,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-06-05 00:00:00',
            'ends_at' => '2026-07-05 00:00:00',
        ]);
        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => 300,
            'final_amount' => 300,
            'amount_egp' => 300,
            'currency_code' => 'EGP',
            'exchange_rate_snapshot' => 1,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'card',
            'resolved_country' => 'EG',
            'paid_at' => '2026-06-06 10:00:00',
        ]);

        $subReport = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/subscriptions?preset=custom&date_from=2026-06-01&date_to=2026-06-30'
        );
        $sales = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?preset=custom&date_from=2026-06-01&date_to=2026-06-30'
        );

        $subReport->assertOk();
        $sales->assertOk();
        $this->assertEquals(
            (float) $subReport->json('data.summary.total_revenue_egp'),
            (float) $sales->json('data.subscription_revenue')
        );
    }
}
