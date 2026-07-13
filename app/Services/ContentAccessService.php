<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\OrderCourse;
use App\Models\User;

class ContentAccessService
{
    /**
     * Per-request lecture access cache: prevents repeated DB hits for the same
     * user/lecture pair when serving multiple HLS segments in one request.
     *
     * @var array<string, bool>
     */
    private static array $lectureAccessCache = [];

    /**
     * Per-request subscription access cache: prevents syncQueuedSubscriptions()
     * from running on every .ts segment fetch.
     *
     * @var array<int, bool>
     */
    private static array $subscriptionAccessCache = [];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * Check if user can access a lecture.
     *
     * Results are memoised for the duration of the HTTP request so that HLS
     * segment serving (which calls this per .ts file) doesn't re-run the full
     * subscription sync on every segment.
     */
    public function canAccessLecture(User $user, CourseChapterLecture $lecture): bool
    {
        // Blocked / banned users are denied immediately, regardless of subscription.
        if (!(bool) $user->is_active) {
            return false;
        }

        if ($lecture->is_free || $lecture->free_preview) {
            return true;
        }

        $course = $lecture->chapter?->course;
        if ($course === null) {
            return false;
        }

        // Per-request memoisation key
        $cacheKey = "{$user->id}:{$lecture->id}";
        if (array_key_exists($cacheKey, self::$lectureAccessCache)) {
            return self::$lectureAccessCache[$cacheKey];
        }

        $result = $this->resolveAccess($user, $course, $lecture);

        self::$lectureAccessCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Check if user can access a course (used by listing pages).
     */
    public function canAccessCourse(User $user, Course $course): bool
    {
        // Blocked / banned users are denied immediately.
        if (!(bool) $user->is_active) {
            return false;
        }

        if ($course->isFreeNow()) {
            return true;
        }

        if ((int) $course->user_id === (int) $user->id) {
            return true;
        }

        if ($this->hasPurchasedCourse($user, $course)) {
            return true;
        }

        return $this->checkSubscriptionAccess($user);
    }

    /**
     * Flush the per-request memoisation caches (useful in tests).
     */
    public static function flushRequestCache(): void
    {
        self::$lectureAccessCache    = [];
        self::$subscriptionAccessCache = [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveAccess(User $user, Course $course, CourseChapterLecture $lecture): bool
    {
        if ($course->isFreeNow()) {
            return true;
        }

        if ((int) $course->user_id === (int) $user->id) {
            return true;
        }

        if ($this->hasPurchasedCourse($user, $course)) {
            return true;
        }

        return $this->checkSubscriptionAccess($user);
    }

    /**
     * Memoised wrapper around SubscriptionService::checkAccess so that
     * syncQueuedSubscriptions() is only called once per user per request.
     */
    private function checkSubscriptionAccess(User $user): bool
    {
        if (array_key_exists($user->id, self::$subscriptionAccessCache)) {
            return self::$subscriptionAccessCache[$user->id];
        }

        $result = $this->subscriptionService->checkAccess($user);
        self::$subscriptionAccessCache[$user->id] = $result;

        return $result;
    }

    private function hasPurchasedCourse(User $user, Course $course): bool
    {
        $enrollmentService = app(\App\Services\UserEnrollmentService::class);
        $enrolledCourses   = $enrollmentService->resolveEnrolledCourses((int) $user->id);

        return $enrolledCourses->contains('course_id', $course->id);
    }
}
