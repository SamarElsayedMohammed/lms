<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Models\Course\CourseChapter\Resource\LectureResource;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ContentAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CourseAccessAdversarialCertificationTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private User $student;
    private SubscriptionPlan $plan;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $paidLecture;
    private CourseChapterLecture $previewLecture;
    private CourseChapterResource $chapterResource;
    private CourseChapterAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        ContentAccessService::flushRequestCache();
        Cache::flush();

        $this->instructor = User::factory()->create([
            'name'      => 'Instructor User',
            'email'     => 'instructor-' . uniqid() . '@example.com',
            'is_active' => true,
        ]);

        $this->student = User::factory()->create([
            'name'      => 'Student User',
            'email'     => 'student-' . uniqid() . '@example.com',
            'is_active' => true,
        ]);

        $this->plan = SubscriptionPlan::create([
            'name'          => 'Pro Membership',
            'slug'          => 'pro-' . uniqid(),
            'price'         => 199.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active'     => true,
        ]);

        $this->course = Course::factory()->create([
            'title'               => 'Masterclass Course',
            'slug'                => 'masterclass-' . uniqid(),
            'user_id'             => $this->instructor->id,
            'status'              => 'publish',
            'approval_status'     => 'approved',
            'is_active'           => true,
            'course_type'         => 'paid',
            'level'               => 'intermediate',
            'certificate_enabled' => true,
            'certificate_fee'     => 0,
        ]);

        $this->chapter = CourseChapter::create([
            'course_id'     => $this->course->id,
            'user_id'       => $this->instructor->id,
            'title'         => 'Core Concepts Chapter',
            'slug'          => 'core-concepts-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $this->paidLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $this->chapter->id,
            'title'             => 'Paid Lecture 1',
            'slug'              => 'paid-lecture-1-' . uniqid(),
            'type'              => 'file',
            'file'              => 'lectures/paid_video.mp4',
            'file_extension'    => 'mp4',
            'is_active'         => true,
            'free_preview'      => false,
            'is_free'           => false,
            'chapter_order'     => 1,
        ]);

        $this->previewLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $this->chapter->id,
            'title'             => 'Free Preview Lecture',
            'slug'              => 'free-preview-' . uniqid(),
            'type'              => 'file',
            'file'              => 'lectures/preview_video.mp4',
            'file_extension'    => 'mp4',
            'is_active'         => true,
            'free_preview'      => true,
            'is_free'           => false,
            'chapter_order'     => 2,
        ]);

        $this->chapterResource = CourseChapterResource::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $this->chapter->id,
            'title'             => 'Confidential Cheatsheet PDF',
            'slug'              => 'cheatsheet-' . uniqid(),
            'type'              => 'file',
            'file'              => 'resources/cheatsheet.pdf',
            'file_extension'    => 'pdf',
            'is_active'         => true,
            'chapter_order'     => 1,
        ]);

        $this->assignment = CourseChapterAssignment::create([
            'user_id'            => $this->instructor->id,
            'course_chapter_id'  => $this->chapter->id,
            'title'              => 'Final Project Assignment',
            'slug'               => 'final-project-' . uniqid(),
            'description'        => 'Build a full-stack application.',
            'media'              => 'assignments/project_spec.zip',
            'media_extension'    => 'zip',
            'points'             => 100,
            'is_active'          => true,
            'chapter_order'      => 1,
        ]);
    }

    // =========================================================================
    // ATTACK-01: Anonymous Access Denial
    // =========================================================================
    public function test_attack_01_anonymous_user_cannot_access_protected_endpoints(): void
    {
        $this->getJson("/api/video/{$this->paidLecture->id}/stream")->assertUnauthorized();
        $this->getJson("/api/get-resources?course_id={$this->course->id}")->assertUnauthorized();
        $this->getJson("/api/curriculum/chapter-details?course_chapter_id={$this->chapter->id}")->assertUnauthorized();
        $this->getJson("/api/curriculum/progress?course_id={$this->course->id}")->assertUnauthorized();
        $this->postJson("/api/curriculum/mark-completed", [
            'course_chapter_id' => $this->chapter->id,
            'model_id'          => $this->paidLecture->id,
            'model_type'        => 'lecture',
        ])->assertUnauthorized();
        $this->getJson("/api/get-assignments?course_id={$this->course->id}")->assertUnauthorized();
    }

    // =========================================================================
    // ATTACK-02: Unsubscribed User Access Denial
    // =========================================================================
    public function test_attack_02_unsubscribed_user_denied_paid_lecture_stream(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");

        $response->assertForbidden();
    }

    // =========================================================================
    // ATTACK-03: Expired Subscription Immediate Revocation
    // =========================================================================
    public function test_attack_03_expired_subscription_blocks_stream_immediately(): void
    {
        Subscription::create([
            'user_id'   => $this->student->id,
            'plan_id'   => $this->plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDays(35),
            'ends_at'   => Carbon::now()->subDays(5),
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");

        $response->assertForbidden();
    }

    // =========================================================================
    // ATTACK-04: Client-Spoofed Access Claims Ignored
    // =========================================================================
    public function test_attack_04_tampered_client_claims_do_not_grant_access(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->withHeaders([
                'X-Is-Subscribed' => 'true',
                'X-Plan'          => 'pro',
                'X-User-Role'     => 'admin',
            ])
            ->getJson("/api/video/{$this->paidLecture->id}/stream?is_enrolled=1&has_access=true&free_preview=1");

        $response->assertForbidden();
    }

    // =========================================================================
    // ATTACK-05: Direct Deep Resource Protection
    // =========================================================================
    public function test_attack_05_deep_resources_and_curriculum_are_protected(): void
    {
        // Unsubscribed student calls get-resources
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/get-resources?course_id={$this->course->id}");
        $res->assertForbidden();

        // Unsubscribed student calls curriculum chapter-details
        $res2 = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/curriculum/chapter-details?course_chapter_id={$this->chapter->id}");
        $res2->assertForbidden();

        // Unsubscribed student calls curriculum progress
        $res3 = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/curriculum/progress?course_id={$this->course->id}");
        $res3->assertForbidden();

        // Unsubscribed student tries marking curriculum completed
        $res4 = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/curriculum/mark-completed", [
                'course_chapter_id' => $this->chapter->id,
                'model_id'          => $this->paidLecture->id,
                'model_type'        => 'lecture',
            ]);
        $res4->assertForbidden();
    }

    // =========================================================================
    // ATTACK-06: Video Stream UUID Token Lifecycle
    // =========================================================================
    public function test_attack_06_video_stream_token_generation_and_direct_serving(): void
    {
        // Give student active subscription
        Subscription::create([
            'user_id'   => $this->student->id,
            'plan_id'   => $this->plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDay(),
            'ends_at'   => Carbon::now()->addDays(29),
        ]);

        $streamRes = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");

        $streamRes->assertOk();
        $data = $streamRes->json('data');

        $this->assertNotNull($data['token']);
        $this->assertEquals('video', $data['type']);
        $this->assertStringContainsString('/api/video-direct/', $data['video_url']);

        // Invalid or expired UUID token cannot be served
        $fakeUuid = (string) Str::uuid();
        $fakeRes = $this->getJson("/api/video-direct/{$fakeUuid}");
        $fakeRes->assertForbidden();
    }

    // =========================================================================
    // ATTACK-08: Immediate Revocation on Refund
    // =========================================================================
    public function test_attack_08_refund_revokes_course_and_video_access_immediately(): void
    {
        $order = Order::create([
            'order_number'   => 'ORD-' . uniqid(),
            'user_id'        => $this->student->id,
            'status'         => 'completed',
            'total_price'    => 100.00,
            'tax_price'      => 0,
            'final_price'    => 100.00,
            'payment_method' => 'card',
        ]);

        OrderCourse::create([
            'order_id'  => $order->id,
            'course_id' => $this->course->id,
            'price'     => 100.00,
            'tax_price' => 0.00,
        ]);

        // Student currently has access via direct purchase
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");
        $res->assertOk();

        // Create approved refund
        RefundRequest::create([
            'user_id'        => $this->student->id,
            'course_id'      => $this->course->id,
            'order_id'       => $order->id,
            'transaction_id' => 'TXN-' . uniqid(),
            'status'         => 'approved',
            'amount'         => 100.00,
            'reason'         => 'Accidental purchase',
        ]);

        ContentAccessService::flushRequestCache();

        // Access is immediately revoked
        $resAfterRefund = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");
        $resAfterRefund->assertForbidden();
    }

    // =========================================================================
    // ATTACK-09: Suspended / Deactivated Account Token Invalidation
    // =========================================================================
    public function test_attack_09_suspended_user_is_blocked_at_middleware_boundary(): void
    {
        Subscription::create([
            'user_id'   => $this->student->id,
            'plan_id'   => $this->plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDay(),
            'ends_at'   => Carbon::now()->addDays(29),
        ]);

        // Deactivate / suspend student account
        $this->student->update(['is_active' => false]);

        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");

        $res->assertStatus(403);
        $res->assertJsonFragment(['message' => 'Account is suspended or deactivated.']);
    }

    // =========================================================================
    // ATTACK-10: Free Preview Boundary Hopping Denied
    // =========================================================================
    public function test_attack_10_free_preview_does_not_unlock_adjacent_paid_lectures(): void
    {
        // Preview lecture is accessible to guest/unsubscribed
        $previewRes = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->previewLecture->id}/stream");
        $previewRes->assertOk();

        // Paid lecture in the same chapter is strictly denied
        $paidRes = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$this->paidLecture->id}/stream");
        $paidRes->assertForbidden();
    }

    // =========================================================================
    // ATTACK-11: Horizontal IDOR on Assignment Submissions Blocked
    // =========================================================================
    public function test_attack_11_student_cannot_view_other_students_assignment_submissions(): void
    {
        $otherStudent = User::factory()->create([
            'name'      => 'Other Student',
            'email'     => 'other-' . uniqid() . '@example.com',
            'is_active' => true,
        ]);

        // Student has active subscription
        Subscription::create([
            'user_id'   => $this->student->id,
            'plan_id'   => $this->plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDay(),
            'ends_at'   => Carbon::now()->addDays(29),
        ]);

        // Other student submitted assignment
        UserAssignmentSubmission::create([
            'user_id'                       => $otherStudent->id,
            'course_chapter_assignment_id'  => $this->assignment->id,
            'status'                        => 'submitted',
        ]);

        // Student tries to view Other Student's submissions via user_id parameter
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/get-assignments?course_id={$this->course->id}&user_id={$otherStudent->id}");

        $res->assertForbidden();
        $res->assertJsonFragment(['message' => 'Unauthorized to view assignment submissions of another user.']);
    }

    // =========================================================================
    // ATTACK-14: Draft / Unapproved Course Protection (INV-14)
    // =========================================================================
    public function test_attack_14_draft_or_unapproved_course_cannot_be_streamed_by_subscriber(): void
    {
        $draftCourse = Course::factory()->create([
            'title'           => 'Draft Course',
            'slug'            => 'draft-course-' . uniqid(),
            'user_id'         => $this->instructor->id,
            'status'          => 'draft', // Not published
            'approval_status' => 'pending',
            'is_active'       => true,
            'course_type'     => 'paid',
        ]);

        $draftChapter = CourseChapter::create([
            'course_id'     => $draftCourse->id,
            'user_id'       => $this->instructor->id,
            'title'         => 'Draft Chapter',
            'slug'          => 'draft-ch-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $draftLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $draftChapter->id,
            'title'             => 'Draft Lecture',
            'slug'              => 'draft-lec-' . uniqid(),
            'type'              => 'file',
            'file'              => 'lectures/draft.mp4',
            'file_extension'    => 'mp4',
            'is_active'         => true,
            'free_preview'      => false,
            'is_free'           => false,
            'chapter_order'     => 1,
        ]);

        Subscription::create([
            'user_id'   => $this->student->id,
            'plan_id'   => $this->plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDay(),
            'ends_at'   => Carbon::now()->addDays(29),
        ]);

        // Active subscriber trying to stream draft course lecture by ID
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$draftLecture->id}/stream");

        $res->assertForbidden();

        // But instructor owner CAN access it
        $instructorRes = $this->actingAs($this->instructor, 'sanctum')
            ->getJson("/api/video/{$draftLecture->id}/stream");
        $instructorRes->assertOk();
    }

    // =========================================================================
    // ATTACK-16: Free Course Access Integrity
    // =========================================================================
    public function test_attack_16_free_course_is_accessible_to_any_active_user(): void
    {
        $freeCourse = Course::factory()->create([
            'title'           => 'Free Course',
            'slug'            => 'free-course-' . uniqid(),
            'user_id'         => $this->instructor->id,
            'status'          => 'publish',
            'approval_status' => 'approved',
            'is_active'       => true,
            'course_type'     => 'free',
            'is_free'         => true,
        ]);

        $freeChapter = CourseChapter::create([
            'course_id'     => $freeCourse->id,
            'user_id'       => $this->instructor->id,
            'title'         => 'Free Chapter',
            'slug'          => 'free-ch-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $freeLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $freeChapter->id,
            'title'             => 'Free Lecture',
            'slug'              => 'free-lec-' . uniqid(),
            'type'              => 'file',
            'file'              => 'lectures/free.mp4',
            'file_extension'    => 'mp4',
            'is_active'         => true,
            'free_preview'      => false,
            'is_free'           => false,
            'chapter_order'     => 1,
        ]);

        // Unsubscribed active student CAN access free course lecture
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/video/{$freeLecture->id}/stream");

        $res->assertOk();
    }
}
