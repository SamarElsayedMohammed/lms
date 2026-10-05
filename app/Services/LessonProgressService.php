<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LessonProgressService
{
    /**
     * Minimum watch percentage to consider a lesson completed.
     */
    public const COMPLETION_THRESHOLD = 95.0;

    public function __construct(
        private readonly ContentAccessService $contentAccessService,
        private readonly CertificateService $certificateService,
    ) {}

    /**
     * Check if a student can access a given lesson (Server-Authoritative Sequential Locking).
     * The first lesson is always unlocked.
     */
    public function canAccessLesson(User $user, CourseChapterLecture $lesson): bool
    {
        $chapter = $lesson->chapter;
        $course = $chapter?->course;

        // If course doesn't enforce sequential access, all lessons are unlocked
        if ($course && !($course->sequential_access ?? true)) {
            return true;
        }

        // Admins, supervisors, instructors, and course owners bypass sequential locks
        if ($user->hasRole(['admin', 'instructor', 'supervisor', 'Super Admin'])
            || ($course && (int) $course->user_id === (int) $user->id)) {
            return true;
        }

        // Free preview lessons are always accessible
        if ((bool) ($lesson->free_preview ?? false) || (bool) ($lesson->is_free ?? false)) {
            return true;
        }

        // If this lesson is already completed, allow replay access
        if ($this->isLessonCompleted($user->id, (int) $lesson->id)) {
            return true;
        }

        $allPriorLessons = $this->getAllPriorLecturesInCourse($lesson);
        if ($allPriorLessons->isEmpty()) {
            return true; // First lesson in course is always unlocked
        }

        $priorLessonIds = $allPriorLessons->pluck('id')->all();

        // Check completion across lesson_progress, video_progress, and user_curriculum_trackings
        $completedLessonIds = LessonProgress::where('user_id', $user->id)
            ->whereIn('lesson_id', $priorLessonIds)
            ->where('is_completed', true)
            ->pluck('lesson_id')
            ->flip()
            ->all();

        $completedVideoIds = VideoProgress::where('user_id', $user->id)
            ->whereIn('lecture_id', $priorLessonIds)
            ->where('is_completed', true)
            ->pluck('lecture_id')
            ->flip()
            ->all();

        $completedTrackingIds = UserCurriculumTracking::where('user_id', $user->id)
            ->whereIn('model_id', $priorLessonIds)
            ->where('status', 'completed')
            ->pluck('model_id')
            ->flip()
            ->all();

        foreach ($allPriorLessons as $prior) {
            // Free preview lessons don't block subsequent lessons if skipped
            if ((bool) ($prior->free_preview ?? false) || (bool) ($prior->is_free ?? false)) {
                continue;
            }

            $isDone = isset($completedLessonIds[$prior->id])
                || isset($completedVideoIds[$prior->id])
                || isset($completedTrackingIds[$prior->id]);

            if (!$isDone) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get or initialize progress record for a user and lesson.
     */
    public function getLessonProgress(int $userId, CourseChapterLecture $lesson): LessonProgress
    {
        $courseId = (int) ($lesson->chapter?->course_id ?? 0);

        /** @var LessonProgress|null $existing */
        $existing = LessonProgress::where('user_id', $userId)
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Fallback: check legacy video_progress for historical records
        $legacy = VideoProgress::where('user_id', $userId)
            ->where('lecture_id', $lesson->id)
            ->first();

        if ($legacy !== null) {
            return LessonProgress::create([
                'user_id'               => $userId,
                'lesson_id'             => $lesson->id,
                'course_id'             => $courseId,
                'last_position_seconds' => (int) $legacy->last_position,
                'max_position_seconds'  => (int) $legacy->last_position,
                'watched_seconds'       => (int) $legacy->watched_seconds,
                'percent'               => (float) $legacy->watch_percentage,
                'is_completed'          => (bool) $legacy->is_completed,
                'completed_at'          => $legacy->completed_at,
                'last_heartbeat_at'     => $legacy->updated_at,
            ]);
        }

        return new LessonProgress([
            'user_id'               => $userId,
            'lesson_id'             => $lesson->id,
            'course_id'             => $courseId,
            'last_position_seconds' => 0,
            'max_position_seconds'  => 0,
            'watched_seconds'       => 0,
            'percent'               => 0.00,
            'is_completed'          => false,
            'completed_at'          => null,
            'last_heartbeat_at'     => null,
        ]);
    }

    /**
     * Merge a new interval [start, end] into an existing list of intervals.
     *
     * @param array<int, array{0: int, 1: int}> $existingIntervals
     * @param int $start
     * @param int $end
     * @return array<int, array{0: int, 1: int}>
     */
    public function mergeInterval(array $existingIntervals, int $start, int $end): array
    {
        if ($start >= $end) {
            return $this->normalizeIntervals($existingIntervals);
        }

        $intervals = $existingIntervals;
        $intervals[] = [$start, $end];

        return $this->normalizeIntervals($intervals);
    }

    /**
     * Normalize, sort, and merge overlapping or contiguous intervals.
     *
     * @param array<int, array{0: int, 1: int}> $intervals
     * @return array<int, array{0: int, 1: int}>
     */
    public function normalizeIntervals(array $intervals): array
    {
        if (empty($intervals)) {
            return [];
        }

        $valid = [];
        foreach ($intervals as $interval) {
            if (is_array($interval) && count($interval) >= 2) {
                $s = (int) $interval[0];
                $e = (int) $interval[1];
                if ($e > $s) {
                    $valid[] = [$s, $e];
                }
            }
        }

        if (empty($valid)) {
            return [];
        }

        usort($valid, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $merged = [];
        $current = $valid[0];

        for ($i = 1, $count = count($valid); $i < $count; $i++) {
            $next = $valid[$i];
            if ($current[1] >= $next[0]) {
                $current[1] = max($current[1], $next[1]);
            } else {
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;

        return $merged;
    }

    /**
     * Calculate total unique watched seconds from merged intervals bounded by duration.
     *
     * @param array<int, array{0: int, 1: int}> $intervals
     * @param int $maxDuration
     * @return int
     */
    public function calculateIntervalCoverage(array $intervals, int $maxDuration): int
    {
        $normalized = $this->normalizeIntervals($intervals);
        $totalSeconds = 0;

        foreach ($normalized as [$start, $end]) {
            $clampedStart = max(0, min($start, $maxDuration));
            $clampedEnd = max(0, min($end, $maxDuration));
            if ($clampedEnd > $clampedStart) {
                $totalSeconds += ($clampedEnd - $clampedStart);
            }
        }

        return min($maxDuration, $totalSeconds);
    }

    /**
     * Record video watch heartbeat (Idempotent, Server-Authoritative, Merged Watched-Intervals).
     */
    public function recordHeartbeat(
        User $user,
        CourseChapterLecture $lesson,
        float $currentTime,
        float $duration,
        string $event,
        array $metadata = []
    ): LessonProgress {
        $course = $lesson->chapter?->course;
        $courseId = (int) ($course?->id ?? 0);

        // 1. Sanitize duration and backfill lesson duration in DB if missing/0
        $durationSeconds = max(1, (int) round($duration));
        $dbDuration = (int) ($lesson->duration_seconds ?? $lesson->duration ?? 0);
        if ($dbDuration <= 0 && $durationSeconds > 0) {
            $lesson->updateQuietly([
                'duration_seconds' => $durationSeconds,
                'hours'            => (int) floor($durationSeconds / 3600),
                'minutes'          => (int) floor(($durationSeconds % 3600) / 60),
                'seconds'          => (int) ($durationSeconds % 60),
            ]);
            $dbDuration = $durationSeconds;
        }
        $canonicalDuration = $dbDuration > 0 ? $dbDuration : $durationSeconds;

        // 2. Fetch existing progress record
        /** @var LessonProgress|null $existing */
        $existing = LessonProgress::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $now = now();
        $safeCurrentTime = max(0, min($canonicalDuration, (int) round($currentTime)));

        // 3. Merged Interval Anti-Cheat
        $existingIntervals = is_array($existing?->watched_intervals) ? $existing->watched_intervals : [];
        if (empty($existingIntervals) && $existing !== null && $existing->watched_seconds > 0) {
            $existingIntervals = [[0, min($canonicalDuration, (int) $existing->watched_seconds)]];
        }

        $fromPos = $existing !== null ? (int) $existing->last_position_seconds : 0;
        $toPos = $safeCurrentTime;
        $rawDelta = $toPos - $fromPos;

        $elapsedSeconds = ($existing !== null && $existing->last_heartbeat_at !== null)
            ? max(0, (int) abs($now->diffInSeconds($existing->last_heartbeat_at)))
            : null;

        $speedTolerance = (float) config('learning.speed_tolerance_multiplier', 2.2);
        $latencyTol = (int) config('learning.network_latency_tolerance_seconds', 4);
        $maxWindow = (int) config('learning.max_heartbeat_window_seconds', 15);

        // Max allowed forward progress increment in a single heartbeat window
        if ($elapsedSeconds === null) {
            // First heartbeat ever: allow up to 30s initial watched credit (covers 15s window at 2.0x playback)
            $maxAllowedIncrement = 30;
        } elseif ($elapsedSeconds > $maxWindow) {
            // Player was paused, backgrounded, or idle during gap:
            // active playback before this heartbeat could only be standard single heartbeat interval (5s)
            $clampedElapsed = 5;
            $maxAllowedIncrement = max(1, (int) round(($clampedElapsed * $speedTolerance) + $latencyTol));
        } else {
            // Normal uninterrupted playback between consecutive heartbeats
            $clampedElapsed = $elapsedSeconds;
            $maxAllowedIncrement = max(1, (int) round(($clampedElapsed * $speedTolerance) + $latencyTol));
        }

        $intervals = $existingIntervals;

        if ($event === 'seeked') {
            // Explicit seek: position updates, but no watch credit accrues for the jump itself
            // Keep existing intervals
        } elseif ($rawDelta > 0) {
            if ($rawDelta > $maxAllowedIncrement) {
                // Forward jump exceeds playback tolerance (cheat attempt, idle seek, or skipped timeline)
                Log::warning('Lesson heartbeat capped: CHEAT_CAPPED', [
                    'user_id'         => $user->id,
                    'lesson_id'       => (int) $lesson->id,
                    'raw_current_time'=> $currentTime,
                    'from_pos'        => $fromPos,
                    'to_pos'          => $toPos,
                    'raw_delta'       => $rawDelta,
                    'max_allowed'     => $maxAllowedIncrement,
                    'elapsed_seconds' => $elapsedSeconds,
                ]);
                // Jump is capped: do not credit skipped interval
            } else {
                // Legitimate playback segment within tolerance
                $intervals = $this->mergeInterval($intervals, $fromPos, $toPos);
            }
        }

        // Compute coverage from merged unique intervals
        $intervalCoverage = $this->calculateIntervalCoverage($intervals, $canonicalDuration);

        // 4. Monotonic guards: progress and max_position never regress
        $prevMaxPosition = $existing !== null ? (int) $existing->max_position_seconds : 0;
        $prevWatched = $existing !== null ? (int) $existing->watched_seconds : 0;
        $prevPercent = $existing !== null ? (float) $existing->percent : 0.00;
        $wasAlreadyCompleted = $existing !== null && (bool) $existing->is_completed;

        $maxPosition = max($prevMaxPosition, $safeCurrentTime);
        $watchedSeconds = max($prevWatched, $intervalCoverage);

        $calculatedPercent = round(($watchedSeconds / max(1, $canonicalDuration)) * 100, 2);
        $percent = max($prevPercent, min(100.0, $calculatedPercent));

        // 5. Completion Logic: Strictly server-validated watched coverage >= threshold
        $threshold = (float) ($course?->completion_threshold ?? config('learning.completion_threshold', 95.0));
        $isCompletedByThreshold = ($percent >= $threshold);

        $isCompleted = $wasAlreadyCompleted || $isCompletedByThreshold;

        if ($isCompleted) {
            $percent = 100.00;
            $watchedSeconds = max($watchedSeconds, $canonicalDuration);
        }

        $completedAt = $isCompleted
            ? ($wasAlreadyCompleted && $existing?->completed_at ? $existing->completed_at : $now)
            : null;

        // 6. DB Transaction for atomic update and synchronization
        return DB::transaction(function () use (
            $user,
            $lesson,
            $courseId,
            $safeCurrentTime,
            $maxPosition,
            $watchedSeconds,
            $percent,
            $intervals,
            $isCompleted,
            $wasAlreadyCompleted,
            $completedAt,
            $now,
            $canonicalDuration,
            $metadata,
            $event
        ): LessonProgress {
            $progress = LessonProgress::updateOrCreate(
                [
                    'user_id'   => $user->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'course_id'             => $courseId,
                    'last_position_seconds' => $safeCurrentTime,
                    'max_position_seconds'  => $maxPosition,
                    'watched_seconds'       => $watchedSeconds,
                    'percent'               => $percent,
                    'watched_intervals'     => $intervals,
                    'is_completed'          => $isCompleted,
                    'completed_at'          => $completedAt,
                    'last_heartbeat_at'     => $now,
                ]
            );

            // Synchronize legacy video_progress for backwards compatibility with mobile and stats
            VideoProgress::updateOrCreate(
                [
                    'user_id'    => $user->id,
                    'lecture_id' => $lesson->id,
                ],
                [
                    'watched_seconds'  => $watchedSeconds,
                    'total_seconds'    => $canonicalDuration,
                    'last_position'    => $safeCurrentTime,
                    'watch_percentage' => $percent,
                    'is_completed'     => $isCompleted,
                    'completed_at'     => $completedAt,
                    'session_id'       => $metadata['session_id'] ?? null,
                    'device'           => $metadata['device'] ?? null,
                    'browser'          => $metadata['browser'] ?? null,
                    'progress_state'   => $event,
                ]
            );

            // Synchronize user_curriculum_trackings if newly completed
            if ($isCompleted && $lesson->course_chapter_id) {
                UserCurriculumTracking::updateOrCreate(
                    [
                        'user_id'           => $user->id,
                        'course_chapter_id' => $lesson->course_chapter_id,
                        'model_id'          => $lesson->id,
                        'model_type'        => CourseChapterLecture::class,
                    ],
                    [
                        'status'       => 'completed',
                        'completed_at' => $completedAt,
                    ]
                );
            }

            // Recalculate course progress and handle certificate issuance upon newly completed lesson
            if ($isCompleted && !$wasAlreadyCompleted && $courseId > 0) {
                $this->recomputeCourseProgressAndCheckCertificate($user, $courseId);
            }

            return $progress;
        });
    }

    /**
     * Get full course progress breakdown with server-authoritative sequential lock state.
     */
    public function getCourseProgressOverview(User $user, Course $course): array
    {
        $chapters = CourseChapter::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->orderBy('chapter_order')
            ->with([
                'lectures' => static fn ($q) => $q->where('is_active', true)->orderBy('chapter_order'),
            ])
            ->get();

        $allLessons = $chapters->flatMap->lectures->values();
        $totalLessons = $allLessons->count();

        $lessonIds = $allLessons->pluck('id')->all();

        // Eager load all progress for user in this course
        $progressMap = LessonProgress::where('user_id', $user->id)
            ->whereIn('lesson_id', $lessonIds)
            ->get()
            ->keyBy('lesson_id');

        $legacyProgressMap = VideoProgress::where('user_id', $user->id)
            ->whereIn('lecture_id', $lessonIds)
            ->get()
            ->keyBy('lecture_id');

        $trackingMap = UserCurriculumTracking::where('user_id', $user->id)
            ->whereIn('model_id', $lessonIds)
            ->where('status', 'completed')
            ->get()
            ->keyBy('model_id');

        $completedLessonsCount = 0;
        $lessonsData = [];
        $nextLessonId = null;
        $isPriorLessonCompleted = true; // First lesson is always unlocked

        foreach ($allLessons as $index => $lesson) {
            /** @var LessonProgress|null $prog */
            $prog = $progressMap->get($lesson->id);
            /** @var VideoProgress|null $legacy */
            $legacy = $legacyProgressMap->get($lesson->id);
            $isTracked = $trackingMap->has($lesson->id);

            $isCompleted = (bool) ($prog?->is_completed ?? $legacy?->is_completed ?? $isTracked ?? false);
            $percent = (float) ($prog?->percent ?? $legacy?->watch_percentage ?? ($isCompleted ? 100.0 : 0.0));
            $lastPosition = (int) ($prog?->last_position_seconds ?? $legacy?->last_position ?? 0);

            $isFreePreview = (bool) ($lesson->free_preview ?? false) || (bool) ($lesson->is_free ?? false);
            $isCourseSequential = (bool) ($course->sequential_access ?? true);

            // Lock logic: first lesson is unlocked; subsequent lessons require prior completed
            $isLocked = false;
            if ($isCourseSequential && !$isFreePreview && !$isCompleted) {
                $isLocked = !$isPriorLessonCompleted;
            }

            if ($isCompleted) {
                $completedLessonsCount++;
                $status = 'completed';
            } elseif ($isLocked) {
                $status = 'locked';
            } elseif ($percent > 0 || $lastPosition > 0) {
                $status = 'in_progress';
            } else {
                $status = 'not_started';
            }

            if (!$nextLessonId && !$isCompleted && !$isLocked) {
                $nextLessonId = $lesson->id;
            }

            $lessonsData[] = [
                'lesson_id'             => $lesson->id,
                'lecture_id'            => $lesson->id,
                'title'                 => $lesson->title,
                'chapter_id'            => $lesson->course_chapter_id,
                'status'                => $status,
                'percent'               => $percent,
                'watch_percentage'      => $percent,
                'last_position'         => $lastPosition,
                'last_position_seconds' => $lastPosition,
                'is_completed'          => $isCompleted,
                'is_locked'             => $isLocked,
                'duration_seconds'      => (int) ($lesson->duration_seconds ?? $lesson->duration ?? 0),
                'free_preview'          => $isFreePreview,
            ];

            // Update prior completed pointer for sequential progression
            $isPriorLessonCompleted = $isCompleted;
        }

        $overallPercent = $totalLessons > 0
            ? round(($completedLessonsCount / $totalLessons) * 100, 2)
            : 0.0;

        return [
            'course_id'          => $course->id,
            'overall_percent'    => (float) $overallPercent,
            'overall_percentage' => (float) $overallPercent, // Compatibility key
            'completed_lessons'  => $completedLessonsCount,
            'completed_items'    => $completedLessonsCount, // Compatibility key
            'total_lessons'      => $totalLessons,
            'total_items'        => $totalLessons,         // Compatibility key
            'next_lesson_id'     => $nextLessonId,
            'next_item'          => $nextLessonId ? ['item_id' => $nextLessonId, 'type' => 'lecture'] : null,
            'is_completed'       => $totalLessons > 0 && $completedLessonsCount >= $totalLessons,
            'lessons'            => $lessonsData,
        ];
    }

    /**
     * Recompute course progress inside DB transaction, update UserCourseProgress,
     * and issue certificate idempotently if course reached 100%.
     */
    public function recomputeCourseProgressAndCheckCertificate(User $user, int $courseId): void
    {
        $course = Course::find($courseId);
        if (!$course) {
            return;
        }

        $overview = $this->getCourseProgressOverview($user, $course);
        $overallPercent = (float) $overview['overall_percent'];
        $isCompleted = (bool) $overview['is_completed'];
        $status = $isCompleted ? 'completed' : ($overallPercent > 0 ? 'in_progress' : 'not_started');

        // Update UserCourseProgress aggregate
        UserCourseProgress::updateOrCreate(
            [
                'user_id'   => $user->id,
                'course_id' => $courseId,
            ],
            [
                'completed_items'     => $overview['completed_lessons'],
                'total_items'         => $overview['total_lessons'],
                'progress_percentage' => $overallPercent,
                'status'              => $status,
                'last_accessed_at'    => now(),
            ]
        );

        // Invalidate cached course progress for this user
        \Illuminate\Support\Facades\Cache::forget("user:{$user->id}:course:{$courseId}:progress");
        \Illuminate\Support\Facades\Cache::forget("user:{$user->id}:all-progress");

        // Issue completion certificate exactly once if enabled
        if ($isCompleted && (bool) $course->certificate_enabled) {
            try {
                $this->certificateService->issueCertificate($user->id, $courseId, [
                    'issuance_source' => 'automatic',
                ]);
            } catch (\Throwable $e) {
                Log::error('Certificate auto-issuance error upon course completion', [
                    'user_id'   => $user->id,
                    'course_id' => $courseId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if a lesson is completed across tables.
     */
    private function isLessonCompleted(int $userId, int $lessonId): bool
    {
        $lp = LessonProgress::where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->where('is_completed', true)
            ->exists();

        if ($lp) {
            return true;
        }

        $vp = VideoProgress::where('user_id', $userId)
            ->where('lecture_id', $lessonId)
            ->where('is_completed', true)
            ->exists();

        if ($vp) {
            return true;
        }

        return UserCurriculumTracking::where('user_id', $userId)
            ->where('model_id', $lessonId)
            ->where('status', 'completed')
            ->exists();
    }

    /**
     * Retrieve all prior active lectures in chronological course order.
     */
    private function getAllPriorLecturesInCourse(CourseChapterLecture $lesson): Collection
    {
        $chapter = $lesson->chapter;
        if (!$chapter || !$chapter->course_id) {
            return collect();
        }

        $allLectures = CourseChapterLecture::query()
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $chapter->course_id)
            ->where('course_chapters.is_active', true)
            ->where('course_chapter_lectures.is_active', true)
            ->orderBy('course_chapters.chapter_order')
            ->orderBy('course_chapter_lectures.chapter_order')
            ->select('course_chapter_lectures.*')
            ->get();

        $currentIndex = $allLectures->search(fn ($l) => (int) $l->id === (int) $lesson->id);

        if ($currentIndex === false || $currentIndex === 0) {
            return collect();
        }

        return $allLectures->slice(0, $currentIndex)->values();
    }
}
