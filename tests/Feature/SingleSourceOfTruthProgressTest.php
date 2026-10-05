<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\Admin\AdminCourseProgressController;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Services\CourseProgressService;
use App\Services\StudentDashboardStatisticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleSourceOfTruthProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $admin;
    private Course $course;
    private CourseChapterLecture $lesson1;
    private CourseChapterLecture $lesson2;

    protected function setUp(): void
    {
        parent::setUp();

        $instructor = User::factory()->create(['is_active' => true]);
        $this->student = User::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');

        $this->course = Course::factory()->create([
            'user_id' => $instructor->id,
            'title' => 'Single Source of Truth Course',
            'certificate_enabled' => true,
            'sequential_access' => true,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        $chapter = CourseChapter::create([
            'course_id' => $this->course->id,
            'user_id' => $instructor->id,
            'title' => 'Chapter 1',
            'chapter_order' => 1,
            'is_active' => true,
        ]);

        $this->lesson1 = CourseChapterLecture::create([
            'course_chapter_id' => $chapter->id,
            'user_id' => $instructor->id,
            'title' => 'Lesson 1',
            'slug' => 'lesson-1',
            'chapter_order' => 1,
            'duration_seconds' => 100,
            'is_active' => true,
            'type' => 'url',
        ]);

        $this->lesson2 = CourseChapterLecture::create([
            'course_chapter_id' => $chapter->id,
            'user_id' => $instructor->id,
            'title' => 'Lesson 2',
            'slug' => 'lesson-2',
            'chapter_order' => 2,
            'duration_seconds' => 100,
            'is_active' => true,
            'type' => 'url',
        ]);

        // Enroll student
        \App\Models\Course\UserCourseTrack::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'in_progress',
        ]);

        $order = Order::create([
            'user_id' => $this->student->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-SSOT-' . microtime(true),
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $this->course->id,
            'price' => 100,
            'tax_price' => 0,
        ]);
    }

    /**
     * Prove that Dashboard, Certificates, Course Progress Bar, and Admin Reports
     * all show identical metrics for the same student.
     */
    public function test_progress_metrics_are_strictly_unified_across_all_consumers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // 1. Initially (0% watched):
        // (a) Course Progress Bar
        $resCourseProg = $this->actingAs($this->student)->getJson("/api/courses/{$this->course->id}/progress");
        $resCourseProg->assertStatus(200);
        $this->assertEquals(0, $resCourseProg->json('data.overall_percent'));
        $this->assertEquals(0, $resCourseProg->json('data.completed_lessons'));

        // (b) Student Dashboard Stats
        $dashboardStatsService = app(StudentDashboardStatisticsService::class);
        $dashStats = $dashboardStatsService->getDashboardStats($this->student);
        $this->assertEquals(0, $dashStats['completed_courses']);
        $this->assertEquals(0, $dashStats['average_progress']);
        $this->assertEquals(0, $dashStats['certificates']);

        // (c) Admin Course Student Details
        $courseProgService = app(CourseProgressService::class);
        $adminDetails = $courseProgService->getDetailedProgress($this->student->id, $this->course->id);
        $this->assertEquals(0, $adminDetails['summary']['completed_items']);
        $this->assertEquals(0, $adminDetails['course']['progress_percentage']);

        // 2. Student watches Lesson 1 completely (50% course progress):
        LessonProgress::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson1->id,
            'course_id' => $this->course->id,
            'last_position_seconds' => 100,
            'max_position_seconds' => 100,
            'watched_seconds' => 100,
            'percent' => 100.0,
            'watched_intervals' => [[0, 100]],
            'is_completed' => true,
            'completed_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        // Recompute
        $lessonProgressService = app(\App\Services\LessonProgressService::class);
        $lessonProgressService->recomputeCourseProgressAndCheckCertificate($this->student, $this->course->id);

        // Verify Course Progress Bar: 50%
        $resCourseProgMid = $this->actingAs($this->student)->getJson("/api/courses/{$this->course->id}/progress");
        $this->assertEquals(50.0, (float) $resCourseProgMid->json('data.overall_percent'));
        $this->assertEquals(1, $resCourseProgMid->json('data.completed_lessons'));

        // Verify Dashboard Stats: 50% average progress, 1 in-progress course, 0 certificates
        $dashStatsMid = $dashboardStatsService->getDashboardStats($this->student);
        $this->assertEquals(50.0, (float) $dashStatsMid['average_progress']);
        $this->assertEquals(1, $dashStatsMid['in_progress_courses']);
        $this->assertEquals(0, $dashStatsMid['completed_courses']);
        $this->assertEquals(0, $dashStatsMid['certificates']);

        // Verify Admin Report: 1 completed item, 50% progress
        $adminDetailsMid = $courseProgService->getDetailedProgress($this->student->id, $this->course->id);
        $this->assertEquals(1, $adminDetailsMid['summary']['completed_items']);
        $this->assertEquals(50.0, (float) $adminDetailsMid['course']['progress_percentage']);

        // 3. Student completes Lesson 2 (100% course completion):
        LessonProgress::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson2->id,
            'course_id' => $this->course->id,
            'last_position_seconds' => 100,
            'max_position_seconds' => 100,
            'watched_seconds' => 100,
            'percent' => 100.0,
            'watched_intervals' => [[0, 100]],
            'is_completed' => true,
            'completed_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $lessonProgressService->recomputeCourseProgressAndCheckCertificate($this->student, $this->course->id);

        // (a) Course Progress Bar: 100%
        $resCourseProgEnd = $this->actingAs($this->student)->getJson("/api/courses/{$this->course->id}/progress");
        $this->assertEquals(100.0, (float) $resCourseProgEnd->json('data.overall_percent'));
        $this->assertEquals(2, $resCourseProgEnd->json('data.completed_lessons'));
        $this->assertTrue($resCourseProgEnd->json('data.is_completed'));

        // (b) Dashboard Stats: 100% average progress, 1 completed course, 1 certificate
        $dashStatsEnd = $dashboardStatsService->getDashboardStats($this->student);
        $this->assertEquals(100.0, (float) $dashStatsEnd['average_progress']);
        $this->assertEquals(1, $dashStatsEnd['completed_courses']);
        $this->assertEquals(0, $dashStatsEnd['in_progress_courses']);
        $this->assertEquals(1, $dashStatsEnd['certificates']);

        // (c) Certificates Endpoint
        $resCerts = $this->actingAs($this->student)->getJson('/api/user/certificates');
        $resCerts->assertStatus(200);
        $certList = $resCerts->json('data.data') ?? $resCerts->json('data') ?? [];
        $this->assertCount(1, $certList);

        // (d) Admin Detailed Progress
        $adminDetailsEnd = $courseProgService->getDetailedProgress($this->student->id, $this->course->id);
        $this->assertEquals(2, $adminDetailsEnd['summary']['completed_items']);
        $this->assertEquals(100.0, (float) $adminDetailsEnd['course']['progress_percentage']);

        Carbon::setTestNow();
    }
}
