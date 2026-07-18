<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $enrolled = $this->enrollmentService->resolveEnrolledCourseIds((int) $user->id);
        
        $explicitCourseIds = [];
        $subscriptionCourseIds = [];
        
        foreach ($enrolled as $item) {
            if ($item['source'] !== 'subscription') {
                $explicitCourseIds[] = $item['course_id'];
            } else {
                $subscriptionCourseIds[] = $item['course_id'];
            }
        }
        
        $explicitCourseIds = array_unique($explicitCourseIds);
        
        // Find subscription courses the user has actually started
        if (!empty($subscriptionCourseIds)) {
            $startedCourseIds = DB::table('user_curriculum_trackings')
                ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                ->where('user_curriculum_trackings.user_id', $user->id)
                ->whereIn('course_chapters.course_id', $subscriptionCourseIds)
                ->pluck('course_chapters.course_id')
                ->toArray();
                
            $explicitCourseIds = array_unique(array_merge($explicitCourseIds, $startedCourseIds));
        }
        
        $enrolledCourseIds = $explicitCourseIds;
        $totalCourses = count($enrolledCourseIds);

        if ($totalCourses === 0) {
            Log::warning('StudentDashboardStatisticsService: no enrolled courses found for user', [
                'user_id' => $user->id,
            ]);
        }

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

        try {
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
        } catch (\Throwable $e) {
            // The video_progress table may not exist yet (pending migration).
            // Return 0 rather than crashing the entire dashboard stats response.
            Log::warning('StudentDashboardStatisticsService: could not calculate learning hours', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            return 0.0;
        }
    }
}
