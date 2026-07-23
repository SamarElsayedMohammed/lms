<?php

namespace Tests\Feature\Api;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseCertificate;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\UserCourseProgress;
use App\Services\CourseProgressService;
use App\Services\PricingService;
use App\Services\StudentDashboardStatisticsService;
use App\Services\UserEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UserDashboardIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Course $completedCourse;
    private Course $incompleteCourse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->completedCourse = Course::factory()->create();
        $this->incompleteCourse = Course::factory()->create();

        $this->createEnrollmentWithContent($this->completedCourse);
        $this->createEnrollmentWithContent($this->incompleteCourse);

        $progressService = Mockery::mock(CourseProgressService::class);
        $progressService->shouldReceive('getProgressWithCache')
            ->andReturnUsing(function (int $userId, int $courseId): UserCourseProgress {
                return new UserCourseProgress([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'progress_percentage' => $courseId === $this->completedCourse->id ? 100 : 25,
                ]);
            });

        app()->instance(CourseProgressService::class, $progressService);
        app()->instance(
            StudentDashboardStatisticsService::class,
            new StudentDashboardStatisticsService(
                app(UserEnrollmentService::class),
                $progressService,
            ),
        );

        $pricingService = Mockery::mock(PricingService::class);
        $pricingService->shouldReceive('detectUserCountry')->andReturn('EG');
        $pricingService->shouldReceive('getCurrencyForCountry')->andReturn(null);
        $pricingService->shouldReceive('convertFromEgp')->andReturn(0.0);
        app()->instance(PricingService::class, $pricingService);
    }

    public function test_revoked_certificates_are_excluded_from_dashboard_statistics(): void
    {
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->incompleteCourse->id,
            'certificate_number' => 'REVOKED-CERTIFICATE',
            'status' => 'revoked',
        ]);

        $stats = app(StudentDashboardStatisticsService::class)->getDashboardStats($this->user);

        $this->assertSame(0, $stats['certificates']);
        $this->assertSame(1, $stats['completed_courses']);
        $this->assertSame(62.5, $stats['average_progress']);
    }

    public function test_manual_active_certificate_does_not_complete_a_low_progress_course(): void
    {
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->incompleteCourse->id,
            'certificate_number' => 'MANUAL-CERTIFICATE',
            'status' => 'active',
        ]);

        $stats = app(StudentDashboardStatisticsService::class)->getDashboardStats($this->user);

        $this->assertSame(1, $stats['certificates']);
        $this->assertSame(1, $stats['completed_courses']);
        $this->assertSame(62.5, $stats['average_progress']);
    }

    public function test_dashboard_completed_courses_match_stats_and_only_returns_contract_keys(): void
    {
        UserCourseProgress::create([
            'user_id' => $this->user->id,
            'course_id' => $this->completedCourse->id,
            'progress_percentage' => 3,
            'last_accessed_at' => now(),
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.completed_courses', 1)
            ->assertJsonCount(1, 'data.completed_courses')
            ->assertJsonPath('data.recent_courses.0.id', $this->completedCourse->id)
            ->assertJsonPath('data.recent_courses.0.progress_percentage', 100);

        $this->assertSame([
            'stats',
            'subscription',
            'wallet',
            'recent_courses',
            'latest_courses',
            'completed_courses',
            'learning_activity',
            'upcoming_webinars',
            'unread_notifications_count',
            'generated_at',
        ], array_keys($response->json('data')));
        $this->assertMatchesRegularExpression(
            '/(?:Z|\+00:00)$/',
            (string) $response->json('data.generated_at'),
        );
    }

    public function test_certificate_list_labels_low_progress_issued_certificates_as_manual(): void
    {
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->incompleteCourse->id,
            'certificate_number' => 'MANUAL-CERTIFICATE',
            'status' => 'active',
        ]);
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->completedCourse->id,
            'certificate_number' => 'REVOKED-CERTIFICATE',
            'status' => 'revoked',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/certificates');

        $response->assertOk()
            ->assertJsonPath('data.0.course_id', $this->incompleteCourse->id)
            ->assertJsonPath('data.0.issuance_type', 'manual')
            ->assertJsonPath('data.0.eligible_by_progress', false)
            ->assertJsonPath('data.0.progress_percentage', 25)
            ->assertJsonCount(2, 'data');
    }

    public function test_dashboard_excludes_activity_for_a_course_without_valid_access(): void
    {
        $historicalCourse = Course::factory()->create();
        $historicalChapter = CourseChapter::factory()->create(['course_id' => $historicalCourse->id]);
        $historicalLecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $historicalChapter->id,
        ]);

        UserCurriculumTracking::create([
            'user_id' => $this->user->id,
            'course_chapter_id' => $historicalChapter->id,
            'model_id' => $historicalLecture->id,
            'model_type' => CourseChapterLecture::class,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/dashboard');

        $response->assertOk()->assertJsonCount(0, 'data.learning_activity');
    }

    private function createEnrollmentWithContent(Course $course): void
    {
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
        CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'DASHBOARD-' . $course->id,
            'total_price' => 100,
            'final_price' => 100,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);

        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => 100,
            'tax_price' => 0,
        ]);
    }
}
