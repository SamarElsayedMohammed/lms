<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentReportAdminApiControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student1;
    private User $student2;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $lecture1;
    private CourseChapterLecture $lecture2;

    protected function setUp(): void
    {
        parent::setUp();

        $roleAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $roleStudent = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'admin@skillso.test']);
        $this->admin->assignRole($roleAdmin);

        $this->student1 = User::factory()->create([
            'name' => 'Ahmed Mohamed',
            'email' => 'ahmed@student.test',
        ]);
        $this->student1->assignRole($roleStudent);

        $this->student2 = User::factory()->create([
            'name' => 'Sara Ali',
            'email' => 'sara@student.test',
        ]);
        $this->student2->assignRole($roleStudent);

        $category = Category::create([
            'name' => 'Web Development',
            'slug' => 'web-development',
            'is_active' => true,
        ]);

        $this->course = Course::factory()->create([
            'title' => 'Complete React Masterclass',
            'slug' => 'complete-react-masterclass',
            'user_id' => $this->admin->id,
            'category_id' => $category->id,
            'price' => 500.0,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'course_type' => 'paid',
        ]);

        $this->chapter = CourseChapter::create([
            'course_id' => $this->course->id,
            'user_id' => $this->admin->id,
            'title' => 'Chapter 1: Basics',
            'order' => 1,
        ]);

        $this->lecture1 = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id' => $this->admin->id,
            'title' => 'Lecture 1',
            'slug' => 'lecture-1',
            'type' => 'file',
            'is_active' => 1,
            'order' => 1,
        ]);

        $this->lecture2 = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id' => $this->admin->id,
            'title' => 'Lecture 2',
            'slug' => 'lecture-2',
            'type' => 'file',
            'is_active' => 1,
            'order' => 2,
        ]);
    }

    // ─── 1. Authentication ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/reports/students')->assertUnauthorized();
        $this->getJson('/api/admin/reports/students/completion-stats')->assertUnauthorized();
        $this->getJson("/api/admin/reports/students/{$this->student1->id}")->assertUnauthorized();
    }

    public function test_non_admin_cannot_access_student_reports(): void
    {
        $this->actingAs($this->student1, 'sanctum')
            ->getJson('/api/admin/reports/students')
            ->assertForbidden();
    }

    // ─── 2. Students Overview Report ───────────────────────────────────────────

    public function test_students_report_lists_paginated_student_progress(): void
    {
        // Enroll student 1 via order
        $order = Order::create([
            'user_id' => $this->student1->id,
            'order_number' => 'ORD-STU-01',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $this->course->id,
            'price' => 500.0,
            'tax_price' => 0,
        ]);

        // Student 1 completed 1 of 2 lectures (50% progress -> in_progress)
        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture1->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'student_id',
                            'name',
                            'email',
                            'total_enrolled',
                            'completed_courses',
                            'in_progress',
                            'not_started',
                            'open_courses',
                            'completion_rate',
                            'joined_at',
                        ],
                    ],
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $studentItem = collect($response->json('data.data'))->firstWhere('student_id', $this->student1->id);
        $this->assertNotNull($studentItem);
        $this->assertEquals(1, $studentItem['total_enrolled']);
        $this->assertEquals(1, $studentItem['in_progress']);
        $this->assertEquals(0, $studentItem['completed_courses']);
    }

    public function test_students_report_search_by_name_or_email(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?search=Sara');

        $response->assertOk();
        $this->assertEquals(1, (int) $response->json('data.total'));
        $this->assertEquals($this->student2->id, $response->json('data.data.0.student_id'));
    }

    public function test_students_report_status_filter_completed_and_in_progress(): void
    {
        // Enroll student 1 & complete both lectures (100% progress)
        $order1 = Order::create([
            'user_id' => $this->student1->id,
            'order_number' => 'ORD-COMPLETED',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order1->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0]);

        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture1->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);
        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture2->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);

        \App\Models\UserCourseProgress::create([
            'user_id' => $this->student1->id,
            'course_id' => $this->course->id,
            'status' => 'completed',
            'progress_percentage' => 100.0,
            'completed_items' => 2,
            'total_items' => 2,
        ]);

        // Filter by status=completed
        $resCompleted = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?status=completed');
        $resCompleted->assertOk();
        $this->assertTrue(
            collect($resCompleted->json('data.data'))->contains('student_id', $this->student1->id)
        );
    }

    // ─── 3. Single Student Details Report ──────────────────────────────────────

    public function test_student_report_details_returns_course_milestone_breakdown(): void
    {
        $order = Order::create([
            'user_id' => $this->student1->id,
            'order_number' => 'ORD-DETAIL',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0]);

        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture1->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/reports/students/{$this->student1->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'student' => ['id', 'name', 'email'],
                    'summary' => [
                        'total_enrolled',
                        'completed_courses',
                        'in_progress_courses',
                        'not_started_courses',
                        'completion_rate',
                    ],
                    'courses' => [
                        '*' => [
                            'course_id',
                            'title',
                            'total_items',
                            'completed_items',
                            'progress_percentage',
                            'status',
                        ],
                    ],
                    'generated_at',
                ],
            ]);

        $this->assertEquals($this->student1->id, $response->json('data.student.id'));
        $this->assertEquals(1, $response->json('data.summary.total_enrolled'));
        $this->assertEquals(50.0, (float) $response->json('data.courses.0.progress_percentage'));
    }

    public function test_student_report_details_returns_404_for_unknown_student(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students/999999');

        $response->assertNotFound();
    }

    // ─── 4. Completion Statistics Endpoint ─────────────────────────────────────

    public function test_student_completion_stats_calculates_brackets_and_trends(): void
    {
        // Student 1 completed
        $order = Order::create([
            'user_id' => $this->student1->id,
            'order_number' => 'ORD-STAT-01',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0]);

        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture1->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);
        UserCurriculumTracking::create([
            'user_id' => $this->student1->id,
            'course_chapter_id' => $this->chapter->id,
            'model_id' => $this->lecture2->id,
            'model_type' => 'lecture',
            'status' => 'completed',
        ]);

        \App\Models\UserCourseProgress::create([
            'user_id' => $this->student1->id,
            'course_id' => $this->course->id,
            'status' => 'completed',
            'progress_percentage' => 100.0,
            'completed_items' => 2,
            'total_items' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students/completion-stats');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'total_students',
                    'students_with_enrollments',
                    'completed_students',
                    'completion_rate',
                    'completion_brackets' => [
                        'no_courses',
                        'not_started',
                        'in_progress',
                        'all_completed',
                    ],
                    'total_completed_course_enrollments',
                    'top_completed_courses',
                    'monthly_enrollment_trend',
                    'generated_at',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.completed_students'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.total_completed_course_enrollments'));
    }

    public function test_detailed_report_returns_correct_pagination_and_filters_by_status(): void
    {
        // Student 1: 100% completed
        $order1 = Order::create([
            'user_id' => $this->student1->id,
            'order_number' => 'ORD-DET-01',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order1->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0]);

        \App\Models\UserCourseProgress::create([
            'user_id' => $this->student1->id,
            'course_id' => $this->course->id,
            'status' => 'completed',
            'progress_percentage' => 100.0,
            'completed_items' => 2,
            'total_items' => 2,
        ]);

        // Student 2: 0% not_started
        $order2 = Order::create([
            'user_id' => $this->student2->id,
            'order_number' => 'ORD-DET-02',
            'total_price' => 500.0,
            'final_price' => 500.0,
            'amount_egp' => 500.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_method' => 'stripe',
            'status' => 'completed',
        ]);
        OrderCourse::create(['order_id' => $order2->id, 'course_id' => $this->course->id, 'price' => 500.0, 'tax_price' => 0]);

        \App\Models\UserCourseProgress::create([
            'user_id' => $this->student2->id,
            'course_id' => $this->course->id,
            'status' => 'not_started',
            'progress_percentage' => 0.0,
            'completed_items' => 0,
            'total_items' => 2,
        ]);

        // All detailed records
        $resAll = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?report_type=detailed');
        $resAll->assertOk();
        $this->assertEquals(2, $resAll->json('data.total'));
        $this->assertCount(2, $resAll->json('data.data'));

        // Filter status=completed
        $resCompleted = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?report_type=detailed&status=completed');
        $resCompleted->assertOk();
        $this->assertEquals(1, $resCompleted->json('data.total'));
        $this->assertCount(1, $resCompleted->json('data.data'));
        $this->assertEquals($this->student1->id, $resCompleted->json('data.data.0.student_id'));

        // Filter status=not_started
        $resNotStarted = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?report_type=detailed&status=not_started');
        $resNotStarted->assertOk();
        $this->assertEquals(1, $resNotStarted->json('data.total'));
        $this->assertCount(1, $resNotStarted->json('data.data'));
        $this->assertEquals($this->student2->id, $resNotStarted->json('data.data.0.student_id'));
    }

    public function test_summary_report_pagination_total_matches_filtered_status_count(): void
    {
        // Student 1: in_progress
        \App\Models\UserCourseProgress::create([
            'user_id' => $this->student1->id,
            'course_id' => $this->course->id,
            'status' => 'in_progress',
            'progress_percentage' => 50.0,
            'completed_items' => 1,
            'total_items' => 2,
        ]);

        // Student 2: not enrolled / no progress
        $resInProgress = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/students?status=in_progress');
        $resInProgress->assertOk();
        $this->assertEquals(1, $resInProgress->json('data.total'));
        $this->assertCount(1, $resInProgress->json('data.data'));
        $this->assertEquals($this->student1->id, $resInProgress->json('data.data.0.student_id'));
    }
}
