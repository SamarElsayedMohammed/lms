<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DashboardDataAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
    }

    public function test_invalid_period_is_rejected_instead_of_silently_defaulting(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/dashboard-data?period=not-a-period')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_custom_period_requires_valid_ordered_dates(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/dashboard-data?period=custom&from=2026-08-04&to=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_zero_egp_snapshot_falls_back_to_the_real_completed_order_amount(): void
    {
        $customer = User::factory()->create();
        Order::create([
            'user_id' => $customer->id,
            'order_number' => 'DASHBOARD-REAL-AMOUNT',
            'total_price' => 500,
            'final_price' => 500,
            'amount_egp' => 0,
            'exchange_rate_snapshot' => 1,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/dashboard-data?period=30_days')
            ->assertOk();

        $this->assertSame(500.0, (float) $response->json('data.financial_stats.monthly_revenue.current'));
        $this->assertSame(500.0, (float) $response->json('data.financial_stats.average_order_value'));
    }
}
