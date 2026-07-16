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

        $query = OrderCourse::with(['order.user', 'course'])
            ->whereHas('order', function ($q) {
                $q->where('status', 'completed')
                  ->whereHas('user');
            })
            ->whereHas('course');

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        if ($request->filled('student')) {
            $search = $request->student;
            $query->whereHas('order.user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'not_started') {
                $query->whereNotExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('user_course_progress')
                        ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                        ->whereColumn('user_course_progress.course_id', 'order_courses.course_id')
                        ->whereColumn('user_course_progress.user_id', 'orders.user_id')
                        ->where(function ($st) {
                            $st->where('user_course_progress.status', '!=', 'not_started')
                               ->orWhere('user_course_progress.progress_percentage', '>', 0);
                        });
                });
            } elseif ($status === 'completed') {
                $query->whereExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('user_course_progress')
                        ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                        ->whereColumn('user_course_progress.course_id', 'order_courses.course_id')
                        ->whereColumn('user_course_progress.user_id', 'orders.user_id')
                        ->where(function ($st) {
                            $st->where('user_course_progress.status', 'completed')
                               ->orWhere('user_course_progress.progress_percentage', '>=', 100);
                        });
                });
            } elseif ($status === 'in_progress') {
                $query->whereExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('user_course_progress')
                        ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                        ->whereColumn('user_course_progress.course_id', 'order_courses.course_id')
                        ->whereColumn('user_course_progress.user_id', 'orders.user_id')
                        ->where(function ($st) {
                            $st->where('user_course_progress.status', 'in_progress')
                               ->orWhere(function ($percent) {
                                   $percent->where('user_course_progress.progress_percentage', '>', 0)
                                           ->where('user_course_progress.progress_percentage', '<', 100);
                               });
                        });
                });
            }
        }

        if ($request->filled('from_date')) {
            $from = Carbon::parse($request->from_date)->startOfDay();
            $query->whereExists(function ($sub) use ($from) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('user_course_progress')
                    ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                    ->whereColumn('user_course_progress.course_id', 'order_courses.course_id')
                    ->whereColumn('user_course_progress.user_id', 'orders.user_id')
                    ->where('user_course_progress.last_accessed_at', '>=', $from);
            });
        }

        if ($request->filled('to_date')) {
            $to = Carbon::parse($request->to_date)->endOfDay();
            $query->whereExists(function ($sub) use ($to) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('user_course_progress')
                    ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                    ->whereColumn('user_course_progress.course_id', 'order_courses.course_id')
                    ->whereColumn('user_course_progress.user_id', 'orders.user_id')
                    ->where('user_course_progress.last_accessed_at', '<=', $to);
            });
        }

        $paginator = $query->orderByDesc('order_courses.id')->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($row) {
            $userId = $row->order->user_id ?? 0;
            $metrics = $this->resolveEnrollmentMetrics((int) $userId, (int) $row->course_id);

            return [
                'id'             => (int) $row->id,
                'student_name'   => $row->order->user->name ?? 'Unknown',
                'email'          => $row->order->user->email ?? 'Unknown',
                'course_name'    => $row->course->title ?? 'Unknown',
                'course_id'      => (int) $row->course_id,
                'current_lesson' => $metrics['current_lesson'],
                'progress'       => $metrics['progress'],
                'status'         => $metrics['status'],
                'last_activity'  => $metrics['last_activity'],
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'data'         => $rows,
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
