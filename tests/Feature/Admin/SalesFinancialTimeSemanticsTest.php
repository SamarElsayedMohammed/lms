<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesFinancialTimeSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00', 'UTC'));

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin-finance-time@skillso.test']);
        $this->admin->assignRole($role);
        $this->student = User::factory()->create();

        $category = Category::create([
            'name' => 'Finance Time',
            'slug' => 'finance-time',
            'is_active' => true,
        ]);
        $this->course = Course::factory()->create([
            'title' => 'Timed Course',
            'user_id' => $this->admin->id,
            'category_id' => $category->id,
            'price' => 200,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'course_type' => 'paid',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_order_created_paid_and_refunded_land_in_different_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 18:00:00', 'UTC'));
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-JAN',
            'total_price' => 200,
            'final_price' => 200,
            'amount_egp' => 200,
            'exchange_rate_snapshot' => 1,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $this->course->id,
            'price' => 200,
            'tax_price' => 0,
            'amount_egp' => 200,
            'exchange_rate_snapshot' => 1,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Feb Pay',
            'slug' => 'feb-pay',
            'price' => 300,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-01-31 10:00:00',
            'ends_at' => '2026-03-02 00:00:00',
        ]);
        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $this->student->id,
            'amount' => 300,
            'final_amount' => 300,
            'amount_egp' => 300,
            'currency_code' => 'EGP',
            'exchange_rate_snapshot' => 1,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'card',
            'created_at' => '2026-01-31 11:00:00',
            'paid_at' => '2026-02-01 09:00:00',
        ]);

        $transaction = Transaction::create([
            'user_id' => $this->student->id,
            'order_id' => $order->id,
            'amount' => 200,
            'amount_egp' => 200,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-03-05 08:00:00', 'UTC'));
        RefundRequest::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'transaction_id' => $transaction->id,
            'refund_amount' => 50,
            'amount_egp' => 50,
            'exchange_rate_snapshot' => 1,
            'status' => 'approved',
            'processed_at' => '2026-03-05 08:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00', 'UTC'));

        $january = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?preset=custom&date_from=2026-01-01&date_to=2026-01-31'
        );
        $february = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?preset=custom&date_from=2026-02-01&date_to=2026-02-28'
        );
        $march = $this->actingAs($this->admin, 'sanctum')->getJson(
            '/api/admin/reports/sales?preset=custom&date_from=2026-03-01&date_to=2026-03-31'
        );

        $january->assertOk();
        $february->assertOk();
        $march->assertOk();

        $this->assertEquals(200.0, (float) $january->json('data.gross_revenue'));
        $this->assertEquals(0.0, (float) $january->json('data.subscription_revenue'));
        $this->assertEquals(0.0, (float) $january->json('data.total_refunds'));
        $this->assertEquals(200.0, (float) $january->json('data.net_revenue'));

        $this->assertEquals(300.0, (float) $february->json('data.gross_revenue'));
        $this->assertEquals(300.0, (float) $february->json('data.subscription_revenue'));
        $this->assertEquals(0.0, (float) $february->json('data.total_refunds'));
        $this->assertEquals(300.0, (float) $february->json('data.net_revenue'));

        $this->assertEquals(0.0, (float) $march->json('data.gross_revenue'));
        $this->assertEquals(50.0, (float) $march->json('data.total_refunds'));
        $this->assertEquals(0.0, (float) $march->json('data.net_revenue'));
        $this->assertSame('refund_recognition_date', $march->json('data.financial_time_model.refunds'));
    }
}
