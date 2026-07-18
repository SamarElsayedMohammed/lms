<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourseProgressService
{
    private const CACHE_TTL = 600; // 10 minutes

    /**
     * Get or create progress record for user in course
     */
    public function getProgress(int $userId, int $courseId): UserCourseProgress
    {
        return UserCourseProgress::firstOrCreate(
            ['user_id' => $userId, 'course_id' => $courseId],
            ['total_items' => $this->getTotalItemsForCourse($courseId)]
        );
    }

    /**
     * Calculate and update progress (heavy operation - use wisely)
     */
    public function calculateAndUpdateProgress(int $userId, int $courseId): UserCourseProgress
    {
        $progress = $this->getProgress($userId, $courseId);
        $user = \App\Models\User::find($userId);
        $course = Course::find($courseId);
        
        if (!$user || !$course) {
            return $progress;
        }

        $videoProgressService = app(\App\Services\VideoProgressService::class);
        $lectures = $videoProgressService->getAllLecturesForCourse($course);
        
        $totalVideoLectures = 0;
        $completedVideoLectures = 0;

        foreach ($lectures as $lecture) {
            // Access the private method lectureHasVideo indirectly or rewrite the check
            $fileType = strtolower((string) ($lecture->file_type ?? ''));
            $isVideo = in_array($fileType, ['video', 'mp4', 'hls', 'stream', 'vimeo', 'youtube', 'yt', 'embed', 'url'], true);
            
            if ($isVideo) {
                $totalVideoLectures++;
                try {
                    $vp = \App\Models\VideoProgress::forUser($userId)->forLecture($lecture->id)->first();
                    if ($vp !== null && $vp->is_completed) {
                        $completedVideoLectures++;
                    }
                } catch (\Throwable $e) {
                    // Gracefully fallback if video_progress table is missing
                    if ($completedVideoLectures === 0) {
                         // We could fallback to curriculum trackings if necessary
                    }
                }
            }
        }

        $percentage = $totalVideoLectures > 0 ? round(($completedVideoLectures / $totalVideoLectures) * 100, 2) : 100;
        
        // Ensure it doesn't exceed 100
        if ($percentage > 100) {
            $percentage = 100;
        }
        
        $status = match(true) {
            $percentage == 0 => 'not_started',
            $percentage == 100 => 'completed',
            default => 'in_progress',
        };

        $progress->update([
            'completed_items' => $completedVideoLectures,
            'total_items' => $totalVideoLectures,
            'progress_percentage' => $percentage,
            'status' => $status,
            'last_accessed_at' => now(),
        ]);

        $this->clearCache($userId, $courseId);

        if ($percentage == 100) {
            app(\App\Services\CertificateService::class)->autoGenerateCertificate($userId, $courseId);
        }

        return $progress->fresh();
    }

    /**
     * Get progress with caching (fast)
     */
    public function getProgressWithCache(int $userId, int $courseId): UserCourseProgress
    {
        $cacheKey = "user:{$userId}:course:{$courseId}:progress";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $courseId) {
            $progress = UserCourseProgress::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();

            if (!$progress) {
                return $this->calculateAndUpdateProgress($userId, $courseId);
            }

            return $progress;
        });
    }

    /**
     * Get all user progress with cache (for my-learning endpoint)
     */
    public function getUserAllProgressWithCache(int $userId): array
    {
        $cacheKey = "user:{$userId}:all-progress";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            return UserCourseProgress::where('user_id', $userId)
                ->with('course')
                ->get()
                ->groupBy('status')
                ->toArray();
        });
    }

    /**
     * Get total curriculum items for course (cached)
     */
    public function getTotalItemsForCourse(int $courseId): int
    {
        $cacheKey = "course:{$courseId}:total_items";

        return Cache::remember($cacheKey, 3600, function () use ($courseId) {
            $course = Course::with([
                'chapters' => fn($q) => $q->where('is_active', 1),
                'chapters.lectures' => fn($q) => $q->where('is_active', 1),
            ])->find($courseId);

            if (!$course) {
                return 0;
            }

            $total = 0;
            foreach ($course->chapters as $chapter) {
                foreach ($chapter->lectures as $lecture) {
                    $fileType = strtolower((string) ($lecture->file_type ?? ''));
                    $isVideo = in_array($fileType, ['video', 'mp4', 'hls', 'stream', 'vimeo', 'youtube', 'yt', 'embed', 'url'], true);
                    if ($isVideo) {
                        $total++;
                    }
                }
            }

            return $total;
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
        return $this->getProgressWithCache($userId, $courseId)->progress_percentage > 0;
    }

    /**
     * Get detailed curriculum breakdown for user in course
     */
    public function getDetailedProgress(int $userId, int $courseId): array
    {
        try {
            $course = Course::with(['chapters.lectures', 'chapters.quizzes', 'chapters.assignments'])
                ->findOrFail($courseId);
        } catch (\Throwable $e) {
            Log::error('Error loading course with relationships: ' . $e->getMessage(), [
                'course_id' => $courseId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        try {
            $tracking = UserCurriculumTracking::where('user_id', $userId)
                ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
                ->get()
                ->keyBy(fn($item) => $item->model_type . ':' . $item->model_id);
        } catch (\Throwable $e) {
            Log::error('Error loading tracking: ' . $e->getMessage());
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
            Log::error('Error loading video progress: ' . $e->getMessage());
            $videoProgress = collect();
        }

        $chaptersData = [];
        $totalItems = 0;
        $completedItems = 0;
        $nextItem = null;

        foreach ($course->chapters as $chapter) {
            $items = [];
            
            foreach ($chapter->lectures as $lecture) {
                $totalItems++;
                $key = get_class($lecture) . ':' . $lecture->id;
                $track = $tracking->get($key);
                $video = $videoProgress->get($lecture->id);
                
                $isCompleted = $track?->status === 'completed' || ($video?->is_completed ?? false);
                if ($isCompleted) {
                    $completedItems++;
                }
                
                if (!$nextItem && !$isCompleted) {
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
                    'watch_percentage' => $video?->watch_percentage ?? 0,
                    'duration_seconds' => $lecture->duration_seconds ?? 0,
                    'watched_seconds' => $video?->watched_seconds ?? 0,
                ];
            }

            foreach ($chapter->quizzes as $quiz) {
                $totalItems++;
                $key = get_class($quiz) . ':' . $quiz->id;
                $track = $tracking->get($key);
                $isCompleted = $track?->status === 'completed';
                if ($isCompleted) {
                    $completedItems++;
                }
                
                if (!$nextItem && !$isCompleted) {
                    $nextItem = [
                        'chapter_id' => $chapter->id,
                        'item_id' => $quiz->id,
                        'type' => 'quiz',
                        'title' => $quiz->title,
                    ];
                }

                $items[] = [
                    'item_id' => $quiz->id,
                    'type' => 'quiz',
                    'title' => $quiz->title,
                    'status' => $isCompleted ? 'completed' : ($track?->status ?? 'not_started'),
                    'completed_at' => $track?->completed_at?->format('Y-m-d H:i:s'),
                    'score' => $track?->metadata['score'] ?? null,
                ];
            }

            foreach ($chapter->assignments as $assignment) {
                $totalItems++;
                $key = get_class($assignment) . ':' . $assignment->id;
                $track = $tracking->get($key);
                $isCompleted = $track?->status === 'completed';
                if ($isCompleted) {
                    $completedItems++;
                }
                
                if (!$nextItem && !$isCompleted) {
                    $nextItem = [
                        'chapter_id' => $chapter->id,
                        'item_id' => $assignment->id,
                        'type' => 'assignment',
                        'title' => $assignment->title,
                    ];
                }

                $items[] = [
                    'item_id' => $assignment->id,
                    'type' => 'assignment',
                    'title' => $assignment->title,
                    'status' => $isCompleted ? 'completed' : ($track?->status ?? 'not_started'),
                    'completed_at' => $track?->completed_at?->format('Y-m-d H:i:s'),
                ];
            }

            foreach ($chapter->resources as $resource) {
                $totalItems++;
                $key = get_class($resource) . ':' . $resource->id;
                $track = $tracking->get($key);
                $isCompleted = $track?->status === 'completed';
                if ($isCompleted) {
                    $completedItems++;
                }
                
                if (!$nextItem && !$isCompleted) {
                    $nextItem = [
                        'chapter_id' => $chapter->id,
                        'item_id' => $resource->id,
                        'type' => 'resource',
                        'title' => $resource->title,
                    ];
                }

                $items[] = [
                    'item_id' => $resource->id,
                    'type' => 'resource',
                    'title' => $resource->title,
                    'status' => $isCompleted ? 'completed' : ($track?->status ?? 'not_started'),
                    'completed_at' => $track?->completed_at?->format('Y-m-d H:i:s'),
                ];
            }

            $chapterCompleted = count(array_filter($items, fn($i) => $i['status'] === 'completed'));
            
            $chaptersData[] = [
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'progress_percentage' => count($items) > 0 ? round(($chapterCompleted / count($items)) * 100, 2) : 0,
                'items' => $items,
            ];
        }

        return [
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'thumbnail' => $course->thumbnail,
                'progress_percentage' => $totalItems > 0 ? round(($completedItems / $totalItems) * 100, 2) : 0,
                'status' => $completedItems === 0 ? 'not_started' : ($completedItems === $totalItems ? 'completed' : 'in_progress'),
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
                    JOIN subscription_plans sp ON s.plan_id = sp.id
                    WHERE s.status = "active"
                    AND (s.ends_at IS NULL OR s.ends_at > NOW())
                ) as subscription_students')
                ->selectRaw('(
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
                
            if (\Illuminate\Support\Facades\Schema::hasTable('video_progress')) {
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
                // Total students = purchased + subscription (subscription users can access all courses)
                $purchased = (int) $course->purchased_students;
                $subscription = (int) $course->subscription_students;
                
                // Note: A user might be counted in both (purchased + has subscription)
                // For accurate count, we'd need to UNION the user IDs, but for overview:
                $totalStudents = max($purchased, $subscription); // Approximation

                // Progress distribution
                $completed = (int) $course->completed_students;
                $inProgress = (int) $course->in_progress_students;
                $started = (int) $course->started_students;
                
                // Calculate not_started (started but no progress tracking)
                $notStarted = max(0, $started - $completed - $inProgress);

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
            Log::error('Error in getAdminOverview: ' . $e->getMessage(), [
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
            Log::error('Error calculating avg progress for course ' . $courseId . ': ' . $e->getMessage());
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
                ->with(['user:id,name,email,phone,avatar']);

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
            Log::error('Error in getAdminCourseStudentProgress: ' . $e->getMessage(), [
                'course_id' => $courseId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
