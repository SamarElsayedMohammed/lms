<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\OrderCourse;
use App\Models\User;

class ContentAccessService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * Check if user can access a lecture.
     */
    public function canAccessLecture(User $user, CourseChapterLecture $lecture): bool
    {
        if ($lecture->is_free || $lecture->free_preview) {
            return true;
        }

        $course = $lecture->chapter?->course;
        if ($course === null) {
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

        return $this->subscriptionService->checkAccess($user);
    }

    /**
     * Check if user can access a course.
     */
    public function canAccessCourse(User $user, Course $course): bool
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

        return $this->subscriptionService->checkAccess($user);
    }

    private function hasPurchasedCourse(User $user, Course $course): bool
    {
        return OrderCourse::query()
            ->where('course_id', $course->id)
            ->whereHas('order', static function ($query) use ($user): void {
                $query->where('user_id', $user->id)->where('status', 'completed');
            })
            ->exists();
    }
}
