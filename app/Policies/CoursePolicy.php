<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Policy for Course authorization.
 *
 * Admins can ONLY approve/reject courses.
 * Only course owners and approved team members can modify course content.
 */
final readonly class CoursePolicy
{
    /**
     * Determine if the user can modify the course (update, delete, manage content).
     *
     * Only course owner or approved team members can modify.
     * Admins are NOT allowed to modify course content.
     */
    public function modify(User $user, Course $course): bool
    {
        // Course owner can always modify
        if ($course->user_id === $user->id) {
            return true;
        }

        // Check if user is an approved team member of the course owner
        return $this->isApprovedTeamMember($user, $course);
    }

    /**
     * Determine if the user can approve or reject the course.
     *
     * Only admins can approve/reject courses.
     */
    public function approve(User $user, Course $course): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if the user can view the course (for admin panel).
     *
     * Admins, course owners, and team members can view.
     */
    public function view(User $user, Course $course): bool
    {
        // Admin can view all courses
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Course owner can view
        if ($course->user_id === $user->id) {
            return true;
        }

        // Approved team member can view
        return $this->isApprovedTeamMember($user, $course);
    }

    /**
     * Check if the user is an approved team member of the course owner's instructor.
     */
    private function isApprovedTeamMember(User $user, Course $course): bool
    {
        // Case 1: User is a team member of the course owner's instructor
        $courseOwnerInstructor = Instructor::where('user_id', $course->user_id)->first();

        if ($courseOwnerInstructor) {
            $isTeamMember = TeamMember::where('instructor_id', $courseOwnerInstructor->id)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if ($isTeamMember) {
                return true;
            }
        }

        // Case 2: User is an instructor and course owner is their approved team member
        $userInstructor = Instructor::where('user_id', $user->id)->first();

        if ($userInstructor) {
            return TeamMember::where('instructor_id', $userInstructor->id)
                ->where('user_id', $course->user_id)
                ->where('status', 'approved')
                ->exists();
        }

        return false;
    }
}
