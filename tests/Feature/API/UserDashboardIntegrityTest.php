<?php

namespace Tests\Feature\Api;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseCertificate;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\UserCurriculumTracking;
use App\Models\UserCourseProgress;
use Carbon\Carbon;
use App\Services\CourseProgressService;
use App\Services\PricingService;
use App\Services\StudentDashboardStatisticsService;
use App\Services\UserEnrollmentService;
use App\Services\SubscriptionService;
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
                    'completed_items' => $courseId === $this->completedCourse->id ? 1 : 0,
                    'total_items' => 1,
                    'status' => $courseId === $this->completedCourse->id ? 'completed' : 'in_progress',
                ]);
            });
        $progressService->shouldReceive('resolveLearningStatus')
            ->andReturnUsing(function (UserCourseProgress $progress, int $watchedSeconds = 0): string {
                if ($progress->total_items > 0
                    && $progress->completed_items >= $progress->total_items
                    && $progress->progress_percentage >= 100) {
                    return 'completed';
                }

                return $progress->progress_percentage > 0
                    || $progress->completed_items > 0
                    || $watchedSeconds > 0
                        ? 'in_progress'
                        : 'not_started';
            });

        app()->instance(CourseProgressService::class, $progressService);
        app()->instance(
            StudentDashboardStatisticsService::class,
            new StudentDashboardStatisticsService(
                app(UserEnrollmentService::class),
                $progressService,
            ),
        );

    }

    public function test_revoked_certificates_are_excluded_from_dashboard_statistics(): void
    {
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->incompleteCourse->id,
            'certificate_number' => 'REVOKED-CERTIFICATE',
            'status' => 'revoked',
            'issued_date' => now(),
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
            'issued_date' => now(),
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
            'certificate_preview',
            'issued_certificate_course_ids',
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
            'issued_date' => now(),
        ]);
        CourseCertificate::create([
            'user_id' => $this->user->id,
            'course_id' => $this->completedCourse->id,
            'certificate_number' => 'REVOKED-CERTIFICATE',
            'status' => 'revoked',
            'issued_date' => now(),
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
        $response->assertOk();
        $this->assertCount(0, $response->json('data.learning_activity') ?? []);
    }

    public function test_my_learning_enrolled_at_uses_user_enrollment_timestamp(): void
    {
        $courseCreatedAt = Carbon::parse('2025-01-10 09:00:00');
        $enrolledAt = Carbon::parse('2026-06-15 12:30:00');
        $this->completedCourse->forceFill(['created_at' => $courseCreatedAt, 'updated_at' => $courseCreatedAt])->save();

        $order = Order::whereHas('orderCourses', fn ($query) => $query->where('course_id', $this->completedCourse->id))->firstOrFail();
        $order->forceFill(['created_at' => $enrolledAt, 'updated_at' => $enrolledAt])->save();

        $myLearning = $this->actingAs($this->user, 'sanctum')->getJson('/api/my-learning?per_page=100');
        $myLearning->assertOk();
        $courseRow = collect($myLearning->json('data.data'))->firstWhere('id', $this->completedCourse->id);

        $this->assertSame('2026-06-15 12:30:00', $courseRow['enrolled_at']);
        $this->assertSame('2026-06-15 12:30:00', $courseRow['purchase_date']);
        $this->assertNotSame($courseCreatedAt->format('Y-m-d H:i:s'), $courseRow['enrolled_at']);

        $dashboard = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/dashboard');
        $dashboard->assertOk();
        $dashboardCourse = collect($dashboard->json('data.latest_courses'))->firstWhere('id', $this->completedCourse->id);
        $this->assertSame(Carbon::parse($courseRow['enrolled_at'])->toIso8601String(), $dashboardCourse['enrolled_at']);
    }

    public function test_curriculum_current_never_fakes_resume_for_never_started_course(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/curriculum/current?course_id=' . $this->incompleteCourse->id);

        $response->assertOk()
            ->assertJsonPath('data.current_curriculum_id', null)
            ->assertJsonPath('data.last_activity_at', null)
            ->assertJsonPath('data.empty_reason', 'never_started');

        $this->assertNotNull($response->json('data.start_item.lesson_id'));
    }

    public function test_curriculum_current_handles_course_without_active_chapter_or_lecture(): void
    {
        $emptyCourse = Course::factory()->create();
        $this->createEnrollment($emptyCourse, false);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/curriculum/current?course_id=' . $emptyCourse->id);

        $response->assertOk()
            ->assertJsonPath('data.current_curriculum_id', null)
            ->assertJsonPath('data.start_item', null)
            ->assertJsonPath('data.empty_reason', 'no_active_curriculum');
    }

    public function test_curriculum_current_ignores_stale_deleted_resume_target(): void
    {
        $chapter = CourseChapter::where('course_id', $this->incompleteCourse->id)->firstOrFail();
        $lecture = CourseChapterLecture::where('course_chapter_id', $chapter->id)->firstOrFail();
        UserCurriculumTracking::create([
            'user_id' => $this->user->id,
            'course_chapter_id' => $chapter->id,
            'model_id' => $lecture->id,
            'model_type' => CourseChapterLecture::class,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $lecture->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/curriculum/current?course_id=' . $this->incompleteCourse->id);

        $response->assertOk()
            ->assertJsonPath('data.current_curriculum_id', null)
            ->assertJsonPath('data.start_item', null)
            ->assertJsonPath('data.empty_reason', 'no_active_curriculum');
    }

    public function test_partial_video_watch_marks_course_started_without_completed_item(): void
    {
        $progress = new UserCourseProgress([
            'progress_percentage' => 0,
            'completed_items' => 0,
            'total_items' => 1,
            'status' => 'not_started',
        ]);

        $this->assertSame(
            'in_progress',
            (new CourseProgressService())->resolveLearningStatus($progress, 60),
        );
    }

    public function test_dashboard_subscription_matches_canonical_subscription_service_after_queued_activation(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subMinute(),
            'status' => Subscription::STATUS_ACTIVE,
            'auto_renew' => false,
        ]);
        $queued = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subSecond(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_PENDING,
            'auto_renew' => false,
        ]);

        $canonical = app(SubscriptionService::class)->getActiveSubscription($this->user);
        $this->assertSame($queued->id, $canonical?->id);

        $dashboard = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/dashboard');
        $subscriptionApi = $this->actingAs($this->user, 'sanctum')->getJson('/api/subscription/my-subscription');

        $dashboard->assertOk()->assertJsonPath('data.subscription.id', $queued->id);
        $subscriptionApi->assertOk()->assertJsonPath('data.subscription.id', $queued->id);
    }

    public function test_user_endpoints_reconcile_for_one_fixture_user(): void
    {
        $dashboard = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/dashboard');
        $myLearning = $this->actingAs($this->user, 'sanctum')->getJson('/api/my-learning?per_page=100');
        $resume = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/curriculum/current?course_id=' . $this->incompleteCourse->id);
        $certificates = $this->actingAs($this->user, 'sanctum')->getJson('/api/user/certificates');
        $subscription = $this->actingAs($this->user, 'sanctum')->getJson('/api/subscription/my-subscription');
        $payments = $this->actingAs($this->user, 'sanctum')->getJson('/api/subscription/history');
        $notifications = $this->actingAs($this->user, 'sanctum')->getJson('/api/notifications');

        foreach ([$dashboard, $myLearning, $resume, $certificates, $subscription, $payments, $notifications] as $response) {
            $response->assertOk();
        }

        $this->assertSame($dashboard->json('data.stats.total_courses'), $myLearning->json('data.summary_counts.all'));
        $this->assertSame($dashboard->json('data.stats.not_started_courses'), $myLearning->json('data.summary_counts.not_started'));
        $this->assertSame($dashboard->json('data.stats.in_progress_courses'), $myLearning->json('data.summary_counts.in_progress'));
        $this->assertSame($dashboard->json('data.stats.completed_courses'), $myLearning->json('data.summary_counts.completed'));
        $this->assertSame(0, $dashboard->json('data.stats.certificates'));
        $issuedCertificates = collect($certificates->json('data'))->where('is_issued', true);
        $this->assertSame($dashboard->json('data.stats.certificates'), $issuedCertificates->count());
        $this->assertNull($resume->json('data.current_curriculum_id'));
        $this->assertFalse((bool) $subscription->json('data.has_access'));
        $this->assertSame(0, $payments->json('data.transactions_count'));
        $this->assertSame(0, $dashboard->json('data.unread_notifications_count'));
    }

    public function test_critical_user_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user/dashboard')->assertUnauthorized();
        $this->getJson('/api/my-learning')->assertUnauthorized();
        $this->getJson('/api/curriculum/current?course_id=' . $this->incompleteCourse->id)->assertUnauthorized();
        $this->getJson('/api/subscription/my-subscription')->assertUnauthorized();
        $this->getJson('/api/user/certificates')->assertUnauthorized();
    }

    private function createEnrollmentWithContent(Course $course): void
    {
        $this->createEnrollment($course, true);
    }

    private function createEnrollment(Course $course, bool $withContent): Order
    {
        if ($withContent) {
            $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
            CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);
        }

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

        return $order;
    }
}
