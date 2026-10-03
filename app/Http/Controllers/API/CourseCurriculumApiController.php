<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Services\ApiResponseService;
use App\Services\ContentAccessService;
use App\Services\CourseProgressService;
use Illuminate\Support\Facades\Auth;

class CourseCurriculumApiController extends Controller
{
    public function __construct(
        private readonly ContentAccessService $contentAccessService,
        private readonly CourseProgressService $courseProgressService,
    ) {}

    public function showBySlug(string $slug): void
    {
        $user = Auth::guard('sanctum')->user() ?: Auth::user();
        if ($user === null) {
            ApiResponseService::errorResponse('يجب تسجيل الدخول لعرض منهج الدورة.', [], 401);
            return;
        }

        $course = Course::query()->where('slug', $slug)->first();
        if ($course === null) {
            ApiResponseService::errorResponse('الدورة غير موجودة.', [], 404);
            return;
        }

        if (!$this->contentAccessService->canAccessCourse($user, $course)) {
            ApiResponseService::forbidden('لا يمكنك الوصول إلى محتوى هذه الدورة.');
            return;
        }

        $detailed = $this->courseProgressService->getDetailedProgress((int) $user->id, (int) $course->id);

        $sections = [];
        $totalLessons = 0;
        $completedLessons = 0;

        $isSequential = (bool) ($course->sequential_access ?? true);
        if ($user && ($user->hasRole(['admin', 'instructor', 'supervisor', 'Super Admin']) || $user->id === $course->user_id)) {
            $isSequential = false;
        }
        $allPriorLessonsCompleted = true; // First lesson is always unlocked

        foreach ($detailed['chapters'] ?? [] as $chapter) {
            $lessons = [];
            foreach ($chapter['items'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'lecture') {
                    continue;
                }

                $totalLessons++;
                $isCompleted = ($item['status'] ?? '') === 'completed';
                if ($isCompleted) {
                    $completedLessons++;
                }

                $isLocked = $isSequential ? (!$allPriorLessonsCompleted && !$isCompleted) : false;
                if (! $isCompleted) {
                    $allPriorLessonsCompleted = false;
                }

                $durationSeconds = (int) ($item['duration_seconds'] ?? 0);
                $watchPercentage = (float) ($item['watch_percentage'] ?? 0);
                $watchedSeconds = (int) ($item['watched_seconds'] ?? 0);

                $lessons[] = [
                    'id' => $item['item_id'],
                    'title' => $item['title'] ?? '',
                    'duration_minutes' => (int) ceil(max(0, $durationSeconds) / 60),
                    'duration_seconds' => $durationSeconds,
                    'type' => 'video',
                    'is_completed' => $isCompleted,
                    'is_locked' => $isLocked,
                    'watch_percentage' => $watchPercentage,
                    'progress_percentage' => $watchPercentage,
                    'watched_seconds' => $watchedSeconds,
                    'model_id' => $item['item_id'],
                    'model_type' => 'lecture',
                    'course_chapter_id' => $chapter['chapter_id'],
                ];
            }

            $sections[] = [
                'id' => $chapter['chapter_id'],
                'title' => $chapter['chapter_name'] ?? '',
                'lessons' => $lessons,
            ];
        }

        ApiResponseService::successResponse('تم جلب منهج الدورة', [
            'course_id' => $course->id,
            'sections' => $sections,
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'progress_percentage' => (float) ($detailed['course']['progress_percentage'] ?? 0),
        ]);
    }
}
