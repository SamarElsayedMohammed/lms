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
        $items = collect($paginator->items());

        if ($items->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'data'         => [],
                ],
            ]);
        }

        $userIds = $items->map(fn ($r) => (int) ($r->order->user_id ?? 0))->unique()->filter()->values()->all();
        $courseIds = $items->map(fn ($r) => (int) $r->course_id)->unique()->filter()->values()->all();

        // 1. Batch load cached UserCourseProgress (NO N+1)
        $progressMap = UserCourseProgress::whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->get()
            ->keyBy(fn ($p) => "{$p->user_id}_{$p->course_id}");

        // 2. Batch load all lectures for the page courses
        $lectures = CourseChapterLecture::join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->whereIn('course_chapters.course_id', $courseIds)
            ->select('course_chapter_lectures.id', 'course_chapter_lectures.title', 'course_chapters.course_id')
            ->get();
        $lectureMap = $lectures->keyBy('id');
        $allLectureIds = $lectures->pluck('id')->all();

        // 3. Batch load video progress for page users & lectures
        $videoProgressByUser = collect();
        if (!empty($allLectureIds) && !empty($userIds)) {
            $videoProgressByUser = VideoProgress::whereIn('user_id', $userIds)
                ->whereIn('lecture_id', $allLectureIds)
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('user_id');
        }

        // 4. Batch load curriculum tracking for page users & courses
        $trackingByUserCourse = UserCurriculumTracking::join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->whereIn('user_curriculum_trackings.user_id', $userIds)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->select('user_curriculum_trackings.*', 'course_chapters.course_id')
            ->orderByDesc('user_curriculum_trackings.updated_at')
            ->get()
            ->groupBy(fn ($t) => "{$t->user_id}_{$t->course_id}");

        // 5. Batch fallback item counts
        $curriculumCounts = DB::table('course_chapter_lectures as ccl')
            ->join('course_chapters as cc', 'ccl.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $courseIds)
            ->where('ccl.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $quizCounts = DB::table('course_chapter_quizzes as ccq')
            ->join('course_chapters as cc', 'ccq.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $courseIds)
            ->where('ccq.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $rows = $items->map(function ($row) use (
            $progressMap, $lectureMap, $videoProgressByUser, $trackingByUserCourse, $curriculumCounts, $quizCounts
        ) {
            $userId = (int) ($row->order->user_id ?? 0);
            $courseId = (int) $row->course_id;
            $pairKey = "{$userId}_{$courseId}";

            $cached = $progressMap->get($pairKey);
            if ($cached !== null) {
                $progressFloat = (float) $cached->progress_percentage;
            } else {
                $totalItems = ($curriculumCounts[$courseId] ?? 0) + ($quizCounts[$courseId] ?? 0);
                if ($totalItems > 0) {
                    $trackings = $trackingByUserCourse->get($pairKey, collect());
                    $completedCount = $trackings->where('status', 'completed')->count();
                    $progressFloat = ($completedCount / $totalItems) * 100;
                } else {
                    $progressFloat = 0.0;
                }
            }

            $progress = (int) round(min(100, max(0, $progressFloat)));
            $status = 'not_started';
            if ($progress >= 100) {
                $status = 'completed';
            } elseif ($progress > 0) {
                $status = 'in_progress';
            }

            // Determine latest video and tracking for this student & course
            $userVideos = $videoProgressByUser->get($userId, collect());
            $courseVideos = $userVideos->filter(function ($vp) use ($lectureMap, $courseId) {
                $lec = $lectureMap->get($vp->lecture_id);
                return $lec && (int) $lec->course_id === $courseId;
            });
            $latestVideo = $courseVideos->first();

            $courseTrackings = $trackingByUserCourse->get($pairKey, collect());
            $latestTracking = $courseTrackings->first();

            $currentLesson = null;
            $lastActivity = null;

            if ($latestVideo !== null) {
                $lecture = $lectureMap->get($latestVideo->lecture_id);
                $currentLesson = $lecture?->title;
                $lastActivity = $latestVideo->updated_at;
            }

            if ($latestTracking !== null) {
                if ($lastActivity === null || $latestTracking->updated_at > $lastActivity) {
                    $lastActivity = $latestTracking->updated_at;
                }

                if ($latestTracking->model_type === CourseChapterLecture::class || str_ends_with((string) $latestTracking->model_type, 'CourseChapterLecture')) {
                    $lecture = $lectureMap->get($latestTracking->model_id);
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
                'id'             => (int) $row->id,
                'student_name'   => $row->order->user->name ?? 'Unknown',
                'email'          => $row->order->user->email ?? 'Unknown',
                'course_name'    => $row->course->title ?? 'Unknown',
                'course_id'      => $courseId,
                'current_lesson' => $currentLesson,
                'progress'       => $progress,
                'status'         => $status,
                'last_activity'  => $lastActivity instanceof Carbon ? $lastActivity->toIso8601String() : ($lastActivity ? Carbon::parse($lastActivity)->toIso8601String() : null),
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
}
