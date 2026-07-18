<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all Student Dashboard Statistics.
 * Eliminates contradictory metrics by enforcing strict business rules:
 * - Only counts courses the user is actively enrolled in.
 * - Only counts published, active courses.
 * - Progress metrics strictly adhere to the enrolled course list.
 */
final class StudentDashboardStatisticsService
{
    public function __construct(
        private readonly UserEnrollmentService $enrollmentService,
        private readonly CourseProgressService $progressService
    ) {}

    /**
     * Get the completely certified and deterministic dashboard stats.
     */
    public function getDashboardStats(User $user): array
    {
        // 1. Resolve mathematically correct accessible courses
        $enrolled = $this->enrollmentService->resolveEnrolledCourses((int) $user->id);
        $enrolledCourseIds = $enrolled->pluck('course_id')->toArray();
        $totalCourses = count($enrolledCourseIds);

        // 2. Initialize progress counters
        $completedCourses = 0;
        $inProgressCourses = 0;
        $notStartedCourses = 0;
        $totalProgressPercentage = 0;

        foreach ($enrolledCourseIds as $courseId) {
            $progress = $this->calculateDeterministicProgress($user->id, $courseId);
            $totalProgressPercentage += $progress;

            if ($progress === 100.0) {
                $completedCourses++;
            } elseif ($progress > 0) {
                $inProgressCourses++;
            } else {
                $notStartedCourses++;
            }
        }

        $averageProgress = $totalCourses > 0 
            ? round($totalProgressPercentage / $totalCourses, 2) 
            : 0;

        // 3. Learning Hours (Only for accessible, enrolled courses)
        $learningHours = $this->calculateLearningHoursForEnrolledCourses($user->id, $enrolledCourseIds);

        // 4. Certificates (Only actual generated certificates)
        $certificatesCount = CourseCertificate::where('user_id', $user->id)
            // Ensure we only count certificates for currently valid/published courses (if business rules dictate, but generally a certificate is permanent. We will count all generated certificates to match the UI `my-certificates` list.)
            ->count();

        // 5. Wishlist
        $wishlistCount = Wishlist::where('user_id', $user->id)->count();

        return [
            'total_courses'       => $totalCourses,
            'not_started_courses' => $notStartedCourses,
            'in_progress_courses' => $inProgressCourses,
            'completed_courses'   => $completedCourses,
            'open_courses'        => $totalCourses - $completedCourses,
            'certificates'        => $certificatesCount,
            'average_progress'    => $averageProgress,
            'learning_hours'      => $learningHours,
            'wishlist'            => $wishlistCount,
        ];
    }

    /**
     * Ensures progress is retrieved securely and deterministic.
     */
    private function calculateDeterministicProgress(int $userId, int $courseId): float
    {
        return (float) $this->progressService
            ->getProgressWithCache($userId, $courseId)
            ->progress_percentage;
    }

    /**
     * Calculate learning hours ONLY for lectures inside the user's valid enrolled courses.
     * Uses cumulative watched time (watched_seconds) from video_progress for accuracy.
     */
    private function calculateLearningHoursForEnrolledCourses(int $userId, array $validCourseIds): float
    {
        if (empty($validCourseIds)) {
            return 0.0;
        }

        // Sum cumulative watched seconds from video_progress for active lectures
        // inside the active chapters of the valid enrolled courses.
        $totalSeconds = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $userId)
            ->whereIn('course_chapters.course_id', $validCourseIds)
            // Ensure the lecture and chapter are active
            ->where('course_chapters.is_active', 1)
            ->where('course_chapter_lectures.is_active', 1)
            ->sum('video_progress.watched_seconds');

        return round(($totalSeconds ?? 0) / 3600, 2);
    }
}
