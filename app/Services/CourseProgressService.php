<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CourseProgressService
{
    private const CACHE_TTL = 600; // 10 minutes

    /**
     * Get or create progress record for user in course
     */
    public function getProgress(int $userId, int $courseId): UserCourseProgress
    {
        try {
            return UserCourseProgress::firstOrCreate(
                ['user_id' => $userId, 'course_id' => $courseId],
                ['total_items' => $this->getTotalItemsForCourse($courseId)]
            );
        } catch (\Throwable $e) {
            Log::error('CourseProgressService::getProgress failed: '.$e->getMessage(), [
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            $existing = UserCourseProgress::query()
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    public function calculateAndUpdateProgress(int $userId, int $courseId, bool $touchLastAccessed = true): UserCourseProgress
    {
        $progress = $this->getProgress($userId, $courseId);
        $user = User::find($userId);
        $course = Course::find($courseId);

        if (! $user || ! $course) {
            return $progress;
        }

        try {
            $detailed = $this->getDetailedProgress($userId, $courseId);
            $completedItems = $detailed['summary']['completed_items'] ?? 0;
            $totalItems = $detailed['summary']['total_items'] ?? 0;
            $percentage = (float) ($detailed['course']['progress_percentage'] ?? 0);

            $watchedSeconds = $this->getWatchedSecondsForCourse($userId, $courseId);
            $status = $this->resolveLearningStatusValues(
                $percentage,
                (int) $completedItems,
                (int) $totalItems,
                $watchedSeconds,
            );
            if ($status === 'completed') {
                $percentage = 100.0;
            } else {
                $percentage = min(99.9, max(0.0, $percentage));
            }

            // Monotonic progress protection: progress percentage and completed items count must never regress
            $previousPercentage = (float) ($progress->progress_percentage ?? 0.0);
            $previousCompleted = (int) ($progress->completed_items ?? 0);
            if ($percentage < $previousPercentage && $previousPercentage > 0) {
                $percentage = $previousPercentage;
            }
            if ($completedItems < $previousCompleted && $previousCompleted > 0) {
                $completedItems = $previousCompleted;
            }
            if ($progress->status === 'completed') {
                $status = 'completed';
                $percentage = 100.0;
            }

            $attributes = [
                'completed_items' => $completedItems,
                'total_items' => $totalItems,
                'progress_percentage' => $percentage,
                'status' => $status,
            ];
            // Recalculation is also performed by read paths such as the dashboard.
            // A read must not fabricate a course access timestamp.
            if ($touchLastAccessed) {
                $attributes['last_accessed_at'] = now();
            }

            $progress->update($attributes);
            $progress = $progress->fresh();

            // Store the fresh value before certificate issuance. CertificateService
            // verifies completion through this service; clearing the cache first
            // caused a 100% course to recursively calculate itself.
            Cache::put("user:{$userId}:course:{$courseId}:progress", $progress, self::CACHE_TTL);

            if ($status === 'completed') {
                app(CertificateService::class)->autoGenerateCertificate($userId, $courseId);
            }
        } catch (\Throwable $e) {
            Log::error('Error calculating progress: '.$e->getMessage());
        }

        return $progress;
    }

    public function resolveLearningStatus(UserCourseProgress $progress, int $watchedSeconds = 0): string
    {
        return $this->resolveLearningStatusValues(
            (float) ($progress->progress_percentage ?? 0),
            (int) ($progress->completed_items ?? 0),
            (int) ($progress->total_items ?? 0),
            $watchedSeconds,
        );
    }

    private function resolveLearningStatusValues(
        float $percentage,
        int $completedItems,
        int $totalItems,
        int $watchedSeconds,
    ): string {
        if (($totalItems > 0 && $completedItems >= $totalItems) || $percentage >= 100.0) {
            return 'completed';
        }

        return $percentage > 0 || $completedItems > 0 || $watchedSeconds > 0
            ? 'in_progress'
            : 'not_started';
    }

    private function getWatchedSecondsForCourse(int $userId, int $courseId): int
    {
        return (int) DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $userId)
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapters.is_active', true)
            ->where('course_chapter_lectures.is_active', true)
            ->sum('video_progress.watched_seconds');
    }

    /**
     * Get progress with caching (fast)
     */
    public function getProgressWithCache(int $userId, int $courseId): UserCourseProgress
    {
        $cacheKey = "user:{$userId}:course:{$courseId}:progress";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $courseId) {
            try {
                // `video_progress` and curriculum tracking are authoritative. A
                // persisted aggregate can be stale after a player update, so every
                // cache miss reconciles it instead of returning the old row.
                return $this->calculateAndUpdateProgress($userId, $courseId, false);
            } catch (\Throwable $e) {
                return $this->calculateAndUpdateProgress($userId, $courseId, false);
            }
        });
    }

    /**
     * Get all user progress with cache (for my-learning endpoint)
     */
    public function getUserAllProgressWithCache(int $userId): array
    {
        $cacheKey = "user:{$userId}:all-progress";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            try {
                return UserCourseProgress::where('user_id', $userId)
                    ->with('course')
                    ->get()
                    ->groupBy('status')
                    ->toArray();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Get total curriculum items for course (cached)
     */
    public function getTotalItemsForCourse(int $courseId): int
    {
        $cacheKey = "course:{$courseId}:total_items";

        return Cache::remember($cacheKey, 3600, function () use ($courseId) {
            try {
                $course = Course::with([
                    'chapters' => fn ($q) => $q->where('is_active', 1),
                    'chapters.lectures' => fn ($q) => $q->where('is_active', 1),
                    'chapters.resources' => fn ($q) => $q->where('is_active', 1),
                ])->find($courseId);

                if (! $course) {
                    return 0;
                }

                $total = 0;
                foreach ($course->chapters as $chapter) {
                    $total += $chapter->lectures->count();
                }

                return $total;
            } catch (\Throwable) {
                return 0;
            }
        });
    }

    /**
     * Clear progress cache
     */
    public function clearCache(int $userId, ?int $courseId = null): void
    {
        Cache::forget("user:{$userId}:all-progress");

        if ($courseId) {
            Cache::forget("user:{$userId}:course:{$courseId}:progress");
        }
    }

    /**
     * Check if user has started course
     */
    public function hasStarted(int $userId, int $courseId): bool
    {
        return $this->resolveLearningStatus(
            $this->getProgressWithCache($userId, $courseId),
            $this->getWatchedSecondsForCourse($userId, $courseId),
        ) !== 'not_started';
    }

    /**
     * Get detailed curriculum breakdown for user in course
     */
    public function getDetailedProgress(int $userId, int $courseId): array
    {
        try {
            $course = Course::with([
                'chapters' => static fn ($query) => $query->where('is_active', true)->orderBy('chapter_order')->with([
                    'lectures' => static fn ($query) => $query->where('is_active', true)->orderBy('chapter_order'),
                    'resources' => static fn ($query) => $query->where('is_active', true),
                ]),
            ])
                ->findOrFail($courseId);
        } catch (\Throwable $e) {
            Log::error('Error loading course with relationships: '.$e->getMessage(), [
                'course_id' => $courseId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        try {
            $tracking = UserCurriculumTracking::where('user_id', $userId)
                ->whereHas('chapter', fn ($q) => $q->where('course_id', $courseId))
                ->get()
                ->keyBy(fn ($item) => $item->model_type.':'.$item->model_id);
        } catch (\Throwable $e) {
            Log::error('Error loading tracking: '.$e->getMessage());
            $tracking = collect();
        }

        try {
            $lectureIds = $course->chapters->flatMap->lectures->pluck('id')->toArray();
            $videoProgress = DB::table('video_progress')
                ->where('user_id', $userId)
                ->whereIn('lecture_id', $lectureIds)
                ->get()
                ->keyBy('lecture_id');
        } catch (\Throwable $e) {
            Log::error('Error loading video progress: '.$e->getMessage());
            $videoProgress = collect();
        }

        $chaptersData = [];
        $totalItems = 0;
        $completedItems = 0;
        $rawProgressScore = 0;
        $nextItem = null;

        foreach ($course->chapters as $chapter) {
            $items = [];

            foreach ($chapter->lectures as $lecture) {
                $totalItems++;
                $key = get_class($lecture).':'.$lecture->id;
                $track = $tracking->get($key);
                $video = $videoProgress->get($lecture->id);

                $requiresVerifiedTracking = app(VideoProgressService::class)
                    ->requiresVerifiedTracking($lecture);
                $isCompleted = $requiresVerifiedTracking
                    ? (bool) ($video?->is_completed ?? false)
                    : $track?->status === 'completed';
                $watchPercentage = min(100.0, max(0.0, (float) ($video?->watch_percentage ?? 0)));
                $durationSeconds = max(0, (int) ($lecture->duration_seconds ?? 0));
                $storedVideoDuration = max(0, (int) ($video?->total_seconds ?? 0));
                $durationLimit = $durationSeconds > 0 ? $durationSeconds : $storedVideoDuration;
                $watchedSeconds = max(0, (int) ($video?->watched_seconds ?? 0));
                if ($durationLimit > 0) {
                    $watchedSeconds = min($watchedSeconds, $durationLimit);
                }

                if ($isCompleted) {
                    $completedItems++;
                    $rawProgressScore += 1;
                } elseif ($watchPercentage > 0) {
                    $rawProgressScore += ($watchPercentage / 100);
                }

                if (! $nextItem && ! $isCompleted) {
                    $nextItem = [
                        'chapter_id' => $chapter->id,
                        'item_id' => $lecture->id,
                        'type' => 'lecture',
                        'title' => $lecture->title,
                    ];
                }

                $items[] = [
                    'item_id' => $lecture->id,
                    'type' => 'lecture',
                    'title' => $lecture->title,
                    'status' => $isCompleted ? 'completed' : ($video?->watched_seconds > 0 ? 'in_progress' : 'not_started'),
                    'completed_at' => $track?->completed_at?->format('Y-m-d H:i:s'),
                    'watch_percentage' => $watchPercentage,
                    'duration_seconds' => $durationSeconds,
                    'watched_seconds' => $watchedSeconds,
                ];
            }

            $chapterProgressScore = 0;
            foreach ($items as $i) {
                if ($i['status'] === 'completed') {
                    $chapterProgressScore += 1;
                } elseif ($i['type'] === 'lecture' && ($i['watch_percentage'] ?? 0) > 0) {
                    $chapterProgressScore += ($i['watch_percentage'] / 100);
                }
            }

            $chaptersData[] = [
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->title ?? $chapter->name ?? '',
                'progress_percentage' => count($items) > 0 ? round(($chapterProgressScore / count($items)) * 100, 2) : 0,
                'items' => $items,
            ];
        }

        $courseCompleted = $totalItems > 0 && $completedItems === $totalItems;
        $calculatedPercentage = $totalItems > 0
            ? ($courseCompleted
                ? 100.0
                : min(99.9, max(0.0, round(($rawProgressScore / $totalItems) * 100, 2))))
            : 0;

        return [
            'course' => [
                'id' => $course->id,
                'name' => $course->title ?? $course->name ?? '',
                'thumbnail' => $course->thumbnail,
                'progress_percentage' => $calculatedPercentage,
                'status' => $courseCompleted
                    ? 'completed'
                    : ($rawProgressScore > 0 ? 'in_progress' : 'not_started'),
            ],
            'chapters' => $chaptersData,
            'next_item' => $nextItem,
            'summary' => [
                'total_items' => $totalItems,
                'completed_items' => $completedItems,
                'started_items' => $tracking->count(),
            ],
        ];
    }

    /**
     * Get admin overview stats for all courses
     * Calculates enrollment from OrderCourse (purchases) + active subscriptions
     */
    public function getAdminOverview(?string $search = null, ?string $status = null): array
    {
        try {
            // Build query for courses with enrollment stats
            $query = DB::table('courses')
                ->select([
                    'courses.id as course_id',
                    'courses.title as course_name',
                    'courses.thumbnail',
                    'courses.status as course_status',
                ])
                ->selectRaw('(
                    SELECT COUNT(DISTINCT o.user_id)
                    FROM order_courses oc
                    JOIN orders o ON oc.order_id = o.id
                    WHERE oc.course_id = courses.id
                    AND o.status = "completed"
                ) as purchased_students')
                ->selectRaw('(
                    SELECT COUNT(DISTINCT s.user_id)
                    FROM subscriptions s
                    WHERE s.status = "active"
                    AND (s.ends_at IS NULL OR s.ends_at > CURRENT_TIMESTAMP)
                    AND (
                        EXISTS (
                            SELECT 1 FROM user_course_progress ucp_sub
                            WHERE ucp_sub.user_id = s.user_id
                            AND ucp_sub.course_id = courses.id
                        )
                        OR EXISTS (
                            SELECT 1 FROM user_curriculum_trackings uct_sub
                            JOIN course_chapters cc_sub ON uct_sub.course_chapter_id = cc_sub.id
                            WHERE uct_sub.user_id = s.user_id
                            AND cc_sub.course_id = courses.id
                        )
                    )
                ) as subscription_students');

            if (Schema::hasTable('user_course_progress')) {
                $query->selectRaw('(
                    SELECT COUNT(DISTINCT ucp.user_id)
                    FROM user_course_progress ucp
                    WHERE ucp.course_id = courses.id
                    AND ucp.status = "completed"
                ) as completed_students')
                    ->selectRaw('(
                    SELECT COUNT(DISTINCT ucp.user_id)
                    FROM user_course_progress ucp
                    WHERE ucp.course_id = courses.id
                    AND ucp.status = "in_progress"
                ) as in_progress_students');
            } else {
                $query->selectRaw('(
                    SELECT COUNT(DISTINCT uct.user_id)
                    FROM user_curriculum_trackings uct
                    JOIN course_chapters cc ON uct.course_chapter_id = cc.id
                    WHERE cc.course_id = courses.id
                    AND uct.status = "completed"
                ) as completed_students')
                    ->selectRaw('(
                    SELECT COUNT(DISTINCT uct.user_id)
                    FROM user_curriculum_trackings uct
                    JOIN course_chapters cc ON uct.course_chapter_id = cc.id
                    WHERE cc.course_id = courses.id
                    AND uct.status = "in_progress"
                ) as in_progress_students');
            }

            $query->selectRaw('(
                    SELECT COUNT(*) FROM (
                        SELECT o.user_id
                        FROM order_courses oc
                        JOIN orders o ON oc.order_id = o.id
                        WHERE oc.course_id = courses.id AND o.status = "completed"
                        UNION
                        SELECT s.user_id
                        FROM subscriptions s
                        WHERE s.status = "active"
                        AND (s.ends_at IS NULL OR s.ends_at > CURRENT_TIMESTAMP)
                        AND (
                            EXISTS (
                                SELECT 1 FROM user_course_progress ucp_u
                                WHERE ucp_u.user_id = s.user_id
                                AND ucp_u.course_id = courses.id
                            )
                            OR EXISTS (
                                SELECT 1 FROM user_curriculum_trackings uct_u
                                JOIN course_chapters cc_u ON uct_u.course_chapter_id = cc_u.id
                                WHERE uct_u.user_id = s.user_id
                                AND cc_u.course_id = courses.id
                            )
                        )
                        UNION
                        SELECT ucp_all.user_id
                        FROM user_course_progress ucp_all
                        WHERE ucp_all.course_id = courses.id
                    ) AS unique_students
                ) as total_students');

            if (Schema::hasTable('video_progress')) {
                $query->selectRaw('(
                    SELECT COUNT(DISTINCT vp.user_id)
                    FROM video_progress vp
                    JOIN course_chapter_lectures ccl ON vp.lecture_id = ccl.id
                    JOIN course_chapters cc ON ccl.course_chapter_id = cc.id
                    WHERE cc.course_id = courses.id
                ) as started_students')
                    ->selectRaw('(
                    SELECT MAX(vp.updated_at)
                    FROM video_progress vp
                    JOIN course_chapter_lectures ccl ON vp.lecture_id = ccl.id
                    JOIN course_chapters cc ON ccl.course_chapter_id = cc.id
                    WHERE cc.course_id = courses.id
                ) as last_activity');
            } else {
                $query->selectRaw('0 as started_students')
                    ->selectRaw('NULL as last_activity');
            }

            if ($search) {
                $query->where('courses.title', 'LIKE', "%{$search}%");
            }

            if ($status) {
                $query->where('courses.status', $status);
            }

            $results = $query->paginate(20);

            // Transform results to calculate total_students and format response
            $data = collect($results->items())->map(function ($course) {
                $purchased = (int) $course->purchased_students;
                $subscription = (int) $course->subscription_students;
                $totalStudents = (int) $course->total_students;

                // Progress distribution
                $completed = (int) $course->completed_students;
                $inProgress = (int) $course->in_progress_students;
                $started = (int) $course->started_students;
                $notStarted = max(0, $totalStudents - $completed - $inProgress);

                // Calculate average progress (approximation based on video progress)
                $avgProgress = $this->calculateCourseAvgProgress($course->course_id);

                return [
                    'course_id' => $course->course_id,
                    'course_name' => $course->course_name,
                    'thumbnail' => $course->thumbnail,
                    'course_status' => $course->course_status,
                    'total_students' => $totalStudents,
                    'purchased_count' => $purchased,
                    'subscription_count' => $subscription,
                    'completed_count' => $completed,
                    'in_progress_count' => $inProgress,
                    'not_started_count' => $notStarted,
                    'started_count' => $started,
                    'avg_progress' => round($avgProgress, 2),
                    'last_activity' => $course->last_activity,
                ];
            });

            return [
                'data' => $data->toArray(),
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Error in getAdminOverview: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Return detailed error for debugging
            return [
                'error_detail' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice($e->getTrace(), 0, 5),
                ],
                'data' => [],
                'current_page' => 1,
                'per_page' => 20,
                'total' => 0,
                'last_page' => 1,
            ];
        }
    }

    /**
     * Calculate average progress for a course
     */
    private function calculateCourseAvgProgress(int $courseId): float
    {
        try {
            // Get total curriculum items for course
            $totalItems = $this->getTotalItemsForCourse($courseId);

            if ($totalItems === 0) {
                return 0;
            }

            // Get all users who have progress in this course
            $userProgress = DB::table('user_curriculum_trackings')
                ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                ->where('course_chapters.course_id', $courseId)
                ->select('user_curriculum_trackings.user_id')
                ->selectRaw('COUNT(CASE WHEN user_curriculum_trackings.status = "completed" THEN 1 END) as completed')
                ->groupBy('user_curriculum_trackings.user_id')
                ->get();

            if ($userProgress->isEmpty()) {
                return 0;
            }

            $totalPercentage = $userProgress->sum(function ($user) use ($totalItems) {
                return ($user->completed / $totalItems) * 100;
            });

            return $totalPercentage / $userProgress->count();
        } catch (\Throwable $e) {
            Log::error('Error calculating avg progress for course '.$courseId.': '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Get admin student progress for specific course
     */
    public function getAdminCourseStudentProgress(int $courseId, ?string $search = null, ?string $status = null): array
    {
        try {
            // Check if user_course_progress table exists
            $tableExists = DB::select("SHOW TABLES LIKE 'user_course_progress'");

            if (empty($tableExists)) {
                // Return empty paginated result
                return [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ];
            }

            $query = UserCourseProgress::where('course_id', $courseId)
                ->with(['user:id,name,email,mobile,profile']);

            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            return $query->paginate(20)->toArray();
        } catch (\Throwable $e) {
            Log::error('Error in getAdminCourseStudentProgress: '.$e->getMessage(), [
                'course_id' => $courseId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
