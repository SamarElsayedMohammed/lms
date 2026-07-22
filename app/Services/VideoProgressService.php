<?php

namespace App\Services;

use App\Events\CurriculumItemCompleted;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoProgressService
{
    /**
     * Minimum watch percentage to consider a video lecture completed.
     * Used consistently across the service and certificate checks.
     */
    public const COMPLETION_THRESHOLD = 90.0;

    /**
     * Default segment size for segment-based progress tracking.
     */
    public const DEFAULT_SEGMENT_SIZE = 10; // seconds

    /**
     * Maximum segments reportable per 15-second update (anti-cheat).
     */
    public const MAX_SEGMENTS_PER_REQUEST = 3;

    /**
     * File types that are considered video lectures (require watch tracking).
     */
    private const VIDEO_FILE_TYPES = ['video', 'mp4', 'hls', 'stream', 'vimeo', 'youtube', 'yt', 'embed', 'url'];

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
        int $totalSeconds,
        array $metadata = []
    ): VideoProgress {
        $existing = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

        // Anti-Cheat: Validate realistic watch time
        $cacheKey = "progress_tracking_legacy_user_{$user->id}_lecture_{$lecture->id}";
        $lastUpdate = Cache::get($cacheKey);
        $now = now()->timestamp;
        
        $previouslyWatched = $existing ? $existing->watched_seconds : 0;
        $reportedSeconds = max(0, $watchedSeconds - $previouslyWatched);

        if ($reportedSeconds > 0) {
            if ($lastUpdate) {
                $timePassed = $now - $lastUpdate;
                // Allow up to 4x playback speed + 15s buffer for legacy tracking
                if (($timePassed * 4) + 15 < $reportedSeconds) {
                    Log::warning('VideoProgressService Anti-Cheat: Unrealistic watch time reported in legacy method', [
                        'user_id'       => $user->id,
                        'lecture_id'    => $lecture->id,
                        'reported_diff' => $reportedSeconds,
                        'time_passed'   => $timePassed,
                    ]);
                    return $existing ?: new VideoProgress(); // Reject update and return current state
                }
            } else {
                // Initial request or cache expired (user resumed after a break).
                // Allow up to MAX_SEGMENTS_PER_REQUEST full segments + a small buffer.
                // This is stricter than a live session but realistic for a resume scenario.
                $initialAllowance = (self::MAX_SEGMENTS_PER_REQUEST * self::DEFAULT_SEGMENT_SIZE) + 15;
                if ($reportedSeconds > $initialAllowance) {
                    Log::warning('VideoProgressService Anti-Cheat: Unrealistic initial watch time reported in legacy method', [
                        'user_id'          => $user->id,
                        'lecture_id'       => $lecture->id,
                        'reported_diff'    => $reportedSeconds,
                        'initial_allowance'=> $initialAllowance,
                    ]);
                    return $existing ?: new VideoProgress(); // Reject update and return current state
                }
            }
        }

        Cache::put($cacheKey, $now, 3600);

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

        $progress = \Illuminate\Support\Facades\DB::transaction(function () use (
            $user, $lecture, $effectiveWatched, $totalSeconds, $lastPosition, 
            $watchPercentage, $isCompleted, $completedAt, $metadata, $existing
        ) {
            $updateData = [
                'watched_seconds' => $effectiveWatched,
                'total_seconds' => $totalSeconds,
                'last_position' => $lastPosition,
                'watch_percentage' => $watchPercentage,
                'is_completed' => $isCompleted,
                'completed_at' => $completedAt,
            ];

            if (!empty($metadata['session_id'])) $updateData['session_id'] = $metadata['session_id'];
            if (!empty($metadata['device'])) $updateData['device'] = $metadata['device'];
            if (!empty($metadata['browser'])) $updateData['browser'] = $metadata['browser'];
            if (!empty($metadata['ip'])) $updateData['ip'] = $metadata['ip'];
            if (!empty($metadata['progress_state'])) $updateData['progress_state'] = $metadata['progress_state'];

            // Increment watch count if it's a new session
            if ($existing && !empty($metadata['session_id']) && $existing->session_id !== $metadata['session_id']) {
                $updateData['watch_count'] = $existing->watch_count + 1;
            }

            return VideoProgress::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'lecture_id' => $lecture->id,
                ],
                $updateData
            );
        });

        // 🔄 Sync: when video is newly completed, mark lecture as completed
        // in user_curriculum_trackings so both tables stay in sync.
        if ($isCompleted && !$wasAlreadyCompleted && $lecture->course_chapter_id) {
            $this->syncCurriculumTracking($user->id, $lecture);

            // Dispatch event to update course progress
            $chapter = CourseChapter::find($lecture->course_chapter_id);
            if ($chapter) {
                CurriculumItemCompleted::dispatch($user->id, $chapter->course_id);
            }
        } else if ($lecture->course_chapter_id) {
            // For partial progress updates, clear the cache so the dashboard reflects the new percentage
            $chapter = CourseChapter::find($lecture->course_chapter_id);
            if ($chapter) {
                app(\App\Services\CourseProgressService::class)->clearCache($user->id, $chapter->course_id);
            }
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
        $videoLectures = collect([]);

        foreach ($lectures as $lecture) {
            if ($this->lectureHasVideo($lecture)) {
                $videoLectures->push($lecture);
            }
        }

        if ($videoLectures->isEmpty()) {
            return 100.0;
        }

        $completedCount = 0;

        foreach ($videoLectures as $lecture) {
            $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();
            if ($progress !== null && $progress->is_completed) {
                $completedCount++;
            }
        }

        return round(($completedCount / $videoLectures->count()) * 100, 2);
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
     * Update progress using segment-based tracking.
     *
     * @param User $user
     * @param CourseChapterLecture $lecture
     * @param int $currentPosition Current playback position in seconds
     * @param int $totalDuration Total video duration in seconds
     * @param array $newlyWatchedSegments Array of segment indices that were newly watched
     * @return VideoProgress
     */
    public function updateSegmentProgress(
        User $user,
        CourseChapterLecture $lecture,
        int $currentPosition,
        int $totalDuration,
        array $newlyWatchedSegments,
        array $metadata = []
    ): VideoProgress {
        $progress = $this->getOrCreateSegmentProgress($user, $lecture, $totalDuration);

        // Anti-Cheat: Validate realistic watch time
        $cacheKey = "progress_tracking_user_{$user->id}_lecture_{$lecture->id}";
        $lastUpdate = Cache::get($cacheKey);
        $now = now()->timestamp;
        $reportedSeconds = count($newlyWatchedSegments) * self::DEFAULT_SEGMENT_SIZE;

        if ($reportedSeconds > 0) {
            if ($lastUpdate) {
                $timePassed = $now - $lastUpdate;
                // Allow up to 4x playback speed + 15s buffer for segment tracking
                if (($timePassed * 4) + 15 < $reportedSeconds) {
                    Log::warning('VideoProgressService Anti-Cheat: Unrealistic watch time reported', [
                        'user_id'          => $user->id,
                        'lecture_id'       => $lecture->id,
                        'reported_seconds' => $reportedSeconds,
                        'time_passed'      => $timePassed,
                    ]);
                    return $progress; // Reject update and return current state
                }
            } else {
                // Initial request or cache expired (user resumed after a break).
                // Allow up to MAX_SEGMENTS_PER_REQUEST full segments + a small buffer.
                $initialAllowance = (self::MAX_SEGMENTS_PER_REQUEST * self::DEFAULT_SEGMENT_SIZE) + 15;
                if ($reportedSeconds > $initialAllowance) {
                    Log::warning('VideoProgressService Anti-Cheat: Unrealistic initial watch time reported', [
                        'user_id'           => $user->id,
                        'lecture_id'        => $lecture->id,
                        'reported_seconds'  => $reportedSeconds,
                        'initial_allowance' => $initialAllowance,
                    ]);
                    return $progress; // Reject update and return current state
                }
            }
        }

        Cache::put($cacheKey, $now, 3600);

        // Get existing watched segments or initialize
        $watchedSegments = $progress->watched_segments ??
            VideoProgress::initializeSegments($totalDuration, self::DEFAULT_SEGMENT_SIZE);

        // Mark newly watched segments
        foreach ($newlyWatchedSegments as $segmentIndex) {
            if ($segmentIndex >= 0 && $segmentIndex < $progress->total_segments) {
                $watchedSegments[$segmentIndex] = 1;
            }
        }

        // Calculate progress
        $completedSegments = array_sum($watchedSegments);
        $watchPercentage = $progress->total_segments > 0
            ? round(($completedSegments / $progress->total_segments) * 100, 2)
            : 0;

        // Check completion
        $wasAlreadyCompleted = $progress->is_completed;
        $isCompleted = $watchPercentage >= self::COMPLETION_THRESHOLD;
        $completedAt = $isCompleted && !$wasAlreadyCompleted ? now() : $progress->completed_at;

        // Also update legacy watched_seconds for backward compatibility
        $watchedSeconds = $completedSegments * self::DEFAULT_SEGMENT_SIZE;

        // Build update array
        $updateData = [
            'watched_segments' => $watchedSegments,
            'completed_segments' => $completedSegments,
            'watch_percentage' => $watchPercentage,
            'watched_seconds' => $watchedSeconds,
            'last_position' => $currentPosition,
            'is_completed' => $isCompleted,
            'completed_at' => $completedAt,
        ];

        if (!empty($metadata['session_id'])) $updateData['session_id'] = $metadata['session_id'];
        if (!empty($metadata['device'])) $updateData['device'] = $metadata['device'];
        if (!empty($metadata['browser'])) $updateData['browser'] = $metadata['browser'];
        if (!empty($metadata['ip'])) $updateData['ip'] = $metadata['ip'];
        if (!empty($metadata['progress_state'])) $updateData['progress_state'] = $metadata['progress_state'];

        // Increment watch count if it's a new session
        if (!empty($metadata['session_id']) && $progress->session_id !== $metadata['session_id']) {
            $updateData['watch_count'] = ($progress->watch_count ?? 1) + 1;
        }

        // Update record
        $progress->update($updateData);

        // Sync curriculum tracking if newly completed
        if ($isCompleted && !$wasAlreadyCompleted && $lecture->course_chapter_id) {
            $this->syncCurriculumTracking($user->id, $lecture);

            $chapter = CourseChapter::find($lecture->course_chapter_id);
            if ($chapter) {
                CurriculumItemCompleted::dispatch($user->id, $chapter->course_id);
            }
        }

        return $progress->fresh();
    }

    /**
     * Get maximum position user can seek to (highest continuously watched point from start).
     *
     * @param VideoProgress $progress
     * @return int Maximum seekable position in seconds
     */
    public function getMaxSeekablePosition(VideoProgress $progress): int
    {
        // If completed, allow seeking anywhere
        if ($progress->is_completed) {
            return $progress->total_seconds;
        }

        $watchedSegments = $progress->watched_segments ?? [];
        $maxContinuousIndex = 0;

        // Find highest continuous watched segment from start
        foreach ($watchedSegments as $index => $watched) {
            if ($watched) {
                $maxContinuousIndex = $index + 1;
            } else {
                break; // First unwatched segment breaks the chain
            }
        }

        return $maxContinuousIndex * ($progress->segment_size ?? self::DEFAULT_SEGMENT_SIZE);
    }

    /**
     * Get progress with seek information for API response.
     *
     * @param User $user
     * @param CourseChapterLecture $lecture
     * @return array|null
     */
    public function getProgressWithSeekInfo(User $user, CourseChapterLecture $lecture): ?array
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
            'watched_segments' => $progress->watched_segments ?? [],
            'total_segments' => $progress->total_segments ?? 0,
            'completed_segments' => $progress->completed_segments ?? 0,
            'can_seek_to' => $this->getMaxSeekablePosition($progress),
            'resume_from' => $progress->last_position,
        ];
    }

    private function lectureHasVideo(CourseChapterLecture $lecture): bool
    {
        // Only types that actually stream/play video require watch-time tracking.
        // Doc-type lectures (PDFs, text) are auto-counted as completed.
        return in_array(strtolower((string) ($lecture->file_type ?? '')), self::VIDEO_FILE_TYPES, true);
    }

    /**
     * Get existing progress or create new with segment initialization.
     */
    private function getOrCreateSegmentProgress(
        User $user,
        CourseChapterLecture $lecture,
        int $totalDuration
    ): VideoProgress {
        $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

        if ($progress === null) {
            $totalSegments = (int) ceil($totalDuration / self::DEFAULT_SEGMENT_SIZE);
            $watchedSegments = array_fill(0, $totalSegments, 0);

            $progress = VideoProgress::create([
                'user_id' => $user->id,
                'lecture_id' => $lecture->id,
                'watched_seconds' => 0,
                'total_seconds' => $totalDuration,
                'last_position' => 0,
                'watch_percentage' => 0,
                'is_completed' => false,
                'watched_segments' => $watchedSegments,
                'segment_size' => self::DEFAULT_SEGMENT_SIZE,
                'total_segments' => $totalSegments,
                'completed_segments' => 0,
            ]);
        }

        return $progress;
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

            // Invalidate CourseProgressService cache and recalculate so UI gets fresh progress immediately
            $courseId = $lecture->chapter->course_id ?? null;
            if ($courseId) {
                app(\App\Services\CourseProgressService::class)->calculateAndUpdateProgress($userId, $courseId);
            }

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
