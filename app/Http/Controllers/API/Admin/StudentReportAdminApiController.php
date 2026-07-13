<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Services\ApiResponseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Student Reports Admin API Controller
 *
 * Provides comprehensive student tracking and progress reports for admins.
 */
class StudentReportAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/admin/reports/students
     * Comprehensive students overview report
     */
    public function index(Request $request): JsonResponse
    {
        $request->merge([
            \'date_from\' => $request->date_from ?? $request->from_date,
            \'date_to\' => $request->date_to ?? $request->to_date
        ]);
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date|after_or_equal:date_from',
            'course_id'  => 'nullable|exists:courses,id',
            'status'     => 'nullable|in:not_started,in_progress,completed',
            'per_page'   => 'nullable|integer|min:1|max:100',
            'report_type' => 'nullable|in:summary,detailed',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $reportType = $request->input('report_type', 'summary');

        if ($reportType === 'detailed') {
            return $this->jsonSuccess('Student reports retrieved', $this->getDetailedReport($request));
        }

        return $this->jsonSuccess('Student reports retrieved', $this->getSummaryReport($request));
    }

    /**
     * GET /api/admin/reports/students/{id}
     * Detailed tracking report for a single student
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $user = User::find($id);
        if (! $user) {
            return $this->jsonError('Student not found', 404);
        }

        $purchasedIds = OrderCourse::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'completed');
        })->pluck('course_id')->unique()->toArray();

        $trackedIds = UserCurriculumTracking::where('user_id', $user->id)
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->pluck('course_chapters.course_id')->unique()->toArray();

        $enrolledCourseIds = array_unique(array_merge($purchasedIds, $trackedIds));

        $totalEnrolled   = count($enrolledCourseIds);
        $completedCourses = 0;
        $inProgressCourses = 0;
        $notStartedCourses = 0;
        $courseDetails = [];

        foreach ($enrolledCourseIds as $courseId) {
            $course   = Course::with('category', 'user')->find($courseId);
            $progress = $this->calculateCourseProgress($user->id, $courseId);

            $status = 'not_started';
            if ($progress >= 100) {
                $status = 'completed';
                $completedCourses++;
            } elseif ($progress > 0) {
                $status = 'in_progress';
                $inProgressCourses++;
            } else {
                $notStartedCourses++;
            }

            $lastActivity = UserCurriculumTracking::where('user_id', $user->id)
                ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
                ->latest('updated_at')
                ->value('updated_at');

            $completedItems = UserCurriculumTracking::where('user_id', $user->id)
                ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
                ->where('status', 'completed')
                ->count();

            $courseDetails[] = [
                'course_id'         => $courseId,
                'title'             => $course?->title ?? 'N/A',
                'category'          => $course?->category?->name ?? 'N/A',
                'instructor'        => $course?->user?->name ?? 'N/A',
                'progress_percent'  => round($progress, 2),
                'status'            => $status,
                'completed_items'   => $completedItems,
                'last_activity_at'  => $lastActivity,
            ];
        }

        $data = [
            'student' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->mobile ?? null,
                'joined_at' => $user->created_at?->toDateString(),
            ],
            'summary' => [
                'total_enrolled'    => $totalEnrolled,
                'completed_courses' => $completedCourses,
                'in_progress_courses' => $inProgressCourses,
                'not_started_courses' => $notStartedCourses,
                'completion_rate'   => $totalEnrolled > 0
                    ? round(($completedCourses / $totalEnrolled) * 100, 2)
                    : 0,
            ],
            'courses' => $courseDetails,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];

        return $this->jsonSuccess('Student tracking report retrieved', $data);
    }

    /**
     * GET /api/admin/reports/students/completion-stats
     * Aggregate completion statistics across all students
     */
    public function completionStats(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $brackets = [
            'no_courses' => 0,
            'not_started' => 0,
            'in_progress' => 0,
            'all_completed' => 0,
        ];

        $studentIds = User::where(function ($q) {
            $q->whereHas('orders', fn ($oq) => $oq->where('status', 'completed'))
              ->orWhereHas('subscriptions');
        })->pluck('id');

        $totalCompletedCourseEnrollments = 0;
        $totalInProgressCourseEnrollments = 0;
        $courseCompletionCounts = [];

        foreach ($studentIds as $userId) {
            $purchasedIds = OrderCourse::whereHas('order', fn ($q) => $q->where('user_id', $userId)->where('status', 'completed'))
                ->pluck('course_id')
                ->unique()
                ->toArray();

            $trackedIds = UserCurriculumTracking::where('user_id', $userId)
                ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                ->pluck('course_chapters.course_id')
                ->unique()
                ->toArray();

            $enrolledIds = array_unique(array_merge($purchasedIds, $trackedIds));

            if ($enrolledIds === []) {
                $brackets['no_courses']++;
                continue;
            }

            $completed = 0;
            $inProgress = 0;

            foreach ($enrolledIds as $courseId) {
                $progress = $this->calculateCourseProgress((int) $userId, (int) $courseId);

                if ($progress >= 100) {
                    $completed++;
                    $courseCompletionCounts[$courseId] = ($courseCompletionCounts[$courseId] ?? 0) + 1;
                } elseif ($progress > 0) {
                    $inProgress++;
                }
            }

            $totalCompletedCourseEnrollments += $completed;
            $totalInProgressCourseEnrollments += $inProgress;

            if ($completed === count($enrolledIds)) {
                $brackets['all_completed']++;
            } elseif ($inProgress > 0) {
                $brackets['in_progress']++;
            } else {
                $brackets['not_started']++;
            }
        }

        $topCompletedCourses = collect($courseCompletionCounts)
            ->sortDesc()
            ->take(10)
            ->map(function ($completions, $courseId) {
                $course = Course::find($courseId);

                return [
                    'course_id' => (int) $courseId,
                    'title' => $course?->title ?? 'N/A',
                    'completions' => $completions,
                ];
            })
            ->values();

        // Updated trend query to combine both order and subscription creations
        $trendOrders = DB::table('orders as o')
            ->where('o.status', 'completed')
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, o.user_id");

        $trendSubs = DB::table('subscriptions as s')
            ->where('s.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(s.created_at, '%Y-%m') as month, s.user_id");

        $combinedTrend = DB::table(DB::raw("({$trendOrders->union($trendSubs)->toSql()}) as combined"))
            ->mergeBindings($trendOrders)
            ->mergeBindings($trendSubs)
            ->selectRaw("month, COUNT(DISTINCT combined.user_id) as new_students")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->jsonSuccess('Completion statistics retrieved', [
            'total_students' => $studentIds->count(),
            'students_with_enrollments' => $studentIds->count() - $brackets['no_courses'],
            'completion_brackets' => $brackets,
            'total_completed_course_enrollments' => $totalCompletedCourseEnrollments,
            'total_in_progress_course_enrollments' => $totalInProgressCourseEnrollments,
            'top_completed_courses' => $topCompletedCourses,
            'monthly_enrollment_trend' => $combinedTrend,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function getSummaryReport(Request $request): array
    {
        $query = User::where(function ($q) {
            $q->whereHas('orders', fn($oq) => $oq->where('status', 'completed'))
              ->orWhereHas('subscriptions');
        });

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $students = $query->select('id', 'name', 'email', 'created_at')->paginate($perPage);

        $items = collect($students->items())->map(function ($user) {
            $purchasedIds = OrderCourse::whereHas('order', fn($q) => $q->where('user_id', $user->id)->where('status', 'completed'))
                ->pluck('course_id')->unique()->toArray();

            $trackedIds = UserCurriculumTracking::where('user_id', $user->id)
                ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                ->pluck('course_chapters.course_id')->unique()->toArray();

            $allCourseIds = array_unique(array_merge($purchasedIds, $trackedIds));

            $completed = 0;
            $inProgress = 0;
            foreach ($allCourseIds as $courseId) {
                $progress = $this->calculateCourseProgress($user->id, $courseId);
                if ($progress >= 100) {
                    $completed++;
                } elseif ($progress > 0) {
                    $inProgress++;
                }
            }

            $notStarted = max(0, count($allCourseIds) - $completed - $inProgress);

            return [
                'student_id'        => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'total_enrolled'    => count($allCourseIds),
                'completed_courses' => $completed,
                'in_progress'       => $inProgress,
                'not_started'       => $notStarted,
                'open_courses'      => count($allCourseIds) - $completed,
                'completion_rate'   => count($allCourseIds) > 0
                    ? round(($completed / count($allCourseIds)) * 100, 2)
                    : 0,
                'joined_at'         => $user->created_at?->toDateString(),
            ];
        });

        if ($request->filled('status')) {
            $items = $items->filter(function ($item) use ($request) {
                return match ($request->status) {
                    'completed' => $item['completed_courses'] > 0,
                    'in_progress' => $item['in_progress'] > 0,
                    'not_started' => $item['not_started'] > 0,
                    default => true,
                };
            })->values();
        }

        return [
            'data'         => $items,
            'current_page' => $students->currentPage(),
            'last_page'    => $students->lastPage(),
            'per_page'     => $students->perPage(),
            'total'        => $students->total(),
        ];
    }

    private function getDetailedReport(Request $request): array
    {
        // Returns per-course enrollment + completion detail per student
        $purchasedCourses = DB::table('order_courses as oc')
            ->join('orders as o', 'oc.order_id', '=', 'o.id')
            ->join('users as u', 'o.user_id', '=', 'u.id')
            ->join('courses as c', 'oc.course_id', '=', 'c.id')
            ->where('o.status', 'completed')
            ->select(
                'u.id as user_id', 'u.name as student_name', 'u.email',
                'c.id as course_id', 'c.title as course_title',
                'o.created_at as enrolled_at'
            );

        $trackedCourses = DB::table('user_curriculum_trackings as uct')
            ->join('users as u', 'uct.user_id', '=', 'u.id')
            ->join('course_chapters as ch', 'uct.course_chapter_id', '=', 'ch.id')
            ->join('courses as c', 'ch.course_id', '=', 'c.id')
            ->select(
                'u.id as user_id', 'u.name as student_name', 'u.email',
                'c.id as course_id', 'c.title as course_title',
                'uct.created_at as enrolled_at'
            );

        $unionQuery = $purchasedCourses->union($trackedCourses);

        $query = DB::table(DB::raw("({$unionQuery->toSql()}) as combined"))
            ->mergeBindings($unionQuery)
            ->select(
                'user_id', 'student_name', 'email',
                'course_id', 'course_title',
                DB::raw('MIN(enrolled_at) as enrolled_at')
            )
            ->groupBy('user_id', 'student_name', 'email', 'course_id', 'course_title');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('enrolled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('enrolled_at', '<=', $request->date_to);
        }

        $perPage  = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->orderByDesc('enrolled_at')->paginate($perPage);

        $items = collect($paginated->items())->map(function ($row) {
            $progress = $this->calculateCourseProgress((int) $row->user_id, (int) $row->course_id);
            $status = 'not_started';
            if ($progress >= 100) {
                $status = 'completed';
            } elseif ($progress > 0) {
                $status = 'in_progress';
            }

            return [
                'student_id'       => $row->user_id,
                'student_name'     => $row->student_name,
                'email'            => $row->email,
                'course_id'        => $row->course_id,
                'course_title'     => $row->course_title,
                'enrolled_at'      => Carbon::parse($row->enrolled_at)->toDateTimeString(),
                'progress_percent' => round($progress, 2),
                'status'           => $status,
            ];
        });

        if ($request->filled('status')) {
            $items = $items->filter(fn ($item) => $item['status'] === $request->status)->values();
        }

        return [
            'data'         => $items,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
        ];
    }

    private function calculateCourseProgress(int $userId, int $courseId): float
    {
        $totalItems = DB::table('course_chapter_lectures')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapter_lectures.is_active', 1)
            ->count()
            + DB::table('course_chapter_quizzes')
            ->join('course_chapters', 'course_chapter_quizzes.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapter_quizzes.is_active', 1)
            ->count();

        if ($totalItems === 0) {
            return 0.0;
        }

        $completedItems = UserCurriculumTracking::where('user_id', $userId)
            ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
            ->where('status', 'completed')
            ->count();

        return ($completedItems / $totalItems) * 100;
    }
}
