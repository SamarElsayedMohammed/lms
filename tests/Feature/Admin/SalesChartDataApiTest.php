<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesChartDataApiTest extends TestCase
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/sales-chart-data')->assertUnauthorized();
    }

    public function test_chart_uses_real_completed_orders(): void
    {
        $customer = User::factory()->create();
        Order::create([
            'user_id' => $customer->id,
            'order_number' => 'REAL-COMPLETED-ORDER',
            'total_price' => 500,
            'final_price' => 500,
            'amount_egp' => 500,
            'exchange_rate_snapshot' => 1,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        Order::create([
            'user_id' => $customer->id,
            'order_number' => 'PENDING-ORDER',
            'total_price' => 900,
            'final_price' => 900,
            'amount_egp' => 900,
            'exchange_rate_snapshot' => 1,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sales-chart-data?preset=today&group_by=day');

        $response->assertOk();
        $today = collect($response->json('data'))->firstWhere('date', now()->format('Y-m-d'));

        $this->assertNotNull($today);
        $this->assertSame(1, $today['sales']);
        $this->assertSame(500.0, (float) $today['revenue']);
        $this->assertSame(0.0, (float) $today['profit']);
    }

    public function test_payment_method_filter_is_applied(): void
    {
        $customer = User::factory()->create();
        foreach (['card' => 100, 'wallet' => 250] as $method => $amount) {
            Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ORDER-' . strtoupper($method),
                'total_price' => $amount,
                'final_price' => $amount,
                'amount_egp' => $amount,
                'exchange_rate_snapshot' => 1,
                'payment_method' => $method,
                'status' => 'completed',
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sales-chart-data?preset=today&group_by=day&payment_method=wallet');

        $today = collect($response->json('data'))->firstWhere('date', now()->format('Y-m-d'));
        $this->assertSame(1, $today['sales']);
        $this->assertSame(250.0, (float) $today['revenue']);
    }
}
