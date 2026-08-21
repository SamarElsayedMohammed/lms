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

        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));

        // 1. Authoritative union of all active learning pairs: orders, tracked curriculum, progress, tracks
        $purchased = DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->selectRaw('orders.user_id as user_id, order_courses.course_id as course_id, orders.created_at as enrolled_at');

        $tracked = DB::table('user_curriculum_trackings')
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->selectRaw('user_curriculum_trackings.user_id as user_id, course_chapters.course_id as course_id, user_curriculum_trackings.created_at as enrolled_at');

        $progressed = DB::table('user_course_progress')
            ->selectRaw('user_course_progress.user_id as user_id, user_course_progress.course_id as course_id, user_course_progress.created_at as enrolled_at');

        $trackAssigned = DB::table('user_course_tracks')
            ->selectRaw('user_course_tracks.user_id as user_id, user_course_tracks.course_id as course_id, user_course_tracks.created_at as enrolled_at');

        $union = $purchased->union($tracked)->union($progressed)->union($trackAssigned);

        $basePairs = DB::query()
            ->fromSub($union, 'u_pairs')
            ->selectRaw('user_id, course_id, MIN(enrolled_at) as enrolled_at')
            ->groupBy('user_id', 'course_id');

        $query = DB::query()
            ->fromSub($basePairs, 'sc')
            ->join('users', 'sc.user_id', '=', 'users.id')
            ->join('courses', 'sc.course_id', '=', 'courses.id')
            ->leftJoin('user_course_progress as ucp', function ($join) {
                $join->on('sc.user_id', '=', 'ucp.user_id')
                     ->on('sc.course_id', '=', 'ucp.course_id');
            })
            ->selectRaw('
                sc.user_id,
                sc.course_id,
                sc.enrolled_at,
                users.name as student_name,
                users.email as student_email,
                courses.title as course_title,
                ucp.id as progress_id,
                ucp.progress_percentage,
                ucp.status as progress_status,
                ucp.last_accessed_at
            ');

        if ($request->filled('course_id')) {
            $query->where('sc.course_id', (int) $request->course_id);
        }

        if ($request->filled('student')) {
            $search = '%' . trim((string) $request->student) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', $search)
                  ->orWhere('users.email', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'completed') {
                $query->where(function ($q) {
                    $q->where('ucp.status', 'completed')
                      ->orWhere('ucp.progress_percentage', '>=', 100);
                });
            } elseif ($status === 'in_progress') {
                $query->where(function ($q) {
                    $q->where(function ($st) {
                        $st->where('ucp.status', 'in_progress')
                           ->orWhere('ucp.progress_percentage', '>', 0);
                    })->where(function ($lt) {
                        $lt->where('ucp.progress_percentage', '<', 100)
                           ->orWhereNull('ucp.progress_percentage');
                    })->where('ucp.status', '!=', 'completed');
                });
            } elseif ($status === 'not_started') {
                $query->where(function ($q) {
                    $q->whereNull('ucp.id')
                      ->orWhere('ucp.status', 'not_started')
                      ->orWhere(function ($st) {
                          $st->where('ucp.progress_percentage', '=', 0)
                             ->where('ucp.status', '!=', 'completed');
                      });
                });
            }
        }

        if ($request->filled('from_date')) {
            $from = Carbon::parse($request->from_date)->startOfDay();
            $query->where(function ($q) use ($from) {
                $q->where('ucp.last_accessed_at', '>=', $from)
                  ->orWhere('sc.enrolled_at', '>=', $from);
            });
        }

        if ($request->filled('to_date')) {
            $to = Carbon::parse($request->to_date)->endOfDay();
            $query->where(function ($q) use ($to) {
                $q->where('ucp.last_accessed_at', '<=', $to)
                  ->orWhere('sc.enrolled_at', '<=', $to);
            });
        }

        $paginator = $query->orderByDesc('sc.enrolled_at')->paginate($perPage);
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

        $userIds = $items->map(fn ($r) => (int) $r->user_id)->unique()->filter()->values()->all();
        $courseIds = $items->map(fn ($r) => (int) $r->course_id)->unique()->filter()->values()->all();

        // 1. Batch load all lectures for the page courses
        $lectures = CourseChapterLecture::join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->whereIn('course_chapters.course_id', $courseIds)
            ->select('course_chapter_lectures.id', 'course_chapter_lectures.title', 'course_chapters.course_id')
            ->get();
        $lectureMap = $lectures->keyBy('id');
        $allLectureIds = $lectures->pluck('id')->all();

        // 2. Batch load video progress for page users & lectures
        $videoProgressByUser = collect();
        if (!empty($allLectureIds) && !empty($userIds)) {
            $videoProgressByUser = VideoProgress::whereIn('user_id', $userIds)
                ->whereIn('lecture_id', $allLectureIds)
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('user_id');
        }

        // 3. Batch load curriculum tracking for page users & courses
        $trackingByUserCourse = UserCurriculumTracking::join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->whereIn('user_curriculum_trackings.user_id', $userIds)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->select('user_curriculum_trackings.*', 'course_chapters.course_id')
            ->orderByDesc('user_curriculum_trackings.updated_at')
            ->get()
            ->groupBy(fn ($t) => "{$t->user_id}_{$t->course_id}");

        // 4. Batch fallback item counts
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
            $lectureMap, $videoProgressByUser, $trackingByUserCourse, $curriculumCounts, $quizCounts
        ) {
            $userId = (int) $row->user_id;
            $courseId = (int) $row->course_id;
            $pairKey = "{$userId}_{$courseId}";

            if ($row->progress_percentage !== null) {
                $progressFloat = (float) $row->progress_percentage;
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
            if ($progress >= 100 || $row->progress_status === 'completed') {
                $status = 'completed';
            } elseif ($progress > 0 || $row->progress_status === 'in_progress') {
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

            if ($row->last_accessed_at !== null) {
                if ($lastActivity === null || Carbon::parse($row->last_accessed_at) > $lastActivity) {
                    $lastActivity = $row->last_accessed_at;
                }
            }

            if ($currentLesson === null && $status === 'not_started') {
                $currentLesson = 'Not Started';
            }

            return [
                'id'             => (int) ($row->progress_id ?? ($courseId * 1000000 + $userId)),
                'student_name'   => $row->student_name ?? 'Unknown',
                'email'          => $row->student_email ?? 'Unknown',
                'course_name'    => $row->course_title ?? 'Unknown',
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
