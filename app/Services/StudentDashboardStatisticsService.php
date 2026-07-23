<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\CourseCertificate;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Collection;
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

    public function getDashboardStats(User $user): array
    {
        return $this->getDashboardStatsForCourseProgresses(
            $user,
            $this->getEnrolledCourseProgresses($user),
        );
    }

    /**
     * Calculate numeric dashboard statistics from one request-scoped progress snapshot.
     *
     * @param Collection<int, array{course_id: int, course: mixed, purchase_date: mixed, source: string, progress_percentage: float}> $courseProgresses
     */
    public function getDashboardStatsForCourseProgresses(User $user, Collection $courseProgresses): array
    {
        $totalCourses = $courseProgresses->count();
        $completedCourses = $courseProgresses->where('progress_percentage', '>=', 100)->count();
        $inProgressCourses = $courseProgresses
            ->filter(static fn (array $course): bool => $course['progress_percentage'] > 0 && $course['progress_percentage'] < 100)
            ->count();
        $notStartedCourses = $totalCourses - $completedCourses - $inProgressCourses;
        $averageProgress = $totalCourses > 0
            ? round($courseProgresses->avg('progress_percentage'), 2)
            : 0;
        $learningHours = $this->calculateLearningHoursForEnrolledCourses(
            (int) $user->id,
            $courseProgresses->pluck('course_id')->all(),
        );
        $certificatesCount = CourseCertificate::query()
            ->where('user_id', $user->id)
            ->active()
            ->count();
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
     * Resolves the same started-enrollment set used for all dashboard metrics.
     *
     * @return Collection<int, array{course_id: int, course: mixed, purchase_date: mixed, source: string, progress_percentage: float}>
     */
    public function getEnrolledCourseProgresses(User $user): Collection
    {
        $enrolled = $this->enrollmentService->resolveEnrolledCourses((int) $user->id);
        $subscriptionCourseIds = $enrolled
            ->where('source', 'subscription')
            ->pluck('course_id')
            ->all();
        $startedSubscriptionCourseIds = $this->getStartedSubscriptionCourseIds(
            (int) $user->id,
            $subscriptionCourseIds,
        );

        return $enrolled
            ->filter(static fn (array $item): bool => $item['source'] !== 'subscription'
                || in_array($item['course_id'], $startedSubscriptionCourseIds, true))
            ->map(function (array $item) use ($user): array {
                $item['progress_percentage'] = (float) $this->progressService
                    ->getProgressWithCache((int) $user->id, (int) $item['course_id'])
                    ->progress_percentage;

                return $item;
            })
            ->values();
    }

    private function getStartedSubscriptionCourseIds(int $userId, array $subscriptionCourseIds): array
    {
        if ($subscriptionCourseIds === []) {
            return [];
        }

        $startedViaTrackings = DB::table('user_curriculum_trackings')
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->where('user_curriculum_trackings.user_id', $userId)
            ->whereIn('course_chapters.course_id', $subscriptionCourseIds)
            ->pluck('course_chapters.course_id')
            ->all();
        $startedViaVideos = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $userId)
            ->whereIn('course_chapters.course_id', $subscriptionCourseIds)
            ->where('video_progress.watched_seconds', '>', 0)
            ->pluck('course_chapters.course_id')
            ->all();

        return array_values(array_unique([...$startedViaTrackings, ...$startedViaVideos]));
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
            Log::error('StudentDashboardStatisticsService: could not calculate learning hours', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
