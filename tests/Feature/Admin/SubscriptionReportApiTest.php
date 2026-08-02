<?php

namespace Tests\Feature\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@skillso.test',
        ]);

        $this->user = User::factory()->create([
            'role' => 'user',
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

    public function test_admin_can_fetch_plan_detail_report(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Gold Subscription',
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

    public function test_admin_can_export_csv_report(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/admin/subscriptions/plan-report/export?preset=30d');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('إجمالي المشتركين', $response->getContent());
    }
}
