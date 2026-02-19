<?php

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;

class ContentAccessService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Check if user can access a lecture.
     */
    public function canAccessLecture(User $user, CourseChapterLecture $lecture): bool
    {
        if ($lecture->is_free) {
            return true;
        }

        $course = $lecture->chapter?->course;
        if ($course !== null && $course->isFreeNow()) {
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

        return $this->subscriptionService->checkAccess($user);
    }
}
