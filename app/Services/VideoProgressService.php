<?php

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoProgressService
{
    /**
     * Minimum watch percentage to consider a video lecture completed.
     * Used consistently across the service and certificate checks.
     */
    public const COMPLETION_THRESHOLD = 100.0;

    /**
     * File types that are considered video lectures (require watch tracking).
     */
    private const VIDEO_FILE_TYPES = ['video', 'mp4', 'hls', 'stream', 'vimeo', 'youtube', 'embed', 'url'];

    public function __construct(
        private readonly FeatureFlagService $featureFlagService
    ) {}

    /**
     * Update or create video progress for a user/lecture.
     */
    public function updateProgress(
        User $user,
        CourseChapterLecture $lecture,
        int $watchedSeconds,
        int $lastPosition,
        int $totalSeconds
    ): VideoProgress {
        $existing = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

        $effectiveWatched = $existing !== null
            ? max($existing->watched_seconds, $watchedSeconds)
            : $watchedSeconds;

        $watchPercentage = $totalSeconds > 0
            ? round(($effectiveWatched / $totalSeconds) * 100, 2)
            : 0;

        $wasAlreadyCompleted = $existing !== null && $existing->is_completed;
        $isCompleted = $watchPercentage >= self::COMPLETION_THRESHOLD;
        $completedAt = $isCompleted && !$wasAlreadyCompleted
            ? now()
            : $existing?->completed_at;

        $progress = VideoProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'lecture_id' => $lecture->id,
            ],
            [
                'watched_seconds' => $effectiveWatched,
                'total_seconds' => $totalSeconds,
                'last_position' => $lastPosition,
                'watch_percentage' => $watchPercentage,
                'is_completed' => $isCompleted,
                'completed_at' => $completedAt,
            ]
        );

        // 🔄 Sync: when video is newly completed, mark lecture as completed
        // in user_curriculum_trackings so both tables stay in sync.
        if ($isCompleted && !$wasAlreadyCompleted && $lecture->course_chapter_id) {
            $this->syncCurriculumTracking($user->id, $lecture);
        }

        return $progress;
    }

    /**
     * Get progress for a user/lecture.
     *
     * @return array{watched_seconds: int, total_seconds: int, last_position: int, watch_percentage: float, is_completed: bool}|null
     */
    public function getProgress(User $user, CourseChapterLecture $lecture): ?array
    {
        $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

        if ($progress === null) {
            return null;
        }

        return [
            'watched_seconds' => $progress->watched_seconds,
            'total_seconds' => $progress->total_seconds,
            'last_position' => $progress->last_position,
            'watch_percentage' => (float) $progress->watch_percentage,
            'is_completed' => $progress->is_completed,
        ];
    }

    /**
     * Check if user can access the next lesson (sequential unlock).
     */
    public function canAccessNextLesson(User $user, CourseChapterLecture $lecture): bool
    {
        if (!$this->featureFlagService->isEnabled('video_progress_enforcement', true)) {
            return true;
        }

        $previousLecture = $this->getPreviousLecture($lecture);

        if ($previousLecture === null) {
            return true;
        }

        if (!$this->lectureHasVideo($previousLecture)) {
            return true;
        }

        $progress = VideoProgress::forUser($user->id)->forLecture($previousLecture->id)->first();

        return $progress !== null && $progress->is_completed;
    }

    /**
     * Get overall course progress percentage (0-100).
     */
    public function getCourseProgress(User $user, Course $course): float
    {
        $lectures = $this->getAllLecturesForCourse($course);

        if ($lectures->isEmpty()) {
            return 100.0;
        }

        $completedCount = 0;

        foreach ($lectures as $lecture) {
            if (!$this->lectureHasVideo($lecture)) {
                $completedCount++;
                continue;
            }

            $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();
            if ($progress !== null && $progress->is_completed) {
                $completedCount++;
            }
        }

        return round(($completedCount / $lectures->count()) * 100, 2);
    }

    /**
     * Get previous lecture in curriculum (for sequential unlock).
     */
    public function getPreviousLecture(CourseChapterLecture $lecture): ?CourseChapterLecture
    {
        $chapter = $lecture->chapter;
        if ($chapter === null) {
            return null;
        }

        $sameChapter = CourseChapterLecture::where('course_chapter_id', $chapter->id)
            ->where('chapter_order', '<', $lecture->chapter_order)
            ->orderByDesc('chapter_order')
            ->first();

        if ($sameChapter !== null) {
            return $sameChapter;
        }

        $course = $chapter->course;
        if ($course === null) {
            return null;
        }

        $previousChapter = $course->chapters()
            ->where('chapter_order', '<', $chapter->chapter_order)
            ->orderByDesc('chapter_order')
            ->first();

        if ($previousChapter === null) {
            return null;
        }

        return $previousChapter->lectures()->orderByDesc('chapter_order')->first();
    }

    /**
     * Generate progress challenge (stub for anti-cheat).
     */
    public function generateProgressChallenge(User $user, CourseChapterLecture $lecture): array
    {
        return [
            'token' => Str::random(32),
            'timestamp' => now()->timestamp,
            'expected_position' => 0,
        ];
    }

    /**
     * Validate progress challenge (stub).
     */
    public function validateProgressChallenge(User $user, CourseChapterLecture $lecture, array $response): bool
    {
        return true;
    }

    private function lectureHasVideo(CourseChapterLecture $lecture): bool
    {
        // Only types that actually stream/play video require watch-time tracking.
        // Doc-type lectures (PDFs, text) are auto-counted as completed.
        return in_array(strtolower((string) ($lecture->file_type ?? '')), self::VIDEO_FILE_TYPES, true);
    }

    /**
     * Sync a completed video lecture into user_curriculum_trackings.
     * Called automatically when video watch reaches COMPLETION_THRESHOLD.
     */
    private function syncCurriculumTracking(int $userId, CourseChapterLecture $lecture): void
    {
        try {
            UserCurriculumTracking::updateOrCreate(
                [
                    'user_id'           => $userId,
                    'course_chapter_id' => $lecture->course_chapter_id,
                    'model_id'          => $lecture->id,
                    'model_type'        => CourseChapterLecture::class,
                ],
                [
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'started_at'   => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('VideoProgressService: failed to sync curriculum tracking', [
                'user_id'    => $userId,
                'lecture_id' => $lecture->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, CourseChapterLecture>
     */
    private function getAllLecturesForCourse(Course $course): \Illuminate\Support\Collection
    {
        $lectures = collect();

        foreach ($course->chapters()->orderBy('chapter_order')->get() as $chapter) {
            $lectures = $lectures->merge(
                $chapter->lectures()->orderBy('chapter_order')->get()
            );
        }

        return $lectures;
    }
}
