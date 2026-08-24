<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\CourseChapter;
use App\Models\CourseChapterLecture;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Services\Reports\UnifiedSalesTransactionQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ReportsApiControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructorUser;
    private User $student;
    private Category $category;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin@skillso.test']);
        $this->admin->assignRole($role);

        $this->instructorUser = User::factory()->create([
            'name' => 'Instructor Alpha',
            'email' => 'instructor@skillso.test',
        ]);
        Instructor::create([
            'user_id' => $this->instructorUser->id,
            'status' => 'approved',
            'type' => 'individual',
        ]);

        $this->student = User::factory()->create([
            'name' => 'Student Beta',
            'email' => 'student@skillso.test',
        ]);

        $this->category = Category::create([
            'name' => 'Software Development',
            'slug' => 'software-development',
            'is_active' => true,
        ]);

        $this->course = Course::factory()->create([
            'title' => 'Mastering Laravel & Vue',
            'slug' => 'mastering-laravel-vue',
            'user_id' => $this->instructorUser->id,
            'category_id' => $this->category->id,
            'price' => 1000.0,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'level' => 'intermediate',
            'course_type' => 'paid',
        ]);
    }

    // ─── 1. Authentication & Security ──────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/reports/sales')->assertUnauthorized();
        $this->getJson('/api/admin/reports/sales')->assertUnauthorized();
        $this->getJson('/api/reports/revenue')->assertUnauthorized();
        $this->getJson('/api/reports/commission')->assertUnauthorized();
        $this->getJson('/api/reports/course')->assertUnauthorized();
        $this->getJson('/api/reports/instructor')->assertUnauthorized();
        $this->getJson('/api/reports/enrollment')->assertUnauthorized();
        $this->getJson('/api/reports/comprehensive')->assertUnauthorized();
        $this->getJson('/api/reports/filters')->assertUnauthorized();
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/reports/sales')
            ->assertForbidden();

        $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/admin/reports/sales')
            ->assertForbidden();
    }

    // ─── 2. Sales Report ───────────────────────────────────────────────────────

    public function test_sales_report_calculates_authoritative_aggregates_from_settled_orders(): void
    {
        // Completed Order 1
        $order1 = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-001',
            'total_price' => 1000.0,
            'final_price' => 1000.0,
            'amount_egp' => 1000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(2),
        ]);
        OrderCourse::create([
            'order_id' => $order1->id,
            'course_id' => $this->course->id,
            'price' => 1000.0,
            'tax_price' => 0.0,
        ]);

        // Completed Order 2
        $order2 = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-002',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'wallet',
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(1),
        ]);
        OrderCourse::create([
            'order_id' => $order2->id,
            'course_id' => $this->course->id,
            'price' => 500.0,
            'tax_price' => 0.0,
        ]);

        // Pending Order (should not be counted in settled revenue)
        $orderPending = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-PENDING',
            'total_price' => 2000.0,
            'final_price' => 2000.0,
            'amount_egp' => 2000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'pending',
            'created_at' => Carbon::now(),
        ]);
        OrderCourse::create([
            'order_id' => $orderPending->id,
            'course_id' => $this->course->id,
            'price' => 2000.0,
            'tax_price' => 0.0,
        ]);

        // Test legacy path
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_orders',
                    'total_revenue',
                    'average_order_value',
                    'completed_orders',
                    'pending_orders',
                    'cancelled_orders',
                    'payment_methods',
                ],
            ]);

        $this->assertEquals(3, (int) $response->json('data.total_orders'));
        $this->assertEquals(1500.0, (float) $response->json('data.total_revenue'));
        $this->assertEquals(2, (int) $response->json('data.completed_orders'));
        $this->assertEquals(1, (int) $response->json('data.pending_orders'));

        // Test canonical admin path alias
        $canonicalResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/sales');

        $canonicalResponse->assertOk();
        $this->assertEquals(1500.0, (float) $canonicalResponse->json('data.total_revenue'));
    }

    public function test_sales_report_filter_by_payment_method_and_card_gateways(): void
    {
        $orderCard = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-STRIPE',
            'total_price' => 600.0,
            'final_price' => 600.0,
            'amount_egp' => 600.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $orderCard->id, 'course_id' => $this->course->id, 'price' => 600.0, 'tax_price' => 0.0]);

        $orderWallet = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-WALLET',
            'total_price' => 400.0,
            'final_price' => 400.0,
            'amount_egp' => 400.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'wallet',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $orderWallet->id, 'course_id' => $this->course->id, 'price' => 400.0, 'tax_price' => 0.0]);

        // Filter by payment_method=stripe
        $resStripe = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?payment_method=stripe');
        $resStripe->assertOk();
        $this->assertEquals(600.0, (float) $resStripe->json('data.total_revenue'));
        $this->assertEquals(1, (int) $resStripe->json('data.completed_orders'));

        // Filter by card_gateways_only=true
        $resCardGateways = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?card_gateways_only=1');
        $resCardGateways->assertOk();
        $this->assertEquals(600.0, (float) $resCardGateways->json('data.total_revenue'));
    }

    public function test_credit_cards_revenue_endpoint_automatically_filters_card_gateways(): void
    {
        $orderStripe = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-STRIPE-CC',
            'total_price' => 750.0,
            'final_price' => 750.0,
            'amount_egp' => 750.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $orderStripe->id, 'course_id' => $this->course->id, 'price' => 750.0, 'tax_price' => 0.0]);

        $orderManual = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-MANUAL-CC',
            'total_price' => 300.0,
            'final_price' => 300.0,
            'amount_egp' => 300.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'manual',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $orderManual->id, 'course_id' => $this->course->id, 'price' => 300.0, 'tax_price' => 0.0]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/credit-cards-revenue');

        $response->assertOk();
        $this->assertEquals(750.0, (float) $response->json('data.total_revenue'));
        $this->assertEquals(1, (int) $response->json('data.completed_orders'));
    }

    public function test_sales_report_detailed_and_chart_modes(): void
    {
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-CHART',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
            'created_at' => Carbon::now(),
        ]);
        OrderCourse::create(['order_id' => $order->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0.0]);

        // Detailed mode
        $resDetailed = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?report_type=detailed&per_page=10');
        $resDetailed->assertOk()->assertJsonStructure(['data', 'meta' => ['total', 'current_page']]);

        // Chart mode
        $resChart = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?report_type=chart&group_by=day');
        $resChart->assertOk()->assertJsonStructure(['data' => ['*' => ['period', 'orders_count', 'revenue']]]);
    }

    // ─── 3. Revenue Report ─────────────────────────────────────────────────────

    public function test_revenue_report_matches_settled_sales_revenue(): void
    {
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-REV-01',
            'total_price' => 1200.0,
            'final_price' => 1200.0,
            'amount_egp' => 1200.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order->id, 'course_id' => $this->course->id, 'price' => 1200.0, 'tax_price' => 0.0]);

        $resSales = $this->actingAs($this->admin, 'sanctum')->getJson('/api/reports/sales');
        $resRevenue = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/revenue');

        $resRevenue->assertOk();
        $this->assertEquals($resSales->json('data.total_revenue'), $resRevenue->json('data.total_revenue'));
    }

    // ─── 4. Commission Report ──────────────────────────────────────────────────

    public function test_commission_report_aggregates_paid_and_pending_commissions(): void
    {
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-COMM',
            'total_price' => 1000.0,
            'final_price' => 1000.0,
            'amount_egp' => 1000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);

        // Paid Commission
        Commission::create([
            'order_id' => $order->id,
            'instructor_id' => $this->instructorUser->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 1000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 300.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 700.0,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        // Pending Commission
        Commission::create([
            'order_id' => $order->id,
            'instructor_id' => $this->instructorUser->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 500.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 150.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 350.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/commission');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_commission_amount',
                    'total_admin_commission_amount',
                    'paid_commission_amount',
                    'pending_commission_amount',
                    'total_commission_count',
                    'paid_commission_count',
                    'pending_commission_count',
                ],
            ]);

        $this->assertEquals(1500.0, (float) $response->json('data.total_commission_amount'));
        $this->assertEquals(450.0, (float) $response->json('data.total_admin_commission_amount'));
        $this->assertEquals(300.0, (float) $response->json('data.paid_commission_amount'));
        $this->assertEquals(150.0, (float) $response->json('data.pending_commission_amount'));
        $this->assertEquals(2, (int) $response->json('data.total_commission_count'));
        $this->assertEquals(1, (int) $response->json('data.paid_commission_count'));
        $this->assertEquals(1, (int) $response->json('data.pending_commission_count'));
    }

    public function test_commission_report_filters_by_status(): void
    {
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-COMM-FLT',
            'total_price' => 1000.0,
            'final_price' => 1000.0,
            'amount_egp' => 1000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);

        Commission::create([
            'order_id' => $order->id,
            'instructor_id' => $this->instructorUser->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 1000.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 300.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 700.0,
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        Commission::create([
            'order_id' => $order->id,
            'instructor_id' => $this->instructorUser->id,
            'course_id' => $this->course->id,
            'instructor_type' => 'individual',
            'course_price' => 500.0,
            'admin_commission_rate' => 30.0,
            'admin_commission_amount' => 150.0,
            'instructor_commission_rate' => 70.0,
            'instructor_commission_amount' => 350.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/commission?status=paid');

        $response->assertOk();
        $this->assertEquals(1, (int) $response->json('data.total_commission_count'));
        $this->assertEquals(300.0, (float) $response->json('data.total_admin_commission_amount'));
    }

    // ─── 5. Course & Instructor Reports ────────────────────────────────────────

    public function test_course_report_aggregates_courses_and_distribution(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/course');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_courses',
                    'active_courses',
                    'free_courses',
                    'paid_courses',
                    'total_enrollments',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.total_courses'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.active_courses'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.paid_courses'));
    }

    public function test_course_report_counts_unique_students_not_purchase_rows(): void
    {
        foreach (['ORD-UNIQUE-01', 'ORD-UNIQUE-02'] as $orderNumber) {
            $order = Order::create([
                'user_id' => $this->student->id,
                'order_number' => $orderNumber,
                'total_price' => 1000.0,
                'final_price' => 1000.0,
                'amount_egp' => 1000.0,
                'exchange_rate_snapshot' => 1.0,
                'payment_method' => 'stripe',
                'status' => 'completed',
            ]);
            OrderCourse::create([
                'order_id' => $order->id,
                'course_id' => $this->course->id,
                'price' => 1000.0,
                'tax_price' => 0.0,
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/course');

        $response->assertOk();
        $this->assertSame(2, (int) $response->json('data.total_enrollments'));
        $this->assertSame(1, (int) $response->json('data.total_students'));
        $this->assertSame('unique_users_with_completed_course_purchases', $response->json('data.students_grain'));
    }

    public function test_course_report_defaults_to_published_active_courses_unless_all_is_explicit(): void
    {
        Course::factory()->create([
            'user_id' => $this->instructorUser->id,
            'category_id' => $this->category->id,
            'is_active' => true,
            'status' => 'draft',
        ]);
        Course::factory()->create([
            'user_id' => $this->instructorUser->id,
            'category_id' => $this->category->id,
            'is_active' => false,
            'status' => 'publish',
        ]);

        $defaultResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/course');
        $allResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/course?status=all');

        $defaultResponse->assertOk();
        $allResponse->assertOk();
        $this->assertSame(1, (int) $defaultResponse->json('data.total_courses'));
        $this->assertSame(3, (int) $allResponse->json('data.total_courses'));
    }

    public function test_course_and_instructor_summaries_aggregate_in_sql(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/API/ReportsApiController.php'));

        $courseSummary = $this->methodSource($source, 'getCourseSummaryData', 'getDetailedCourseData');
        $this->assertStringContainsString('selectRaw', $courseSummary);
        $this->assertStringNotContainsString("withAvg('ratings', 'rating')->get()", $courseSummary);

        $instructorSummary = $this->methodSource($source, 'getInstructorSummaryData', 'getDetailedInstructorData');
        $this->assertStringContainsString('selectRaw', $instructorSummary);
        $this->assertStringContainsString("whereIn('instructors.user_id'", $instructorSummary);

        $coursePerformance = $this->methodSource($source, 'getCoursePerformanceData', 'getInstructorSummaryData');
        $this->assertStringContainsString('paginate(', $coursePerformance);

        $instructorPerformance = $this->methodSource($source, 'getInstructorPerformanceData', 'getEnrollmentSummaryData');
        $this->assertStringContainsString('paginate(', $instructorPerformance);
    }

    private function methodSource(string $source, string $method, string $nextMethod): string
    {
        $start = strpos($source, "function {$method}(");
        $end = strpos($source, "function {$nextMethod}(", $start ?: 0);
        $this->assertNotFalse($start, $method);
        $this->assertNotFalse($end, $nextMethod);

        return substr($source, $start, $end - $start);
    }

    public function test_unified_sales_export_rejects_rows_above_its_explicit_limit(): void
    {
        foreach (range(1, 2) as $index) {
            Order::create([
                'user_id' => $this->student->id,
                'order_number' => 'ORD-EXPORT-' . $index,
                'total_price' => 100.0,
                'final_price' => 100.0,
                'amount_egp' => 100.0,
                'exchange_rate_snapshot' => 1.0,
                'payment_method' => 'stripe',
                'status' => 'completed',
                'created_at' => Carbon::now(),
            ]);
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Export is limited to 1 rows');

        (new UnifiedSalesTransactionQuery())->export(
            Request::create('/api/reports/sales', 'GET'),
            false,
            1,
        );
    }

    public function test_instructor_report_aggregates_instructor_metrics(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/instructor');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_instructors',
                    'individual_instructors',
                    'approved_instructors',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.total_instructors'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.approved_instructors'));
    }

    // ─── 6. Enrollment Report ──────────────────────────────────────────────────

    public function test_enrollment_report_calculates_progress_and_completion_rate(): void
    {
        UserCourseProgress::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_at' => Carbon::now(),
        ]);

        $student2 = User::factory()->create();
        UserCourseProgress::create([
            'user_id' => $student2->id,
            'course_id' => $this->course->id,
            'status' => 'in_progress',
            'progress_percentage' => 50,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/enrollment');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_enrollments',
                    'in_progress_enrollments',
                    'completed_enrollments',
                    'completion_rate',
                ],
            ]);

        $this->assertEquals(2, (int) $response->json('data.total_enrollments'));
        $this->assertEquals(1, (int) $response->json('data.completed_enrollments'));
        $this->assertEquals(1, (int) $response->json('data.in_progress_enrollments'));
        $this->assertEquals(50.0, (float) $response->json('data.completion_rate'));
    }

    // ─── 7. Comprehensive Report ───────────────────────────────────────────────

    public function test_comprehensive_report_returns_all_domain_summaries(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/comprehensive');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'sales_summary',
                    'course_summary',
                    'instructor_summary',
                    'enrollment_summary',
                    'generated_at',
                ],
            ]);

        $this->assertArrayHasKey('total_revenue', $response->json('data.sales_summary'));
        $this->assertArrayHasKey('total_courses', $response->json('data.course_summary'));
        $this->assertArrayHasKey('total_instructors', $response->json('data.instructor_summary'));
        $this->assertArrayHasKey('total_enrollments', $response->json('data.enrollment_summary'));
    }

    // ─── 8. Filter Options Endpoint ────────────────────────────────────────────

    public function test_report_filters_endpoint_returns_options_lists(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/filters');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'courses',
                    'instructors',
                    'categories',
                    'order_statuses',
                    'commission_statuses',
                    'payment_methods',
                    'course_statuses',
                    'course_types',
                    'course_levels',
                    'report_types',
                    'group_by_options',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.order_statuses'));
        $this->assertNotEmpty($response->json('data.payment_methods'));
    }

    // ─── 9. Financial Reconciliation & Precision Invariants ─────────────────

    public function test_failed_discounted_payment_renders_zero_paid_amount_and_zero_revenue_and_increments_failed_orders(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium Plan',
            'slug' => 'premium-plan',
            'price' => 5000.0,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'starts_at' => Carbon::now(),
            'status' => 'pending',
        ]);

        // Failed payment with discount: original 5000, discount 500, final payable 4500, status failed
        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->student->id,
            'original_amount' => 5000.0,
            'discount_amount' => 500.0,
            'final_amount' => 4500.0,
            'amount' => 4500.0,
            'promo_code' => 'WELCOME',
            'currency_code' => 'EGP',
            'status' => SubscriptionPayment::STATUS_FAILED,
            'payment_method' => 'manual',
            'paid_at' => null,
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?report_type=detailed');

        $response->assertOk();

        // 1. Summary aggregations
        $this->assertEquals(1, (int) $response->json('data.summary.total_orders'));
        $this->assertEquals(0, (int) $response->json('data.summary.completed_orders'));
        $this->assertEquals(1, (int) $response->json('data.summary.failed_orders'));
        $this->assertEquals(0.0, (float) $response->json('data.summary.total_revenue'));

        // 2. Row level truth
        $row = collect($response->json('data.data'))->firstWhere('order_number', 'SUB-' . $payment->id);
        $this->assertNotNull($row);
        $this->assertSame('failed', $row['status']);
        $this->assertEquals(5000.0, (float) $row['original_price']);
        $this->assertEquals(500.0, (float) $row['discount_amount']);
        $this->assertEquals(4500.0, (float) $row['final_price']);
        $this->assertEquals(4500.0, (float) $row['net_payable_amount']);
        $this->assertEquals(0.0, (float) $row['paid_amount']); // Actual paid MUST be 0.00
        $this->assertEquals(0.0, (float) $row['amount']);      // Recognized revenue MUST be 0.00
        $this->assertNull($row['paid_at']);
    }

    public function test_successful_discounted_payment_reports_correct_revenue_and_paid_amount(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium Plan',
            'slug' => 'premium-plan-2',
            'price' => 5000.0,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'starts_at' => Carbon::now(),
            'status' => 'active',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->student->id,
            'original_amount' => 5000.0,
            'discount_amount' => 500.0,
            'final_amount' => 4500.0,
            'amount' => 4500.0,
            'promo_code' => 'WELCOME',
            'currency_code' => 'EGP',
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'stripe',
            'paid_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?report_type=detailed');

        $response->assertOk();
        $this->assertEquals(1, (int) $response->json('data.summary.completed_orders'));
        $this->assertEquals(0, (int) $response->json('data.summary.failed_orders'));
        $this->assertEquals(4500.0, (float) $response->json('data.summary.total_revenue'));

        $row = collect($response->json('data.data'))->firstWhere('order_number', 'SUB-' . $payment->id);
        $this->assertNotNull($row);
        $this->assertEquals(4500.0, (float) $row['paid_amount']);
        $this->assertEquals(4500.0, (float) $row['amount']);
    }

    public function test_100_percent_discount_free_order_reports_zero_cash_revenue_and_completed_order(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Free Trial Plan',
            'slug' => 'free-trial-plan',
            'price' => 5000.0,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'user_id' => $this->student->id,
            'plan_id' => $plan->id,
            'starts_at' => Carbon::now(),
            'status' => 'active',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->student->id,
            'original_amount' => 5000.0,
            'discount_amount' => 5000.0,
            'final_amount' => 0.0,
            'amount' => 0.0,
            'promo_code' => 'AHMED',
            'currency_code' => 'EGP',
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => 'wallet',
            'paid_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales?report_type=detailed');

        $response->assertOk();
        $this->assertEquals(1, (int) $response->json('data.summary.completed_orders'));
        $this->assertEquals(0.0, (float) $response->json('data.summary.total_revenue'));

        $row = collect($response->json('data.data'))->firstWhere('order_number', 'SUB-' . $payment->id);
        $this->assertNotNull($row);
        $this->assertSame('completed', $row['status']);
        $this->assertEquals(0.0, (float) $row['paid_amount']);
        $this->assertEquals(0.0, (float) $row['amount']);
    }

    public function test_refund_reduces_net_recognized_revenue_while_preserving_gross_and_details(): void
    {
        $order = Order::create([
            'user_id' => $this->student->id,
            'order_number' => 'ORD-REFUND-TEST',
            'total_price' => 2000.0,
            'final_price' => 2000.0,
            'amount_egp' => 2000.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
            'created_at' => Carbon::now()->subDay(),
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $this->course->id,
            'price' => 2000.0,
            'tax_price' => 0.0,
        ]);

        RefundRequest::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'order_id' => $order->id,
            'refund_amount' => 500.0,
            'amount_egp' => 500.0,
            'status' => 'approved',
            'reason' => 'Duplicate purchase',
            'processed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales');

        $response->assertOk();
        $this->assertEquals(2000.0, (float) $response->json('data.gross_revenue'));
        $this->assertEquals(500.0, (float) $response->json('data.total_refunds'));
        $this->assertEquals(1500.0, (float) $response->json('data.total_revenue'));
        $this->assertEquals(1500.0, (float) $response->json('data.net_revenue'));
    }

    public function test_detailed_sales_report_reconciles_total_orders_with_completed_pending_failed_cancelled(): void
    {
        // 1 Completed Order (1000)
        $o1 = Order::create(['user_id' => $this->student->id, 'order_number' => 'ORD-C1', 'total_price' => 1000.0, 'final_price' => 1000.0, 'amount_egp' => 1000.0, 'payment_method' => 'stripe', 'status' => 'completed']);
        OrderCourse::create(['order_id' => $o1->id, 'course_id' => $this->course->id, 'price' => 1000.0, 'tax_price' => 0.0]);

        // 1 Pending Order (500)
        $o2 = Order::create(['user_id' => $this->student->id, 'order_number' => 'ORD-P1', 'total_price' => 500.0, 'final_price' => 500.0, 'amount_egp' => 500.0, 'payment_method' => 'stripe', 'status' => 'pending']);
        OrderCourse::create(['order_id' => $o2->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0.0]);

        // 1 Cancelled Order (300)
        $o3 = Order::create(['user_id' => $this->student->id, 'order_number' => 'ORD-X1', 'total_price' => 300.0, 'final_price' => 300.0, 'amount_egp' => 300.0, 'payment_method' => 'stripe', 'status' => 'cancelled']);
        OrderCourse::create(['order_id' => $o3->id, 'course_id' => $this->course->id, 'price' => 300.0, 'tax_price' => 0.0]);

        // 1 Failed Subscription Payment (4500)
        $plan = SubscriptionPlan::create(['name' => 'Plan B', 'price' => 5000.0, 'is_active' => true]);
        $sub = Subscription::create(['user_id' => $this->student->id, 'plan_id' => $plan->id, 'status' => 'pending']);
        SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'user_id' => $this->student->id,
            'original_amount' => 5000.0,
            'discount_amount' => 500.0,
            'final_amount' => 4500.0,
            'amount' => 4500.0,
            'status' => SubscriptionPayment::STATUS_FAILED,
            'payment_method' => 'manual',
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/reports/sales');

        $response->assertOk();
        $summary = $response->json('data');

        $totalOrders = (int) $summary['total_orders'];
        $completed = (int) $summary['completed_orders'];
        $pending = (int) $summary['pending_orders'];
        $cancelled = (int) $summary['cancelled_orders'];
        $failed = (int) $summary['failed_orders'];

        $this->assertEquals(4, $totalOrders);
        $this->assertEquals(1, $completed);
        $this->assertEquals(1, $pending);
        $this->assertEquals(1, $cancelled);
        $this->assertEquals(1, $failed);
        $this->assertEquals($totalOrders, $completed + $pending + $cancelled + $failed);
    }
}

