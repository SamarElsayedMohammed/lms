<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesUnifiedPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin-sales-page@skillso.test']);
        $this->admin->assignRole($role);
        $this->student = User::factory()->create();
        $category = Category::create(['name' => 'Sales Page', 'slug' => 'sales-page', 'is_active' => true]);
        $this->course = Course::factory()->create([
            'title' => 'Paged Course',
            'user_id' => $this->admin->id,
            'category_id' => $category->id,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_detailed_sales_paginates_the_unified_population_on_the_server(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Paged Sub',
            'slug' => 'paged-sub',
            'price' => 40,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        foreach (range(1, 3) as $i) {
            $order = Order::create([
                'user_id' => $this->student->id,
                'order_number' => 'ORD-PAGE-' . $i,
                'total_price' => 10 * $i,
                'final_price' => 10 * $i,
                'amount_egp' => 10 * $i,
                'exchange_rate_snapshot' => 1,
                'payment_method' => 'card',
                'status' => 'completed',
                'created_at' => now()->subHours($i),
            ]);
            OrderCourse::create([
                'order_id' => $order->id,
                'course_id' => $this->course->id,
                'price' => 10 * $i,
                'tax_price' => 0,
            ]);
            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $this->student->id,
                'amount' => 40,
                'final_amount' => 40,
                'amount_egp' => 40,
                'currency_code' => 'EGP',
                'exchange_rate_snapshot' => 1,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'card',
                'paid_at' => now()->subHours($i + 3),
            ]);
        }

        $page1 = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?report_type=detailed&per_page=2&page=1&preset=30d'
        );
        $subsOnly = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?report_type=detailed&per_page=15&product_type=subscription&preset=30d'
        );

        $page1->assertOk();
        $subsOnly->assertOk();
        $this->assertSame(2, count($page1->json('data.data')));
        $this->assertSame(6, (int) $page1->json('meta.total'));
        $this->assertSame('server_side', $page1->json('data.pagination_mode'));
        $this->assertSame(3, (int) $subsOnly->json('meta.total'));
        $this->assertTrue(collect($subsOnly->json('data.data'))->every(
            fn ($row) => ($row['product_type'] ?? '') === 'subscription'
        ));
    }

    public function test_sales_export_returns_all_filtered_rows_not_the_current_page(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Export Sub',
            'slug' => 'export-sub',
            'price' => 40,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        foreach (range(1, 3) as $i) {
            $order = Order::create([
                'user_id' => $this->student->id,
                'order_number' => 'ORD-EXPORT-' . $i,
                'total_price' => 10 * $i,
                'final_price' => 10 * $i,
                'amount_egp' => 10 * $i,
                'exchange_rate_snapshot' => 1,
                'payment_method' => 'card',
                'status' => 'completed',
            ]);
            OrderCourse::create([
                'order_id' => $order->id,
                'course_id' => $this->course->id,
                'price' => 10 * $i,
                'tax_price' => 0,
            ]);
            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $this->student->id,
                'amount' => 40,
                'final_amount' => 40,
                'amount_egp' => 40,
                'currency_code' => 'EGP',
                'exchange_rate_snapshot' => 1,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'card',
                'paid_at' => now()->subHours($i + 3),
            ]);
        }

        $export = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?report_type=export&per_page=2&page=1&preset=30d'
        );

        $export->assertOk();
        $rows = $export->json('data.rows') ?? $export->json('data.data');
        $this->assertCount(6, $rows);
        $this->assertSame('all_filtered_rows', $export->json('data.export_scope'));
        $this->assertFalse((bool) $export->json('data.export_truncated'));
        $this->assertSame(6, (int) $export->json('data.exported'));
    }
}
