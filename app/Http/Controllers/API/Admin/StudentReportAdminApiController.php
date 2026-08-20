<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Services\ApiResponseService;
use App\Support\RoleManager;
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
    private function baseStudentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return RoleManager::applyRoleFilter(User::query(), 'student');
    }

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
            'date_from' => $request->date_from ?? $request->from_date,
            'date_to' => $request->date_to ?? $request->to_date
        ]);
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date|after_or_equal:date_from',
            'course_id'  => 'nullable|exists:courses,id',
            'instructor_id' => 'nullable|exists:users,id',
            'category_id' => 'nullable|exists:categories,id',
            'search' => 'nullable|string|max:255',
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

        // ── Resolve enrolled course IDs (batch, no N+1) ────────────────────
        $purchasedIds = OrderCourse::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'completed');
        })->pluck('course_id')->unique()->toArray();

        $trackedIds = UserCurriculumTracking::where('user_curriculum_trackings.user_id', $user->id)
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->pluck('course_chapters.course_id')->unique()->toArray();

        $enrolledCourseIds = array_unique(array_merge($purchasedIds, $trackedIds));

        if (empty($enrolledCourseIds)) {
            return $this->jsonSuccess('Student tracking report retrieved', [
                'student'      => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'phone'     => $user->mobile ?? null,
                    'joined_at' => $user->created_at?->toDateString(),
                ],
                'summary'      => [
                    'total_enrolled'      => 0,
                    'completed_courses'   => 0,
                    'in_progress_courses' => 0,
                    'not_started_courses' => 0,
                    'completion_rate'     => 0,
                ],
                'courses'      => [],
                'generated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        // ── Batch-load everything needed for all courses at once ───────────

        // Courses metadata
        $courses = Course::with('category', 'user')
            ->whereIn('id', $enrolledCourseIds)
            ->get()
            ->keyBy('id');

        // Total items per course (lectures + quizzes + assignments + resources)
        $totalItemsMap = DB::table('course_chapter_lectures as ccl')
            ->join('course_chapters as cc', 'ccl.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->where('ccl.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $quizMap = DB::table('course_chapter_quizzes as ccq')
            ->join('course_chapters as cc', 'ccq.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->where('ccq.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $assignmentMap = DB::table('course_chapter_assignments as cca')
            ->join('course_chapters as cc', 'cca.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->where('cca.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $resourceMap = DB::table('course_chapter_resources as ccr')
            ->join('course_chapters as cc', 'ccr.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->where('ccr.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        // Completed tracking items per course for this student
        $completedItemsMap = UserCurriculumTracking::where('user_curriculum_trackings.user_id', $user->id)
            ->join('course_chapters as cc', 'user_curriculum_trackings.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->where('user_curriculum_trackings.status', 'completed')
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        // Last activity per course
        $lastActivityMap = UserCurriculumTracking::where('user_curriculum_trackings.user_id', $user->id)
            ->join('course_chapters as cc', 'user_curriculum_trackings.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $enrolledCourseIds)
            ->selectRaw('cc.course_id, MAX(user_curriculum_trackings.updated_at) as last_at')
            ->groupBy('cc.course_id')
            ->pluck('last_at', 'course_id');

        // Enrollment date per course (first completed order)
        $enrolledAtMap = DB::table('order_courses as oc')
            ->join('orders as o', 'oc.order_id', '=', 'o.id')
            ->where('o.user_id', $user->id)
            ->where('o.status', 'completed')
            ->whereIn('oc.course_id', $enrolledCourseIds)
            ->selectRaw('oc.course_id, MIN(o.created_at) as enrolled_at')
            ->groupBy('oc.course_id')
            ->pluck('enrolled_at', 'course_id');

        // ── Build course details from in-memory maps (zero extra queries) ──
        $totalEnrolled     = count($enrolledCourseIds);
        $completedCourses  = 0;
        $inProgressCourses = 0;
        $notStartedCourses = 0;
        $courseDetails     = [];

        foreach ($enrolledCourseIds as $courseId) {
            $total = ($totalItemsMap[$courseId] ?? 0)
                   + ($quizMap[$courseId] ?? 0)
                   + ($assignmentMap[$courseId] ?? 0)
                   + ($resourceMap[$courseId] ?? 0);

            $completed = (int) ($completedItemsMap[$courseId] ?? 0);
            $progress  = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

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

            $course = $courses[$courseId] ?? null;

            $courseDetails[] = [
                'course_id'        => $courseId,
                'title'            => $course?->title ?? 'N/A',
                'category'         => $course?->category?->name ?? 'N/A',
                'instructor'       => $course?->user?->name ?? 'N/A',
                'progress_percent'    => $progress,
                'progress_percentage' => $progress,
                'status'              => $status,
                'completed_items'     => $completed,
                'total_items'         => $total,
                'last_activity_at'    => $lastActivityMap[$courseId] ?? null,
                'enrolled_at'         => isset($enrolledAtMap[$courseId])
                    ? Carbon::parse($enrolledAtMap[$courseId])->toDateTimeString()
                    : null,
            ];
        }

        return $this->jsonSuccess('Student tracking report retrieved', [
            'student'      => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->mobile ?? null,
                'joined_at' => $user->created_at?->toDateString(),
            ],
            'summary'      => [
                'total_enrolled'      => $totalEnrolled,
                'completed_courses'   => $completedCourses,
                'in_progress_courses' => $inProgressCourses,
                'not_started_courses' => $notStartedCourses,
                'completion_rate'     => $totalEnrolled > 0
                    ? round(($completedCourses / $totalEnrolled) * 100, 2)
                    : 0,
            ],
            'courses'      => $courseDetails,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * GET /api/admin/reports/students/completion-stats
     * Aggregate completion statistics across all students
     */
    public function completionStats(Request $request): JsonResponse
    {
        $request->merge([
            'date_from' => $request->date_from ?? $request->from_date,
            'date_to' => $request->date_to ?? $request->to_date,
        ]);
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'course_id' => 'nullable|exists:courses,id',
            'instructor_id' => 'nullable|exists:users,id',
            'category_id' => 'nullable|exists:categories,id',
        ]);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $eligibleCourseIds = $this->eligibleCourseIds($request);

        $studentQuery = $this->baseStudentQuery();
        $this->applyStudentScope($studentQuery, $request, $eligibleCourseIds, false);
        $totalStudentsCount = $studentQuery->count();
        $scopedStudentIds = (clone $studentQuery)->select('users.id');

        // Keep enrollment pairs inside SQL so report size does not control PHP memory usage.
        $enrollmentsQuery = DB::table('order_courses as oc')
            ->join('orders as o', 'oc.order_id', '=', 'o.id')
            ->where('o.status', 'completed')
            ->whereIn('o.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('oc.course_id', $eligibleCourseIds))
            ->select('o.user_id', 'oc.course_id');

        $trackingEnrollments = DB::table('user_curriculum_trackings as uct')
            ->join('course_chapters as cc', 'uct.course_chapter_id', '=', 'cc.id')
            ->whereIn('uct.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('cc.course_id', $eligibleCourseIds))
            ->select('uct.user_id', 'cc.course_id');

        $enrolledPairs = $enrollmentsQuery->union($trackingEnrollments);
        $progressByCourse = DB::table('user_course_progress as ucp')
            ->whereIn('ucp.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('ucp.course_id', $eligibleCourseIds))
            ->selectRaw("
                ucp.user_id,
                ucp.course_id,
                MAX(CASE WHEN ucp.status = 'completed' OR ucp.progress_percentage >= 100 THEN 1 ELSE 0 END) as is_completed,
                MAX(CASE WHEN ucp.status = 'in_progress' OR ucp.progress_percentage > 0 THEN 1 ELSE 0 END) as has_progress
            ")
            ->groupBy('ucp.user_id', 'ucp.course_id');

        $perStudentCompletion = DB::query()
            ->fromSub($enrolledPairs, 'enrollments')
            ->leftJoinSub($progressByCourse, 'progress', static function ($join): void {
                $join->on('progress.user_id', '=', 'enrollments.user_id')
                    ->on('progress.course_id', '=', 'enrollments.course_id');
            })
            ->selectRaw("
                enrollments.user_id,
                COUNT(*) as enrolled_courses,
                SUM(COALESCE(progress.is_completed, 0)) as completed_courses,
                SUM(COALESCE(progress.has_progress, 0)) as started_courses
            ")
            ->groupBy('enrollments.user_id');

        $bracketCounts = DB::query()
            ->fromSub($perStudentCompletion, 'student_completion')
            ->selectRaw("
                COUNT(*) as students_with_enrollments,
                SUM(CASE
                    WHEN completed_courses = enrolled_courses THEN 1
                    ELSE 0
                END) as all_completed,
                SUM(CASE
                    WHEN completed_courses < enrolled_courses AND started_courses > 0 THEN 1
                    ELSE 0
                END) as in_progress,
                SUM(CASE
                    WHEN started_courses = 0 THEN 1
                    ELSE 0
                END) as not_started
            ")
            ->first();

        $studentsWithEnrollments = (int) ($bracketCounts->students_with_enrollments ?? 0);
        $noCoursesCount = max(0, $totalStudentsCount - $studentsWithEnrollments);

        $brackets = [
            'no_courses'    => $noCoursesCount,
            'not_started'   => (int) ($bracketCounts->not_started ?? 0),
            'in_progress'   => (int) ($bracketCounts->in_progress ?? 0),
            'all_completed' => (int) ($bracketCounts->all_completed ?? 0),
        ];

        // Aggregate the scoped progress grain without loading individual rows.
        $progressCounts = DB::query()
            ->fromSub(clone $progressByCourse, 'progress')
            ->selectRaw("
                SUM(is_completed) as completed_cnt,
                SUM(CASE WHEN is_completed = 0 AND has_progress = 1 THEN 1 ELSE 0 END) as in_progress_cnt
            ")
            ->first();

        $totalCompletedCourseEnrollments = (int) ($progressCounts->completed_cnt ?? 0);
        $totalInProgressCourseEnrollments = (int) ($progressCounts->in_progress_cnt ?? 0);

        // Top completed courses query
        $topCompletedCourses = DB::table('user_course_progress as ucp')
            ->join('courses as c', 'ucp.course_id', '=', 'c.id')
            ->where(function ($q) {
                $q->where('ucp.status', 'completed')
                  ->orWhere('ucp.progress_percentage', '>=', 100);
            })
            ->whereIn('ucp.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('ucp.course_id', $eligibleCourseIds))
            ->select('c.id as course_id', 'c.title', DB::raw('COUNT(DISTINCT ucp.user_id) as completions'))
            ->groupBy('c.id', 'c.title')
            ->orderByDesc('completions')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'course_id'   => (int) $row->course_id,
                'title'       => $row->title,
                'completions' => (int) $row->completions,
            ])
            ->values();

        // Monthly enrollment trend (last 6 months)
        $monthOrderSql = \App\Services\Reports\ReportMoneySql::dateFormatSql('o.created_at', 'month');
        $monthSubSql = \App\Services\Reports\ReportMoneySql::dateFormatSql('s.created_at', 'month');

        $trendOrders = DB::table('orders as o')
            ->when($eligibleCourseIds !== null, fn ($q) => $q->join('order_courses as trend_oc', 'trend_oc.order_id', '=', 'o.id'))
            ->where('o.status', 'completed')
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->whereIn('o.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('trend_oc.course_id', $eligibleCourseIds))
            ->selectRaw("{$monthOrderSql} as month, o.user_id as user_id");

        $trendSubs = DB::table('subscriptions as s')
            ->where('s.created_at', '>=', Carbon::now()->subMonths(6))
            ->whereIn('s.user_id', clone $scopedStudentIds)
            ->when($eligibleCourseIds !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw("{$monthSubSql} as month, s.user_id as user_id");

        $combinedTrend = DB::query()
            ->fromSub($trendOrders->union($trendSubs), 'combined_trend')
            ->selectRaw('month, COUNT(*) as new_students')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(static fn ($row) => [
                'month'        => (string) $row->month,
                'new_students' => (int) $row->new_students,
            ])
            ->values();

        return $this->jsonSuccess('Completion statistics retrieved', array_merge([
            'total_students'                       => $totalStudentsCount,
            'students_with_enrollments'            => $studentsWithEnrollments,
            'completed_students'                   => $brackets['all_completed'],
            'completion_rate'                      => $studentsWithEnrollments > 0
                ? round(($brackets['all_completed'] / $studentsWithEnrollments) * 100, 2)
                : 0,
            'completion_brackets'                  => $brackets,
            'total_completed_course_enrollments'   => $totalCompletedCourseEnrollments,
            'total_in_progress_course_enrollments' => $totalInProgressCourseEnrollments,
            'top_completed_courses'                => $topCompletedCourses,
            'monthly_enrollment_trend'             => $combinedTrend,
            'generated_at'                         => Carbon::now()->toDateTimeString(),
        ], $this->reportingGrains()));
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Null means no course dimension was selected; an empty array means the
     * selected dimensions matched no courses and must match no students.
     */
    private function eligibleCourseIds(Request $request): ?array
    {
        if (! $request->filled('course_id')
            && ! $request->filled('instructor_id')
            && ! $request->filled('category_id')) {
            return null;
        }

        return Course::query()
            ->when($request->filled('course_id'), fn ($query) => $query->whereKey($request->course_id))
            ->when($request->filled('instructor_id'), fn ($query) => $query->where('user_id', $request->instructor_id))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->category_id))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function applyStudentScope($query, Request $request, ?array $eligibleCourseIds, bool $includeSearch = true): void
    {
        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('users.created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('users.created_at', '<=', $to);
        }
        if ($includeSearch && $request->filled('search')) {
            $search = '%' . trim((string) $request->search) . '%';
            $query->where(static fn ($students) => $students
                ->where('users.name', 'like', $search)
                ->orWhere('users.email', 'like', $search));
        }
        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'completed') {
                $query->whereExists(function ($sub) use ($eligibleCourseIds) {
                    $sub->selectRaw('1')
                        ->from('user_course_progress')
                        ->whereColumn('user_course_progress.user_id', 'users.id')
                        ->where(function ($st) {
                            $st->where('user_course_progress.status', 'completed')
                               ->orWhere('user_course_progress.progress_percentage', '>=', 100);
                        })
                        ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('user_course_progress.course_id', $eligibleCourseIds));
                });
            } elseif ($status === 'in_progress') {
                $query->whereExists(function ($sub) use ($eligibleCourseIds) {
                    $sub->selectRaw('1')
                        ->from('user_course_progress')
                        ->whereColumn('user_course_progress.user_id', 'users.id')
                        ->where(function ($st) {
                            $st->where('user_course_progress.status', 'in_progress')
                               ->orWhere(function ($pct) {
                                   $pct->where('user_course_progress.progress_percentage', '>', 0)
                                       ->where('user_course_progress.progress_percentage', '<', 100);
                               });
                        })
                        ->when($eligibleCourseIds !== null, fn ($q) => $q->whereIn('user_course_progress.course_id', $eligibleCourseIds));
                });
            } elseif ($status === 'not_started') {
                $query->where(function ($q) use ($eligibleCourseIds) {
                    $q->whereExists(function ($sub) use ($eligibleCourseIds) {
                        $sub->selectRaw('1')
                            ->from('user_course_progress')
                            ->whereColumn('user_course_progress.user_id', 'users.id')
                            ->where(function ($st) {
                                $st->where('user_course_progress.status', 'not_started')
                                   ->orWhere('user_course_progress.progress_percentage', '=', 0);
                            })
                            ->when($eligibleCourseIds !== null, fn ($sq) => $sq->whereIn('user_course_progress.course_id', $eligibleCourseIds));
                    })->orWhere(function ($noProgress) use ($eligibleCourseIds) {
                        $noProgress->whereExists(function ($subOrders) use ($eligibleCourseIds) {
                            $subOrders->selectRaw('1')
                                ->from('orders')
                                ->join('order_courses', 'orders.id', '=', 'order_courses.order_id')
                                ->whereColumn('orders.user_id', 'users.id')
                                ->where('orders.status', 'completed')
                                ->when($eligibleCourseIds !== null, fn ($sq) => $sq->whereIn('order_courses.course_id', $eligibleCourseIds));
                        })->whereNotExists(function ($subProg) use ($eligibleCourseIds) {
                            $subProg->selectRaw('1')
                                ->from('user_course_progress')
                                ->whereColumn('user_course_progress.user_id', 'users.id')
                                ->where('user_course_progress.progress_percentage', '>', 0)
                                ->when($eligibleCourseIds !== null, fn ($sq) => $sq->whereIn('user_course_progress.course_id', $eligibleCourseIds));
                        });
                    });
                });
            }
        }
        if ($eligibleCourseIds !== null) {
            $query->where(static function ($students) use ($eligibleCourseIds): void {
                $students->whereExists(static function ($orders) use ($eligibleCourseIds): void {
                    $orders->selectRaw('1')
                        ->from('orders')
                        ->join('order_courses', 'orders.id', '=', 'order_courses.order_id')
                        ->whereColumn('orders.user_id', 'users.id')
                        ->where('orders.status', 'completed')
                        ->whereIn('order_courses.course_id', $eligibleCourseIds);
                })->orWhereExists(static function ($tracking) use ($eligibleCourseIds): void {
                    $tracking->selectRaw('1')
                        ->from('user_curriculum_trackings')
                        ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                        ->whereColumn('user_curriculum_trackings.user_id', 'users.id')
                        ->whereIn('course_chapters.course_id', $eligibleCourseIds);
                });
            });
        }
    }

    private function getSummaryReport(Request $request): array
    {
        $query = $this->baseStudentQuery();
        $eligibleCourseIds = $this->eligibleCourseIds($request);
        $this->applyStudentScope($query, $request, $eligibleCourseIds);

        $perPage = min((int) $request->input('per_page', 20), 100);
        $students = $query->select('id', 'name', 'email', 'created_at')->paginate($perPage);

        $studentIds = collect($students->items())->pluck('id')->toArray();

        if (empty($studentIds)) {
            return array_merge([
                'data'         => [],
                'current_page' => $students->currentPage(),
                'last_page'    => $students->lastPage(),
                'per_page'     => $students->perPage(),
                'total'        => $students->total(),
            ], $this->reportingGrains());
        }

        // Batch query 1: all purchased course IDs for page students
        $purchasedMap = OrderCourse::join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->whereIn('orders.user_id', $studentIds)
            ->where('orders.status', 'completed')
            ->when($eligibleCourseIds !== null, fn ($query) => $query->whereIn('order_courses.course_id', $eligibleCourseIds))
            ->select('orders.user_id', 'order_courses.course_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn($rows) => $rows->pluck('course_id')->unique()->toArray());

        // Batch query 2: all tracked course IDs for page students
        $trackedMap = UserCurriculumTracking::whereIn('user_curriculum_trackings.user_id', $studentIds)
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->when($eligibleCourseIds !== null, fn ($query) => $query->whereIn('course_chapters.course_id', $eligibleCourseIds))
            ->select('user_curriculum_trackings.user_id', 'course_chapters.course_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn($rows) => $rows->pluck('course_id')->unique()->toArray());

        // Gather all relevant course IDs
        $allCourseIds = collect($purchasedMap->values()->toArray())
            ->merge(collect($trackedMap->values()->toArray()))
            ->flatten()->unique()->filter()->values()->toArray();

        $lectureCountMap = collect();
        $quizCountMap = collect();
        $assignmentCountMap = collect();
        $resourceCountMap = collect();
        $completedMap = collect();

        if (!empty($allCourseIds)) {
            // Batch query 3: lecture counts per course
            $lectureCountMap = DB::table('course_chapter_lectures')
                ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
                ->whereIn('course_chapters.course_id', $allCourseIds)
                ->where('course_chapter_lectures.is_active', 1)
                ->selectRaw('course_chapters.course_id, COUNT(*) as cnt')
                ->groupBy('course_chapters.course_id')
                ->pluck('cnt', 'course_id');

            // Batch query 4: quiz counts per course
            $quizCountMap = DB::table('course_chapter_quizzes')
                ->join('course_chapters', 'course_chapter_quizzes.course_chapter_id', '=', 'course_chapters.id')
                ->whereIn('course_chapters.course_id', $allCourseIds)
                ->where('course_chapter_quizzes.is_active', 1)
                ->selectRaw('course_chapters.course_id, COUNT(*) as cnt')
                ->groupBy('course_chapters.course_id')
                ->pluck('cnt', 'course_id');

            $assignmentCountMap = DB::table('course_chapter_assignments')
                ->join('course_chapters', 'course_chapter_assignments.course_chapter_id', '=', 'course_chapters.id')
                ->whereIn('course_chapters.course_id', $allCourseIds)
                ->where('course_chapter_assignments.is_active', 1)
                ->selectRaw('course_chapters.course_id, COUNT(*) as cnt')
                ->groupBy('course_chapters.course_id')
                ->pluck('cnt', 'course_id');

            $resourceCountMap = DB::table('course_chapter_resources')
                ->join('course_chapters', 'course_chapter_resources.course_chapter_id', '=', 'course_chapters.id')
                ->whereIn('course_chapters.course_id', $allCourseIds)
                ->where('course_chapter_resources.is_active', 1)
                ->selectRaw('course_chapters.course_id, COUNT(*) as cnt')
                ->groupBy('course_chapters.course_id')
                ->pluck('cnt', 'course_id');

            // Batch query 5: completed tracking items per user+course
            $completedMap = UserCurriculumTracking::whereIn('user_curriculum_trackings.user_id', $studentIds)
                ->where('user_curriculum_trackings.status', 'completed')
                ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
                ->whereIn('course_chapters.course_id', $allCourseIds)
                ->selectRaw('user_curriculum_trackings.user_id, course_chapters.course_id, COUNT(*) as cnt')
                ->groupBy('user_curriculum_trackings.user_id', 'course_chapters.course_id')
                ->get()
                ->groupBy('user_id')
                ->map(fn($rows) => $rows->pluck('cnt', 'course_id'));
        }

        $items = collect($students->items())->map(function ($user) use (
            $purchasedMap, $trackedMap, $lectureCountMap, $quizCountMap,
            $assignmentCountMap, $resourceCountMap, $completedMap
        ) {
            $purchased = $purchasedMap->get($user->id, []);
            $tracked   = $trackedMap->get($user->id, []);
            $enrolledIds = array_unique(array_merge($purchased, $tracked));

            $completed = 0;
            $inProgress = 0;

            foreach ($enrolledIds as $courseId) {
                $totalItems = ($lectureCountMap->get($courseId) ?? 0)
                    + ($quizCountMap->get($courseId) ?? 0)
                    + ($assignmentCountMap->get($courseId) ?? 0)
                    + ($resourceCountMap->get($courseId) ?? 0);
                if ($totalItems === 0) continue;
                $completedItems = $completedMap->get($user->id)?->get($courseId) ?? 0;
                $progress = ($completedItems / $totalItems) * 100;
                if ($progress >= 100) {
                    $completed++;
                } elseif ($progress > 0) {
                    $inProgress++;
                }
            }

            $notStarted = max(0, count($enrolledIds) - $completed - $inProgress);

            return [
                'student_id'        => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'total_enrolled'    => count($enrolledIds),
                'completed_courses' => $completed,
                'in_progress'       => $inProgress,
                'not_started'       => $notStarted,
                'open_courses'      => $inProgress,
                'completion_rate'   => count($enrolledIds) > 0
                    ? round(($completed / count($enrolledIds)) * 100, 2)
                    : 0,
                'joined_at'         => $user->created_at?->toDateString(),
            ];
        });

        return array_merge([
            'data'         => $items,
            'current_page' => $students->currentPage(),
            'last_page'    => $students->lastPage(),
            'per_page'     => $students->perPage(),
            'total'        => $students->total(),
        ], $this->reportingGrains());
    }

    private function getDetailedReport(Request $request): array
    {
        $purchasedCourses = DB::table('order_courses as oc')
            ->join('orders as o', 'oc.order_id', '=', 'o.id')
            ->join('users as u', 'o.user_id', '=', 'u.id')
            ->join('courses as c', 'oc.course_id', '=', 'c.id')
            ->where('o.status', 'completed');

        $trackedCourses = DB::table('user_curriculum_trackings as uct')
            ->join('users as u', 'uct.user_id', '=', 'u.id')
            ->join('course_chapters as ch', 'uct.course_chapter_id', '=', 'ch.id')
            ->join('courses as c', 'ch.course_id', '=', 'c.id');

        if ($request->filled('course_id')) {
            $courseId = (int) $request->course_id;
            $purchasedCourses->where('c.id', $courseId);
            $trackedCourses->where('c.id', $courseId);
        }
        if ($request->filled('instructor_id')) {
            $instructorId = (int) $request->instructor_id;
            $purchasedCourses->where('c.user_id', $instructorId);
            $trackedCourses->where('c.user_id', $instructorId);
        }
        if ($request->filled('category_id')) {
            $categoryId = (int) $request->category_id;
            $purchasedCourses->where('c.category_id', $categoryId);
            $trackedCourses->where('c.category_id', $categoryId);
        }

        $purchasedCourses->select(
            'u.id as user_id', 'u.name as student_name', 'u.email',
            'c.id as course_id', 'c.title as course_title',
            'o.created_at as enrolled_at'
        );

        $trackedCourses->select(
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

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('course_title', 'like', $search);
            });
        }

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('enrolled_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('enrolled_at', '<=', $to);
        }

        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'completed') {
                $query->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('user_course_progress as ucp')
                        ->whereColumn('ucp.user_id', 'combined.user_id')
                        ->whereColumn('ucp.course_id', 'combined.course_id')
                        ->where(function ($st) {
                            $st->where('ucp.status', 'completed')
                               ->orWhere('ucp.progress_percentage', '>=', 100);
                        });
                });
            } elseif ($status === 'in_progress') {
                $query->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('user_course_progress as ucp')
                        ->whereColumn('ucp.user_id', 'combined.user_id')
                        ->whereColumn('ucp.course_id', 'combined.course_id')
                        ->where(function ($st) {
                            $st->where('ucp.status', 'in_progress')
                               ->orWhere(function ($pct) {
                                   $pct->where('ucp.progress_percentage', '>', 0)
                                       ->where('ucp.progress_percentage', '<', 100);
                               });
                        });
                });
            } elseif ($status === 'not_started') {
                $query->where(function ($q) {
                    $q->whereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('user_course_progress as ucp')
                            ->whereColumn('ucp.user_id', 'combined.user_id')
                            ->whereColumn('ucp.course_id', 'combined.course_id')
                            ->where(function ($st) {
                                $st->where('ucp.status', 'not_started')
                                   ->orWhere('ucp.progress_percentage', '=', 0);
                            });
                    })->orWhereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('user_course_progress as ucp')
                            ->whereColumn('ucp.user_id', 'combined.user_id')
                            ->whereColumn('ucp.course_id', 'combined.course_id')
                            ->where('ucp.progress_percentage', '>', 0);
                    });
                });
            }
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->orderByDesc('enrolled_at')->paginate($perPage);

        $pageItems = collect($paginated->items());
        if ($pageItems->isEmpty()) {
            return [
                'data'         => [],
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ];
        }

        // Batch-load progress & curriculum details for page items (NO N+1)
        $pageUserIds = $pageItems->pluck('user_id')->unique()->all();
        $pageCourseIds = $pageItems->pluck('course_id')->unique()->all();

        $progressRecords = UserCourseProgress::whereIn('user_id', $pageUserIds)
            ->whereIn('course_id', $pageCourseIds)
            ->get()
            ->keyBy(fn ($item) => "{$item->user_id}_{$item->course_id}");

        // For any pair without cached UserCourseProgress, batch load item counts
        $totalItemsMap = DB::table('course_chapter_lectures as ccl')
            ->join('course_chapters as cc', 'ccl.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $pageCourseIds)
            ->where('ccl.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $quizMap = DB::table('course_chapter_quizzes as ccq')
            ->join('course_chapters as cc', 'ccq.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $pageCourseIds)
            ->where('ccq.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $assignmentMap = DB::table('course_chapter_assignments as cca')
            ->join('course_chapters as cc', 'cca.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $pageCourseIds)
            ->where('cca.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $resourceMap = DB::table('course_chapter_resources as ccr')
            ->join('course_chapters as cc', 'ccr.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $pageCourseIds)
            ->where('ccr.is_active', 1)
            ->selectRaw('cc.course_id, COUNT(*) as cnt')
            ->groupBy('cc.course_id')
            ->pluck('cnt', 'course_id');

        $trackingMap = UserCurriculumTracking::whereIn('user_curriculum_trackings.user_id', $pageUserIds)
            ->where('user_curriculum_trackings.status', 'completed')
            ->join('course_chapters as cc', 'user_curriculum_trackings.course_chapter_id', '=', 'cc.id')
            ->whereIn('cc.course_id', $pageCourseIds)
            ->selectRaw('user_curriculum_trackings.user_id, cc.course_id, COUNT(*) as cnt')
            ->groupBy('user_curriculum_trackings.user_id', 'cc.course_id')
            ->get()
            ->keyBy(fn ($t) => "{$t->user_id}_{$t->course_id}");

        $items = $pageItems->map(function ($row) use (
            $progressRecords, $totalItemsMap, $quizMap, $assignmentMap, $resourceMap, $trackingMap
        ) {
            $key = "{$row->user_id}_{$row->course_id}";
            $cached = $progressRecords->get($key);

            if ($cached !== null) {
                $progress = (float) $cached->progress_percentage;
            } else {
                $total = ($totalItemsMap[$row->course_id] ?? 0)
                       + ($quizMap[$row->course_id] ?? 0)
                       + ($assignmentMap[$row->course_id] ?? 0)
                       + ($resourceMap[$row->course_id] ?? 0);
                $completed = (int) ($trackingMap->get($key)?->cnt ?? 0);
                $progress = $total > 0 ? ($completed / $total) * 100 : 0.0;
            }

            $progress = round(min(100.0, max(0.0, $progress)), 2);

            $status = 'not_started';
            if ($progress >= 100.0) {
                $status = 'completed';
            } elseif ($progress > 0.0) {
                $status = 'in_progress';
            }

            return [
                'student_id'       => (int) $row->user_id,
                'student_name'     => $row->student_name,
                'email'            => $row->email,
                'course_id'        => (int) $row->course_id,
                'course_title'     => $row->course_title,
                'enrolled_at'      => Carbon::parse($row->enrolled_at)->toDateTimeString(),
                'progress_percent' => $progress,
                'status'           => $status,
            ];
        });

        return array_merge([
            'data'         => $items,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
        ], $this->reportingGrains());
    }

    /**
     * @return array<string, string>
     */
    private function reportingGrains(): array
    {
        return [
            'account_grain' => 'student_role_accounts',
            'access_grain' => 'completed_purchases_and_curriculum_tracking',
            'progress_grain' => 'user_course_progress_or_completed_curriculum_item_ratio',
            'subscription_catalog_access' => 'excluded_from_student_report_included_in_my_learning',
        ];
    }
}
