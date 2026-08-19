<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\WalletHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FinanceApiControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructor;
    private User $student;
    private Course $course;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $roleAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin@skillso.test']);
        $this->admin->assignRole($roleAdmin);

        $this->instructor = User::factory()->create([
            'name' => 'Dr. Ahmed Finance',
            'email' => 'finance.instructor@skillso.test',
        ]);
        Instructor::create([
            'user_id' => $this->instructor->id,
            'status' => 'approved',
            'type' => 'individual',
        ]);

        $this->student = User::factory()->create();

        $category = Category::create([
            'name' => 'Financial Engineering',
            'slug' => 'financial-engineering',
            'is_active' => true,
        ]);

        $this->course = Course::factory()->create([
            'title' => 'Advanced Financial Algorithms',
            'slug' => 'advanced-financial-algorithms',
            'user_id' => $this->instructor->id,
            'category_id' => $category->id,
            'price' => 2000.0,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'course_type' => 'paid',
        ]);

        $this->order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-FIN-001',
            'total_price' => 2000.0,
            'final_price' => 2000.0,
            'amount_egp' => 2000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create([
            'order_id' => $this->order->id,
            'course_id' => $this->course->id,
            'price' => 2000.0,
            'tax_price' => 0.0,
        ]);
    }

    // ─── 1. Authentication ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/finance/dashboard')->assertUnauthorized();
        $this->getJson('/api/finance/commissions')->assertUnauthorized();
        $this->getJson('/api/finance/instructor-earnings')->assertUnauthorized();
        $this->postJson('/api/finance/process-commission', [])->assertUnauthorized();
    }

    public function test_non_finance_admin_is_rejected(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/finance/dashboard')
            ->assertForbidden();
    }

    // ─── 2. Finance Dashboard ──────────────────────────────────────────────────

    public function test_finance_dashboard_returns_commissions_and_monthly_aggregates(): void
    {
        Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 2000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 600.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 1400.0,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/finance/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'total_admin_commissions',
                        'total_instructor_commissions',
                        'total_paid_commissions',
                        'total_pending_commissions',
                    ],
                    'monthly_data',
                    'top_instructors',
                    'recent_transactions',
                ],
            ]);

        $this->assertEquals(600.0, (float) $response->json('data.summary.total_admin_commissions'));
        $this->assertEquals(1400.0, (float) $response->json('data.summary.total_instructor_commissions'));
        $this->assertEquals(600.0, (float) $response->json('data.summary.total_paid_commissions'));
    }

    // ─── 3. Commissions List & Filters ─────────────────────────────────────────

    public function test_finance_commissions_pagination_and_status_filtering(): void
    {
        $paidComm = Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 2000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 600.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 1400.0,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        $pendingComm = Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 1000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 300.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 700.0,
            'status' => 'pending',
        ]);

        // Filter status=paid
        $resPaid = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/finance/commissions?status=paid');

        $resPaid->assertOk();
        $this->assertEquals(1, (int) ($resPaid->json('meta.total') ?? $resPaid->json('data.total')));
        $this->assertEquals($paidComm->id, $resPaid->json('data.0.id') ?? $resPaid->json('data.data.0.id'));

        // Filter status=pending
        $resPending = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/finance/commissions?status=pending');

        $resPending->assertOk();
        $this->assertEquals(1, (int) ($resPending->json('meta.total') ?? $resPending->json('data.total')));
        $this->assertEquals($pendingComm->id, $resPending->json('data.0.id') ?? $resPending->json('data.data.0.id'));
    }

    // ─── 4. Instructor Earnings ────────────────────────────────────────────────

    public function test_instructor_earnings_summary(): void
    {
        Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 2000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 600.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 1400.0,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/finance/instructor-earnings?instructor_id={$this->instructor->id}");

        $response->assertOk();
        $instructorItem = collect($response->json('data'))->firstWhere('instructor_id', $this->instructor->id);
        $this->assertNotNull($instructorItem);
        $this->assertEquals(1400.0, (float) $instructorItem['total_earnings']);
        $this->assertEquals(1400.0, (float) $instructorItem['paid_earnings']);
    }

    // ─── 5. Process Commission ─────────────────────────────────────────────────

    public function test_admin_can_approve_pending_commission_and_credit_wallet(): void
    {
        $comm = Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 2000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 600.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 1400.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/finance/process-commission', [
                'commission_id' => $comm->id,
                'action' => 'approve',
            ]);

        $response->assertOk();
        $this->assertEquals('paid', $comm->fresh()->status);
        $this->assertNotNull($comm->fresh()->paid_at);

        // Verify instructor wallet was credited
        $this->assertEquals(1400.0, (float) $this->instructor->fresh()->wallet_balance);
    }

    public function test_admin_can_reject_pending_commission(): void
    {
        $comm = Commission::create([
            'order_id' => $this->order->id,
            'instructor_id' => $this->instructor->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 1000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 300.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 700.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/finance/process-commission', [
                'commission_id' => $comm->id,
                'action' => 'reject',
            ]);

        $response->assertOk();
        $this->assertEquals('cancelled', $comm->fresh()->status);
    }
}
