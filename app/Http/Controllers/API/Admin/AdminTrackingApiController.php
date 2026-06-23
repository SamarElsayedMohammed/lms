<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\OrderCourse;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Admin student tracking API for the Next.js dashboard.
 *
 * GET /api/admin/tracking
 */
final class AdminTrackingApiController extends AdminCrudApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:100',
            'student'   => 'nullable|string|max:255',
            'course_id' => 'nullable|integer|exists:courses,id',
            'from_date' => 'nullable|date',
            'to_date'   => 'nullable|date|after_or_equal:from_date',
            'status'    => 'nullable|in:completed,in_progress,not_started',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $perPage = (int) $request->input('per_page', 15);
        $currentPage = (int) $request->input('page', 1);

        $query = OrderCourse::query()
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('courses', 'order_courses.course_id', '=', 'courses.id')
            ->where('orders.status', 'completed')
            ->whereNull('users.deleted_at')
            ->select([
                'order_courses.id',
                'users.id as user_id',
                'users.name as student_name',
                'users.email',
                'courses.id as course_id',
                'courses.title as course_name',
            ]);

        if ($request->filled('course_id')) {
            $query->where('order_courses.course_id', (int) $request->course_id);
        }

        if ($request->filled('student')) {
            $search = $request->student;
            $query->where(static function ($q) use ($search): void {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $enrollments = $query->orderByDesc('order_courses.id')->get();

        $rows = $enrollments->map(function ($row) {
            $metrics = $this->resolveEnrollmentMetrics((int) $row->user_id, (int) $row->course_id);

            return [
                'id'             => (int) $row->id,
                'student_name'   => $row->student_name,
                'email'          => $row->email,
                'course_name'    => $row->course_name,
                'course_id'      => (int) $row->course_id,
                'current_lesson' => $metrics['current_lesson'],
                'progress'       => $metrics['progress'],
                'status'         => $metrics['status'],
                'last_activity'  => $metrics['last_activity'],
            ];
        });

        if ($request->filled('status')) {
            $rows = $rows->filter(fn (array $row) => $row['status'] === $request->status)->values();
        }

        if ($request->filled('from_date')) {
            $from = Carbon::parse($request->from_date)->startOfDay();
            $rows = $rows->filter(static function (array $row) use ($from): bool {
                if ($row['last_activity'] === null) {
                    return false;
                }

                return Carbon::parse($row['last_activity'])->gte($from);
            })->values();
        }

        if ($request->filled('to_date')) {
            $to = Carbon::parse($request->to_date)->endOfDay();
            $rows = $rows->filter(static function (array $row) use ($to): bool {
                if ($row['last_activity'] === null) {
                    return false;
                }

                return Carbon::parse($row['last_activity'])->lte($to);
            })->values();
        }

        $total = $rows->count();
        $items = $rows->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'data'         => $paginator->items(),
            ],
        ]);
    }

    /**
     * @return array{progress: int, status: string, current_lesson: string|null, last_activity: string|null}
     */
    private function resolveEnrollmentMetrics(int $userId, int $courseId): array
    {
        $cached = UserCourseProgress::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        $progressFloat = $cached !== null
            ? (float) $cached->progress_percentage
            : $this->calculateProgressFromCurriculum($userId, $courseId);

        $progress = (int) round(min(100, max(0, $progressFloat)));

        $status = 'not_started';
        if ($progress >= 100) {
            $status = 'completed';
        } elseif ($progress > 0) {
            $status = 'in_progress';
        }

        $lectureIds = CourseChapterLecture::query()
            ->whereHas('chapter', static fn ($q) => $q->where('course_id', $courseId))
            ->pluck('id');

        $latestVideo = VideoProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lecture_id', $lectureIds)
            ->orderByDesc('updated_at')
            ->first();

        $latestTracking = UserCurriculumTracking::query()
            ->where('user_id', $userId)
            ->whereHas('chapter', static fn ($q) => $q->where('course_id', $courseId))
            ->orderByDesc('updated_at')
            ->first();

        $currentLesson = null;
        $lastActivity = null;

        if ($latestVideo !== null) {
            $lecture = CourseChapterLecture::find($latestVideo->lecture_id);
            $currentLesson = $lecture?->title;
            $lastActivity = $latestVideo->updated_at;
        }

        if ($latestTracking !== null) {
            if ($lastActivity === null || $latestTracking->updated_at > $lastActivity) {
                $lastActivity = $latestTracking->updated_at;
            }

            if ($latestTracking->model_type === CourseChapterLecture::class) {
                $lecture = CourseChapterLecture::find($latestTracking->model_id);
                if ($lecture !== null) {
                    $currentLesson = $lecture->title;
                }
            }
        }

        if ($cached?->last_accessed_at !== null) {
            if ($lastActivity === null || $cached->last_accessed_at > $lastActivity) {
                $lastActivity = $cached->last_accessed_at;
            }
        }

        if ($currentLesson === null && $status === 'not_started') {
            $currentLesson = 'Not Started';
        }

        return [
            'progress'       => $progress,
            'status'         => $status,
            'current_lesson' => $currentLesson,
            'last_activity'  => $lastActivity?->toIso8601String(),
        ];
    }

    private function calculateProgressFromCurriculum(int $userId, int $courseId): float
    {
        $chapterIds = CourseChapter::where('course_id', $courseId)->pluck('id');

        if ($chapterIds->isEmpty()) {
            return 0.0;
        }

        $totalItems = DB::table('course_chapter_lectures')
            ->whereIn('course_chapter_id', $chapterIds)
            ->where('is_active', 1)
            ->count()
            + DB::table('course_chapter_quizzes')
                ->whereIn('course_chapter_id', $chapterIds)
                ->where('is_active', 1)
                ->count();

        if ($totalItems === 0) {
            return 0.0;
        }

        $completedItems = UserCurriculumTracking::query()
            ->where('user_id', $userId)
            ->whereIn('course_chapter_id', $chapterIds)
            ->where('status', 'completed')
            ->count();

        return ($completedItems / $totalItems) * 100;
    }
}
