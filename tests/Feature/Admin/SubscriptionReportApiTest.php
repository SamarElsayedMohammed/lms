<?php

namespace Tests\Feature\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscription-plans-list', 'guard_name' => 'web']);
        $role->givePermissionTo('subscription-plans-list');

        $this->admin = User::factory()->create(['email' => 'admin@skillso.test']);
        $this->admin->assignRole($role);

        $this->user = User::factory()->create([
            'email' => 'student@skillso.test',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/admin/subscriptions/plan-report');
        $response->assertStatus(401);
    }

    public function test_admin_can_fetch_global_subscription_report(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Gold Subscription',
            'slug' => 'gold-subscription-global',
            'price' => 500.0,
            'billing_cycle' => 'yearly',
            'duration_days' => 365,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
        ]);

        SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->user->id,
            'amount' => 500.0,
            'final_amount' => 500.0,
            'currency_code' => 'EGP',
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'card',
            'resolved_country' => 'EG',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/subscriptions/plan-report?preset=30d');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'summary' => [
                    'total_revenue_egp',
                    'total_orders',
                    'total_subscribers',
                    'total_active_subscribers',
                    'total_expired_subscribers',
                    'comparisons',
                ],
                'revenue_series',
                'status_distribution',
                'plans',
            ],
        ]);

        $this->assertEquals(500.0, $response->json('data.summary.total_revenue_egp'));
        $this->assertEquals(1, $response->json('data.summary.total_orders'));
        $this->assertEquals(1, $response->json('data.summary.total_subscribers'));
    }

    public function test_canonical_admin_reports_path_is_available(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=30d');

        $response->assertOk()->assertJsonStructure([
            'data' => ['summary', 'revenue_series', 'status_distribution', 'plans', 'meta'],
        ]);
    }

    public function test_admin_can_fetch_plan_detail_report(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Gold Subscription',
            'slug' => 'gold-subscription-detail',
            'price' => 500.0,
            'billing_cycle' => 'yearly',
            'duration_days' => 365,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
        ]);

        SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->user->id,
            'amount' => 500.0,
            'final_amount' => 500.0,
            'currency_code' => 'EGP',
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'card',
            'resolved_country' => 'EG',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/subscriptions/plan-report/plans/{$plan->id}?preset=30d");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'plan_id',
                'plan_name',
                'summary' => [
                    'total_students',
                    'active_subscribers',
                    'expired_subscribers',
                    'total_revenue_egp',
                ],
                'country_breakdown',
                'country_totals',
                'country_distribution',
                'monthly_growth',
            ],
        ]);

        $this->assertEquals($plan->id, $response->json('data.plan_id'));
        $this->assertEquals(500.0, $response->json('data.summary.total_revenue_egp'));
    }

    public function test_canonical_admin_reports_plan_detail_path_is_available(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Canonical Detail Plan',
            'slug' => 'canonical-detail-plan',
            'price' => 250,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/reports/subscriptions/{$plan->id}?preset=30d");

        $response->assertOk()
            ->assertJsonPath('data.plan_id', $plan->id)
            ->assertJsonStructure(['data' => ['summary', 'country_breakdown', 'monthly_growth', 'meta']]);
    }

    public function test_admin_can_export_csv_report(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/admin/subscriptions/plan-report/export?preset=30d');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('إجمالي المشتركين', $response->getContent());
    }

    public function test_custom_period_requires_valid_ordered_dates(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/subscriptions/plan-report?preset=custom&date_from=2026-08-10&date_to=2026-08-01');

        $response->assertStatus(422)->assertJsonValidationErrors(['date_to']);
    }

    public function test_this_year_preset_is_supported(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=this_year');

        $response->assertOk()
            ->assertJsonPath('data.meta.applied_filters.preset', 'this_year');

        $this->assertStringStartsWith(
            now()->startOfYear()->format('Y-m-d'),
            (string) $response->json('data.meta.current_period.from')
        );
    }

    public function test_revenue_uses_paid_at_and_counts_unique_subscribers(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Accurate Plan',
            'slug' => 'accurate-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        foreach ([100, 50] as $index => $amount) {
            $payment = SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $this->user->id,
                'amount' => $amount,
                'final_amount' => $amount,
                'currency_code' => 'EGP',
                'amount_egp' => $amount,
                'exchange_rate_snapshot' => 1,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'card',
                'resolved_country' => 'EG',
                'paid_at' => now(),
            ]);
            $payment->forceFill(['created_at' => now()->subMonths($index + 2)])->save();
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/subscriptions/plan-report?preset=30d');

        $response->assertOk();
        $this->assertSame(150.0, (float) $response->json('data.summary.total_revenue_egp'));
        $this->assertSame(2, $response->json('data.summary.total_orders'));
        $this->assertSame(1, $response->json('data.summary.total_subscribers'));
        $this->assertSame(150.0, (float) collect($response->json('data.revenue_series'))->sum('revenue_egp'));
    }

    public function test_completed_legacy_payment_without_paid_at_uses_created_at(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Legacy Revenue Plan',
            'slug' => 'legacy-revenue-plan',
            'price' => 300,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $this->user->id,
            'amount' => 300,
            'final_amount' => 300,
            'amount_egp' => null,
            'currency_code' => 'EGP',
            'exchange_rate_snapshot' => null,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'manual',
            'resolved_country' => 'EG',
            'paid_at' => null,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=30d');

        $response->assertOk();
        $this->assertSame(300.0, (float) $response->json('data.summary.total_revenue_egp'));
        $this->assertSame(300.0, (float) collect($response->json('data.plans'))->sum('total_revenue_egp'));
        $this->assertSame(300.0, (float) collect($response->json('data.revenue_series'))->sum('revenue_egp'));
    }

    public function test_filters_are_applied_to_summary_and_plan_totals(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Filtered Plan',
            'slug' => 'filtered-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        foreach (['EG' => 100, 'SA' => 200] as $country => $amount) {
            $user = User::factory()->create();
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'final_amount' => $amount,
                'amount_egp' => $amount,
                'currency_code' => 'EGP',
                'exchange_rate_snapshot' => 1,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'card',
                'resolved_country' => $country,
                'paid_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/subscriptions/plan-report?preset=30d&country=eg');

        $response->assertOk();
        $this->assertSame(100.0, (float) $response->json('data.summary.total_revenue_egp'));
        $this->assertSame(1, $response->json('data.summary.total_subscribers'));
        $this->assertSame(100.0, (float) collect($response->json('data.plans'))->sum('total_revenue_egp'));
    }

    public function test_summary_status_cards_use_the_same_subscription_cohort(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Cohort Plan',
            'slug' => 'cohort-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        $oldUser = User::factory()->create();
        Subscription::create([
            'user_id' => $oldUser->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=30d');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.summary.total_subscribers'));
        $this->assertSame(1, $response->json('data.summary.total_active_subscribers'));
        $this->assertSame(0, $response->json('data.summary.total_expired_subscribers'));
    }

    public function test_zero_to_zero_comparison_has_zero_percentage(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=30d');

        $response->assertOk()
            ->assertJsonPath('data.summary.comparisons.expired.current', 0)
            ->assertJsonPath('data.summary.comparisons.expired.previous', 0)
            ->assertJsonPath('data.summary.comparisons.expired.percentage', 0)
            ->assertJsonPath('data.summary.comparisons.expired.direction', 'neutral')
            ->assertJsonPath('data.summary.comparisons.expired.is_new', false);
    }

    public function test_distinct_subscribers_and_subscriptions_count_are_differentiated(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Distinct Multi Plan',
            'slug' => 'distinct-multi-plan',
            'price' => 200,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // Same user has 2 subscriptions in the period
        for ($i = 0; $i < 2; $i++) {
            $sub = Subscription::create([
                'user_id' => $this->user->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(28),
            ]);

            SubscriptionPayment::create([
                'subscription_id' => $sub->id,
                'user_id' => $this->user->id,
                'amount' => 200,
                'final_amount' => 200,
                'currency_code' => 'EGP',
                'amount_egp' => 200,
                'exchange_rate_snapshot' => 1,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'card',
                'resolved_country' => 'EG',
                'paid_at' => now(),
            ]);
        }

        $overviewRes = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/subscriptions?preset=30d');

        $overviewRes->assertOk();
        $this->assertSame(1, $overviewRes->json('data.summary.total_subscribers'));
        $this->assertSame(2, $overviewRes->json('data.summary.subscriptions_count'));
        $this->assertSame(400.0, (float) $overviewRes->json('data.summary.total_revenue_egp'));

        $planRow = collect($overviewRes->json('data.plans'))->firstWhere('plan_id', $plan->id);
        $this->assertNotNull($planRow);
        $this->assertSame(1, $planRow['total_subscribers']);
        $this->assertSame(2, $planRow['subscriptions_count']);
        $this->assertSame(400.0, (float) $planRow['total_revenue_egp']);

        // Check detail report matches overview numbers
        $detailRes = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/reports/subscriptions/{$plan->id}?preset=30d");

        $detailRes->assertOk();
        $this->assertSame(1, $detailRes->json('data.summary.total_students'));
        $this->assertSame(1, $detailRes->json('data.summary.total_subscribers'));
        $this->assertSame(2, $detailRes->json('data.summary.subscriptions_count'));
        $this->assertSame(400.0, (float) $detailRes->json('data.summary.total_revenue_egp'));
        $this->assertSame(1, $detailRes->json('data.summary.active_subscribers'));
    }
}
