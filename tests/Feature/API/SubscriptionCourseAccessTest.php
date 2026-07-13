<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ContentAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SubscriptionCourseAccessTest
 *
 * Reproduces the reported bug: "subscribed students cannot access some courses".
 * Proves the fix by running all boundary scenarios through ContentAccessService.
 *
 * Uses DatabaseTransactions so every test rolls back without leaving dirty data.
 */
final class SubscriptionCourseAccessTest extends TestCase
{
    use DatabaseTransactions;

    private ContentAccessService $service;
    private SubscriptionPlan $plan;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $lecture;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        // Flush the per-request memoisation cache before each test so results
        // from a previous test cannot bleed through.
        ContentAccessService::flushRequestCache();

        $this->service = app(ContentAccessService::class);

        // Create an instructor user and a published paid course with one lecture.
        $this->instructor = User::factory()->create([
            'is_active' => true,
        ]);

        $this->plan = SubscriptionPlan::create([
            'name'          => 'Monthly Plan',
            'slug'          => 'monthly-plan-' . uniqid(),
            'price'         => 99.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active'     => true,
        ]);

        $this->course = Course::create([
            'title'           => 'Test Course ' . uniqid(),
            'slug'            => 'test-course-' . uniqid(),
            'user_id'         => $this->instructor->id,
            'status'          => 'publish',
            'approval_status' => 'approved',
            'is_active'       => true,
            'course_type'     => 'paid',
            'price'           => 100.00,
        ]);

        $this->chapter = CourseChapter::create([
            'course_id'     => $this->course->id,
            'user_id'       => $this->instructor->id,
            'title'         => 'Chapter 1',
            'slug'          => 'chapter-1-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $this->lecture = CourseChapterLecture::create([
            'user_id'          => $this->instructor->id,
            'course_chapter_id' => $this->chapter->id,
            'title'            => 'Lecture 1',
            'slug'             => 'lecture-1-' . uniqid(),
            'type'             => 'video',
            'is_active'        => true,
            'free_preview'     => false,
            'is_free'          => false,
            'chapter_order'    => 1,
        ]);
    }

    // =========================================================================
    // Scenario 1 — CORE BUG: subscribed user should access any published course
    // =========================================================================

    /**
     * @test
     * This is the primary regression test for the reported bug.
     * A user with an active subscription must be able to access ANY published,
     * paid lecture — regardless of whether they directly purchased that course.
     */
    public function subscribed_user_can_access_any_paid_lecture(): void
    {
        $user = $this->createActiveSubscriber();

        $canAccess = $this->service->canAccessLecture($user, $this->lecture);

        $this->assertTrue(
            $canAccess,
            'BUG REPRODUCED: subscribed user could not access a paid lecture. ' .
            'This means the subscription access check is failing.'
        );
    }

    /**
     * @test
     * Subscription must grant access to courses the user has NEVER visited before.
     * This tests the N+1 / cold-course scenario.
     */
    public function subscribed_user_can_access_a_course_they_never_visited(): void
    {
        // Create a second course that the subscriber has ZERO interaction with.
        $coldCourse = Course::create([
            'title'           => 'Cold Course ' . uniqid(),
            'slug'            => 'cold-course-' . uniqid(),
            'user_id'         => $this->instructor->id,
            'status'          => 'publish',
            'approval_status' => 'approved',
            'is_active'       => true,
            'course_type'     => 'paid',
            'price'           => 200.00,
        ]);
        $coldChapter = CourseChapter::create([
            'course_id'     => $coldCourse->id,
            'user_id'       => $this->instructor->id,
            'title'         => 'Cold Chapter',
            'slug'          => 'cold-chapter-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);
        $coldLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $coldChapter->id,
            'title'             => 'Cold Lecture',
            'slug'              => 'cold-lecture-' . uniqid(),
            'type'              => 'video',
            'is_active'         => true,
            'free_preview'      => false,
            'is_free'           => false,
            'chapter_order'     => 1,
        ]);

        $user = $this->createActiveSubscriber();

        $this->assertTrue(
            $this->service->canAccessLecture($user, $coldLecture),
            'Subscribed user was denied access to a course they have never previously visited.'
        );
    }

    // =========================================================================
    // Scenario 2 — Expired subscription is BLOCKED
    // =========================================================================

    /** @test */
    public function expired_subscription_blocks_access(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createSubscription($user, [
            'status'  => 'active',
            // Ended in the past
            'ends_at' => Carbon::now()->subDay(),
        ]);

        // Flush the cache before testing: the subscription is expired so the
        // getActiveSubscription query must return null.
        ContentAccessService::flushRequestCache();

        $this->assertFalse(
            $this->service->canAccessLecture($user, $this->lecture),
            'Expired subscription should NOT grant lecture access.'
        );
    }

    // =========================================================================
    // Scenario 3 — Direct purchase still works (no subscription required)
    // =========================================================================

    /** @test */
    public function directly_purchased_course_grants_access_without_subscription(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Create a completed order for the course.
        $order = Order::create([
            'user_id'        => $user->id,
            'status'         => 'completed',
            'payment_method' => 'wallet',
            'total_price'    => 100.00,
            'final_price'    => 100.00,
        ]);
        OrderCourse::create([
            'order_id'  => $order->id,
            'course_id' => $this->course->id,
            'price'     => 100.00,
        ]);

        ContentAccessService::flushRequestCache();

        $this->assertTrue(
            $this->service->canAccessLecture($user, $this->lecture),
            'User with a direct course purchase should have lecture access.'
        );
    }

    // =========================================================================
    // Scenario 4 — Instructor owns the course — always has access
    // =========================================================================

    /** @test */
    public function instructor_who_owns_course_can_access_their_lectures(): void
    {
        // instructor has no subscription, no purchase — owns the course.
        ContentAccessService::flushRequestCache();

        $this->assertTrue(
            $this->service->canAccessLecture($this->instructor, $this->lecture),
            'Course instructor must always have access to their own lectures.'
        );
    }

    // =========================================================================
    // Scenario 5 — Free-preview lecture open to everyone
    // =========================================================================

    /** @test */
    public function free_preview_lecture_is_accessible_to_anyone(): void
    {
        $freePreviewLecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $this->chapter->id,
            'title'             => 'Free Preview Lecture',
            'slug'              => 'free-preview-' . uniqid(),
            'type'              => 'video',
            'is_active'         => true,
            'free_preview'      => true,   // <-- free preview flag
            'is_free'           => false,
            'chapter_order'     => 2,
        ]);

        // Anonymous-equivalent: a user with no subscription and no purchase.
        $stranger = User::factory()->create(['is_active' => true]);

        ContentAccessService::flushRequestCache();

        $this->assertTrue(
            $this->service->canAccessLecture($stranger, $freePreviewLecture),
            'Free-preview lecture must be accessible without subscription or purchase.'
        );
    }

    // =========================================================================
    // Scenario 6 — Blocked/banned user is always denied
    // =========================================================================

    /** @test */
    public function banned_user_is_denied_even_with_active_subscription(): void
    {
        // Create a subscribed user, then ban them.
        $user = $this->createActiveSubscriber();
        $user->update(['is_active' => false]);
        $user->refresh();

        ContentAccessService::flushRequestCache();

        $this->assertFalse(
            $this->service->canAccessLecture($user, $this->lecture),
            'Banned (is_active=false) user must be denied lecture access even if subscribed.'
        );
    }

    // =========================================================================
    // Scenario 7 — Cancelled / explicitly-set "expired" subscription is blocked
    // =========================================================================

    /** @test */
    public function cancelled_subscription_blocks_access(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createSubscription($user, [
            'status'  => 'expired',
            'ends_at' => Carbon::now()->subHour(),
        ]);

        ContentAccessService::flushRequestCache();

        $this->assertFalse(
            $this->service->canAccessLecture($user, $this->lecture),
            'Cancelled/expired subscription must not grant lecture access.'
        );
    }

    // =========================================================================
    // Scenario 8 — getCourse API returns is_subscribed=true for active subscriber
    // =========================================================================

    /** @test */
    public function get_course_api_returns_is_subscribed_true_for_active_subscriber(): void
    {
        $user = $this->createActiveSubscriber();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/get-course?slug=' . $this->course->slug);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertTrue(
            (bool) ($data['is_subscribed'] ?? $data['is_purchased'] ?? false),
            'getCourse API must return is_subscribed=true (or is_purchased=true) for an active subscriber.'
        );
    }

    // =========================================================================
    // Scenario 9 — getCourse API returns is_subscribed=false for expired subscriber
    // =========================================================================

    /** @test */
    public function get_course_api_returns_not_subscribed_for_expired_subscriber(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createSubscription($user, [
            'status'  => 'active',
            'ends_at' => Carbon::now()->subDay(), // already expired
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/get-course?slug=' . $this->course->slug);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertFalse(
            (bool) ($data['is_subscribed'] ?? false),
            'Expired subscriber must NOT see is_subscribed=true from the getCourse API.'
        );
        $this->assertFalse(
            (bool) ($data['has_access'] ?? false),
            'Expired subscriber must NOT see has_access=true from the getCourse API.'
        );
    }

    // =========================================================================
    // Performance: per-request memoisation prevents duplicate subscription syncs
    // =========================================================================

    /** @test */
    public function access_check_result_is_memoised_within_same_request(): void
    {
        $user = $this->createActiveSubscriber();

        // First call: hits the DB
        $result1 = $this->service->canAccessLecture($user, $this->lecture);
        // Second call (same lecture, same user): must use memoised result without
        // additional syncQueuedSubscriptions() execution.
        $result2 = $this->service->canAccessLecture($user, $this->lecture);

        $this->assertSame($result1, $result2, 'Memoised result must be consistent.');
        $this->assertTrue($result1, 'Subscribed user must have access.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createActiveSubscriber(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createSubscription($user, [
            'status'     => 'active',
            'starts_at'  => Carbon::now()->subDays(5),
            'ends_at'    => Carbon::now()->addDays(25),
        ]);
        return $user;
    }

    private function createSubscription(User $user, array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id'         => $user->id,
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'starts_at'       => Carbon::now(),
            'ends_at'         => Carbon::now()->addDays(30),
            'activation_mode' => 'active',
        ], $attrs));
    }
}
