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
     * Resolves all accessible/enrolled course progresses for the user.
     *
     * @return Collection<int, array{course_id: int, course: mixed, enrolled_at: mixed, access_started_at: mixed, purchase_date: mixed, source: string, progress_percentage: float, completed_items: int, total_items: int, watched_seconds: int, is_started: bool, learning_status: string}>
     */
    public function getEnrolledCourseProgresses(User $user): Collection
    {
        $enrolled = $this->enrollmentService->resolveEnrolledCourses((int) $user->id);
        if ($enrolled->isEmpty()) {
            return collect();
        }

        $courseIds = $enrolled->pluck('course_id')->all();

        // Batch load video progress for started check
        $videoWatchedMap = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $user->id)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->where('video_progress.watched_seconds', '>', 0)
            ->groupBy('course_chapters.course_id')
            ->selectRaw('course_chapters.course_id, SUM(video_progress.watched_seconds) as total_watched')
            ->pluck('total_watched', 'course_id');

        $videoStartedItemsMap = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $user->id)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->where('course_chapters.is_active', true)
            ->where('course_chapter_lectures.is_active', true)
            ->where('video_progress.watched_seconds', '>', 0)
            ->groupBy('course_chapters.course_id')
            ->selectRaw('course_chapters.course_id, COUNT(DISTINCT video_progress.lecture_id) as started_items')
            ->pluck('started_items', 'course_id');

        return $enrolled
            ->map(function (array $item) use ($user, $videoWatchedMap, $videoStartedItemsMap): array {
                $courseId = (int) $item['course_id'];
                $progressObj = $this->progressService->getProgressWithCache((int) $user->id, $courseId);
                $progressPercentage = (float) $progressObj->progress_percentage;
                $completedItems = (int) ($progressObj->completed_items ?? 0);
                $totalItems = (int) ($progressObj->total_items ?? 0);
                $watchedSeconds = (int) ($videoWatchedMap->get($courseId) ?? 0);
                $startedItems = max($completedItems, (int) ($videoStartedItemsMap->get($courseId) ?? 0));
                $isStarted = $progressPercentage > 0 || $watchedSeconds > 0 || $completedItems > 0;
                $learningStatus = $this->progressService->resolveLearningStatus($progressObj, $watchedSeconds);

                $item['progress_percentage'] = $progressPercentage;
                $item['completed_items'] = $completedItems;
                $item['total_items'] = $totalItems;
                $item['watched_seconds'] = $watchedSeconds;
                $item['started_items'] = $startedItems;
                $item['is_started'] = $isStarted;
                $item['learning_status'] = $learningStatus;

                return $item;
            })
            ->values();
    }

    /**
     * Calculate numeric dashboard statistics from one request-scoped progress snapshot.
     *
     * @param Collection<int, array{course_id: int, course: mixed, purchase_date: mixed, source: string, progress_percentage: float, watched_seconds?: int, is_started?: bool}> $courseProgresses
     */
    public function getDashboardStatsForCourseProgresses(User $user, Collection $courseProgresses): array
    {
        $totalCourses = $courseProgresses->count();
        $completedCourses = $courseProgresses->where('learning_status', 'completed')->count();
        $inProgressCourses = $courseProgresses->where('learning_status', 'in_progress')->count();
        $notStartedCourses = max(0, $totalCourses - $completedCourses - $inProgressCourses);
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
            'open_courses'        => max(0, $totalCourses - $completedCourses),
            'certificates'        => $certificatesCount,
            'average_progress'    => $averageProgress,
            'learning_hours'      => $learningHours,
            'wishlist'            => $wishlistCount,
        ];
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
