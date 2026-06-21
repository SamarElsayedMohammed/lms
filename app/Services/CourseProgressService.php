<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        
        $totalItems = $this->getTotalItemsForCourse($courseId);
        $completedItems = UserCurriculumTracking::where('user_id', $userId)
            ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
            ->where('status', 'completed')
            ->count();

        $percentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100, 2) : 0;
        
        $status = match(true) {
            $percentage == 0 => 'not_started',
            $percentage == 100 => 'completed',
            default => 'in_progress',
        };

        $progress->update([
            'completed_items' => $completedItems,
            'total_items' => $totalItems,
            'progress_percentage' => $percentage,
            'status' => $status,
            'last_accessed_at' => now(),
        ]);

        $this->clearCache($userId, $courseId);

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
            $course = Course::with(['chapters.lectures', 'chapters.quizzes', 'chapters.assignments', 'chapters.resources'])
                ->find($courseId);

            if (!$course) {
                return 0;
            }

            $total = 0;
            foreach ($course->chapters as $chapter) {
                $total += $chapter->lectures->count()
                    + $chapter->quizzes->count()
                    + $chapter->assignments->count()
                    + $chapter->resources->count();
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
        $course = Course::with(['chapters.lectures', 'chapters.quizzes', 'chapters.assignments', 'chapters.resources'])
            ->findOrFail($courseId);

        $tracking = UserCurriculumTracking::where('user_id', $userId)
            ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
            ->get()
            ->keyBy(fn($item) => $item->model_type . ':' . $item->model_id);

        $videoProgress = DB::table('video_progress')
            ->where('user_id', $userId)
            ->whereIn('lecture_id', $course->chapters->flatMap->lectures->pluck('id'))
            ->get()
            ->keyBy('lecture_id');

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
     */
    public function getAdminOverview(?string $search = null, ?string $status = null): array
    {
        $query = DB::table('courses')
            ->leftJoin('user_course_progress', 'courses.id', '=', 'user_course_progress.course_id')
            ->select([
                'courses.id as course_id',
                'courses.name as course_name',
                'courses.thumbnail',
                'courses.status as course_status',
                DB::raw('COUNT(DISTINCT user_course_progress.user_id) as total_students'),
                DB::raw('SUM(CASE WHEN user_course_progress.status = "completed" THEN 1 ELSE 0 END) as completed_count'),
                DB::raw('SUM(CASE WHEN user_course_progress.status = "in_progress" THEN 1 ELSE 0 END) as in_progress_count'),
                DB::raw('SUM(CASE WHEN user_course_progress.status = "not_started" THEN 1 ELSE 0 END) as not_started_count'),
                DB::raw('AVG(user_course_progress.progress_percentage) as avg_progress'),
                DB::raw('MAX(user_course_progress.last_accessed_at) as last_activity'),
            ])
            ->groupBy('courses.id', 'courses.name', 'courses.thumbnail', 'courses.status');

        if ($search) {
            $query->where('courses.name', 'LIKE', "%{$search}%");
        }

        if ($status) {
            $query->where('courses.status', $status);
        }

        return $query->paginate(20)->toArray();
    }

    /**
     * Get admin student progress for specific course
     */
    public function getAdminCourseStudentProgress(int $courseId, ?string $search = null, ?string $status = null): array
    {
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
    }
}
