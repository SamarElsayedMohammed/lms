<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\User;
use App\Services\ApiResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportsApiController extends Controller
{
    /**
     * Get sales reports with filters
     */
    public function getSalesReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'status' => 'nullable|in:pending,completed,cancelled,failed',
                'payment_method' => 'nullable|in:stripe,razorpay,flutterwave,wallet',
                'category_id' => 'nullable|exists:categories,id',
                'report_type' => 'nullable|in:summary,detailed,chart',
                'group_by' => 'nullable|in:day,week,month,year',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Order::with(['orderCourses.course.category', 'user']);

            // Apply filters
            $this->applyDateFilter($query, $request);
            $this->applyCourseFilter($query, $request);
            $this->applyInstructorFilter($query, $request);
            $this->applyStatusFilter($query, $request);
            $this->applyPaymentMethodFilter($query, $request);
            $this->applyCategoryFilter($query, $request);

            // Get report type
            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getSalesChartData($query, $request),
                'detailed' => $this->getDetailedSalesData($query, $request),
                default => $this->getSalesSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Sales report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate sales report: ' . $e->getMessage());
        }
    }

    /**
     * Get commission reports with filters
     */
    public function getCommissionReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'status' => 'nullable|in:pending,paid,cancelled',
                'instructor_type' => 'nullable|in:individual,team',
                'report_type' => 'nullable|in:summary,detailed,chart',
                'group_by' => 'nullable|in:day,week,month,year',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Commission::with(['instructor', 'course.category', 'order']);

            // Apply filters
            $this->applyCommissionFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getCommissionChartData($query, $request),
                'detailed' => $this->getDetailedCommissionData($query, $request),
                default => $this->getCommissionSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Commission report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate commission report: ' . $e->getMessage());
        }
    }

    /**
     * Get course reports with filters
     */
    public function getCourseReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'category_id' => 'nullable|exists:categories,id',
                'status' => 'nullable|in:active,inactive',
                'approval_status' => 'nullable|in:pending,approved,rejected',
                'course_type' => 'nullable|in:free,paid',
                'level' => 'nullable|in:beginner,intermediate,advanced',
                'report_type' => 'nullable|in:summary,detailed,performance',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Course::with(['user', 'category', 'ratings', 'orderCourses']);

            // Apply filters
            $this->applyCourseReportFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'performance' => $this->getCoursePerformanceData($query, $request),
                'detailed' => $this->getDetailedCourseData($query, $request),
                default => $this->getCourseSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Course report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate course report: ' . $e->getMessage());
        }
    }

    /**
     * Get instructor reports with filters
     */
    public function getInstructorReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'instructor_id' => 'nullable|exists:users,id',
                'instructor_type' => 'nullable|in:individual,team',
                'status' => 'nullable|in:pending,approved,rejected',
                'report_type' => 'nullable|in:summary,detailed,performance',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Instructor::with(['user', 'user.courses']);

            // Apply filters
            $this->applyInstructorReportFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'performance' => $this->getInstructorPerformanceData($query, $request),
                'detailed' => $this->getDetailedInstructorData($query, $request),
                default => $this->getInstructorSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Instructor report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate instructor report: ' . $e->getMessage());
        }
    }

    /**
     * Get student enrollment reports
     */
    public function getEnrollmentReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'category_id' => 'nullable|exists:categories,id',
                'status' => 'nullable|in:started,in_progress,completed',
                'report_type' => 'nullable|in:summary,detailed,chart',
                'group_by' => 'nullable|in:day,week,month,year',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = UserCourseTrack::with(['user', 'course.category', 'course.user']);

            // Apply filters
            $this->applyEnrollmentFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getEnrollmentChartData($query, $request),
                'detailed' => $this->getDetailedEnrollmentData($query, $request),
                default => $this->getEnrollmentSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Enrollment report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate enrollment report: ' . $e->getMessage());
        }
    }

    /**
     * Get revenue reports with filters
     */
    public function getRevenueReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'category_id' => 'nullable|exists:categories,id',
                'payment_method' => 'nullable|in:stripe,razorpay,flutterwave,wallet',
                'report_type' => 'nullable|in:summary,detailed,chart,comparison',
                'group_by' => 'nullable|in:day,week,month,year',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'comparison' => $this->getRevenueComparisonData($request),
                'chart' => $this->getRevenueChartData($request),
                'detailed' => $this->getDetailedRevenueData($request),
                default => $this->getRevenueSummaryData($request),
            };

            return ApiResponseService::successResponse('Revenue report generated successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate revenue report: ' . $e->getMessage());
        }
    }

    /**
     * Get all available filter options for reports
     */
    public function getReportFilters(Request $request)
    {
        try {
            $data = [
                'courses' => Course::select('id', 'title')->get(),
                'instructors' => User::whereHas('instructor_details')->select('id', 'name', 'email')->get(),
                'categories' => Category::select('id', 'name')->get(),
                'order_statuses' => ['pending', 'completed', 'cancelled', 'failed'],
                'commission_statuses' => ['pending', 'paid', 'cancelled'],
                'payment_methods' => ['stripe', 'razorpay', 'flutterwave', 'wallet'],
                'instructor_types' => ['individual', 'team'],
                'course_types' => ['free', 'paid'],
                'course_levels' => ['beginner', 'intermediate', 'advanced'],
                'enrollment_statuses' => ['started', 'in_progress', 'completed'],
                'approval_statuses' => ['pending', 'approved', 'rejected'],
                'report_types' => [
                    'sales' => ['summary', 'detailed', 'chart'],
                    'commission' => ['summary', 'detailed', 'chart'],
                    'course' => ['summary', 'detailed', 'performance'],
                    'instructor' => ['summary', 'detailed', 'performance'],
                    'enrollment' => ['summary', 'detailed', 'chart'],
                    'revenue' => ['summary', 'detailed', 'chart', 'comparison'],
                ],
                'group_by_options' => ['day', 'week', 'month', 'year'],
            ];

            return ApiResponseService::successResponse('Report filters retrieved successfully', $data);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve report filters');
        }
    }

    // Private helper methods for filtering and data processing

    private function applyDateFilter($query, $request)
    {
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
    }

    private function applyCourseFilter($query, $request)
    {
        if ($request->filled('course_id')) {
            $query->whereHas('orderCourses', static function ($q) use ($request): void {
                $q->where('course_id', $request->course_id);
            });
        }
    }

    private function applyInstructorFilter($query, $request)
    {
        if ($request->filled('instructor_id')) {
            $query->whereHas('orderCourses.course', static function ($q) use ($request): void {
                $q->where('user_id', $request->instructor_id);
            });
        }
    }

    private function applyStatusFilter($query, $request)
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
    }

    private function applyPaymentMethodFilter($query, $request)
    {
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
    }

    private function applyCategoryFilter($query, $request)
    {
        if ($request->filled('category_id')) {
            $query->whereHas('orderCourses.course.category', static function ($q) use ($request): void {
                $q->where('id', $request->category_id);
            });
        }
    }

    private function applyCommissionFilters($query, $request)
    {
        $this->applyDateFilter($query, $request);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->instructor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('instructor_type')) {
            $query->where('instructor_type', $request->instructor_type);
        }
    }

    private function applyCourseReportFilters($query, $request)
    {
        $this->applyDateFilter($query, $request);

        if ($request->filled('course_id')) {
            $query->where('id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $query->where('user_id', $request->instructor_id);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('status')) {
            $isActive = $request->status === 'active';
            $query->where('is_active', $isActive);
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }
        if ($request->filled('course_type')) {
            $query->where('course_type', $request->course_type);
        }
        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }
    }

    private function applyInstructorReportFilters($query, $request)
    {
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->whereHas('user', function ($q) use ($request): void {
                $this->applyDateFilter($q, $request);
            });
        }

        if ($request->filled('instructor_id')) {
            $query->where('user_id', $request->instructor_id);
        }
        if ($request->filled('instructor_type')) {
            $query->where('type', $request->instructor_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
    }

    private function applyEnrollmentFilters($query, $request)
    {
        $this->applyDateFilter($query, $request);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $query->whereHas('course', static function ($q) use ($request): void {
                $q->where('user_id', $request->instructor_id);
            });
        }
        if ($request->filled('category_id')) {
            $query->whereHas('course.category', static function ($q) use ($request): void {
                $q->where('id', $request->category_id);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
    }

    // Data processing methods for different report types

    private function getSalesSummaryData($query, $request)
    {
        $orders = $query->get();

        return [
            'total_orders' => $orders->count(),
            'total_revenue' => $orders->sum('final_price'),
            'average_order_value' => $orders->avg('final_price'),
            'completed_orders' => $orders->where('status', 'completed')->count(),
            'pending_orders' => $orders->where('status', 'pending')->count(),
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'payment_methods' => $orders->groupBy('payment_method')->map->count(),
            'top_courses' => $this->getTopCoursesSales($orders),
            'recent_orders' => $orders->sortByDesc('created_at')->take(10)->values(),
        ];
    }

    private function getDetailedSalesData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function getSalesChartData($query, $request)
    {
        $groupBy = $request->group_by ?? 'day';

        $format = match ($groupBy) {
            'year' => '%Y',
            'month' => '%Y-%m',
            'week' => '%Y-%u',
            default => '%Y-%m-%d',
        };

        return $query->selectRaw("
                DATE_FORMAT(created_at, '{$format}') as period,
                COUNT(*) as orders_count,
                SUM(final_price) as revenue,
                AVG(final_price) as avg_order_value
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getCommissionSummaryData($query, $request)
    {
        $commissions = $query->get();

        return [
            'total_commissions' => $commissions->count(),
            'total_admin_commission' => $commissions->sum('admin_commission_amount'),
            'total_instructor_commission' => $commissions->sum('instructor_commission_amount'),
            'paid_commissions' => $commissions->where('status', 'paid')->count(),
            'pending_commissions' => $commissions->where('status', 'pending')->count(),
            'top_earning_instructors' => $this->getTopEarningInstructors($commissions),
            'commission_by_course' => $this->getCommissionByCourse($commissions),
            'recent_commissions' => $commissions->sortByDesc('created_at')->take(10)->values(),
        ];
    }

    private function getDetailedCommissionData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function getCommissionChartData($query, $request)
    {
        $groupBy = $request->group_by ?? 'day';

        $format = match ($groupBy) {
            'year' => '%Y',
            'month' => '%Y-%m',
            'week' => '%Y-%u',
            default => '%Y-%m-%d',
        };

        return $query->selectRaw("
                DATE_FORMAT(created_at, '{$format}') as period,
                COUNT(*) as commission_count,
                SUM(admin_commission_amount) as admin_total,
                SUM(instructor_commission_amount) as instructor_total
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getCourseSummaryData($query, $request)
    {
        $courses = $query->withCount(['orderCourses', 'ratings'])->get();

        return [
            'total_courses' => $courses->count(),
            'active_courses' => $courses->where('is_active', true)->count(),
            'free_courses' => $courses->where('course_type', 'free')->count(),
            'paid_courses' => $courses->where('course_type', 'paid')->count(),
            'average_rating' => $courses->avg('ratings_avg_rating'),
            'total_enrollments' => $courses->sum('order_courses_count'),
            'courses_by_category' => $this->getCoursesByCategory($courses),
            'courses_by_level' => $courses->groupBy('level')->map->count(),
            'top_rated_courses' => $courses->sortByDesc('ratings_avg_rating')->take(10)->values(),
        ];
    }

    private function getDetailedCourseData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        return $query
            ->withCount(['orderCourses', 'ratings'])
            ->withAvg('ratings', 'rating')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    private function getCoursePerformanceData($query, $request)
    {
        return $query
            ->withCount(['orderCourses', 'ratings'])
            ->withAvg('ratings', 'rating')
            ->with(['orderCourses' => static function ($q): void {
                $q->selectRaw('course_id, SUM(price) as total_revenue, COUNT(*) as enrollment_count')->groupBy(
                    'course_id',
                );
            }])
            ->get()
            ->map(static fn($course) => [
                'course' => $course,
                'performance_metrics' => [
                    'enrollments' => $course->order_courses_count,
                    'revenue' => $course->orderCourses->sum('total_revenue'),
                    'rating' => round($course->ratings_avg_rating ?? 0, 2),
                    'reviews_count' => $course->ratings_count,
                ],
            ]);
    }

    private function getInstructorSummaryData($query, $request)
    {
        $instructors = $query->with(['user.courses'])->get();

        return [
            'total_instructors' => $instructors->count(),
            'individual_instructors' => $instructors->where('type', 'individual')->count(),
            'team_instructors' => $instructors->where('type', 'team')->count(),
            'approved_instructors' => $instructors->where('status', 'approved')->count(),
            'pending_instructors' => $instructors->where('status', 'pending')->count(),
            'total_courses_created' => $instructors->sum(static fn($instructor) => $instructor->user->courses->count()),
            'top_instructors_by_courses' => $this->getTopInstructorsByCourses($instructors),
            'instructors_by_status' => $instructors->groupBy('status')->map->count(),
        ];
    }

    private function getDetailedInstructorData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        return $query
            ->with(['user.courses' => static function ($q): void {
                $q->withCount('orderCourses');
            }])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    private function getInstructorPerformanceData($query, $request)
    {
        return $query->with([
            'user.courses.orderCourses',
            'user.courses.ratings',
        ])->get()->map(static function ($instructor) {
            $courses = $instructor->user->courses;
            return [
                'instructor' => $instructor,
                'performance_metrics' => [
                    'total_courses' => $courses->count(),
                    'total_enrollments' => $courses->sum(static fn($course) => $course->orderCourses->count()),
                    'total_revenue' => $courses->sum(static fn($course) => $course->orderCourses->sum('price')),
                    'average_rating' => $courses->avg(static fn($course) => $course->ratings->avg('rating')),
                ],
            ];
        });
    }

    private function getEnrollmentSummaryData($query, $request)
    {
        $enrollments = $query->get();

        return [
            'total_enrollments' => $enrollments->count(),
            'started_enrollments' => $enrollments->where('status', 'started')->count(),
            'in_progress_enrollments' => $enrollments->where('status', 'in_progress')->count(),
            'completed_enrollments' => $enrollments->where('status', 'completed')->count(),
            'completion_rate' => $this->calculateCompletionRate($enrollments),
            'enrollments_by_course' => $this->getEnrollmentsByCourse($enrollments),
            'enrollments_by_month' => $this->getEnrollmentsByMonth($enrollments),
            'recent_enrollments' => $enrollments->sortByDesc('created_at')->take(10)->values(),
        ];
    }

    private function getDetailedEnrollmentData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function getEnrollmentChartData($query, $request)
    {
        $groupBy = $request->group_by ?? 'day';

        $format = match ($groupBy) {
            'year' => '%Y',
            'month' => '%Y-%m',
            'week' => '%Y-%u',
            default => '%Y-%m-%d',
        };

        return $query->selectRaw("
                DATE_FORMAT(created_at, '{$format}') as period,
                COUNT(*) as enrollment_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getRevenueSummaryData($request)
    {
        $query = Order::where('status', 'completed');
        $this->applyDateFilter($query, $request);
        $this->applyCourseFilter($query, $request);
        $this->applyInstructorFilter($query, $request);
        $this->applyCategoryFilter($query, $request);
        $this->applyPaymentMethodFilter($query, $request);

        $orders = $query->get();

        return [
            'total_revenue' => $orders->sum('final_price'),
            'total_orders' => $orders->count(),
            'average_order_value' => $orders->avg('final_price'),
            'revenue_by_payment_method' => $orders->groupBy('payment_method')->map(
                static fn($orders) => $orders->sum('final_price'),
            ),
            'revenue_by_category' => $this->getRevenueByCategory($orders),
            'top_revenue_courses' => $this->getTopRevenueCourses($orders),
            'revenue_trend' => $this->getRevenueTrend($orders),
        ];
    }

    private function getDetailedRevenueData($request)
    {
        $query = Order::where('status', 'completed')->with(['orderCourses.course', 'user']);
        $this->applyDateFilter($query, $request);
        $this->applyCourseFilter($query, $request);
        $this->applyInstructorFilter($query, $request);
        $this->applyCategoryFilter($query, $request);
        $this->applyPaymentMethodFilter($query, $request);

        $perPage = $request->per_page ?? 15;
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function getRevenueChartData($request)
    {
        $query = Order::where('status', 'completed');
        $this->applyDateFilter($query, $request);
        $this->applyCourseFilter($query, $request);
        $this->applyInstructorFilter($query, $request);
        $this->applyCategoryFilter($query, $request);
        $this->applyPaymentMethodFilter($query, $request);

        $groupBy = $request->group_by ?? 'day';

        $format = match ($groupBy) {
            'year' => '%Y',
            'month' => '%Y-%m',
            'week' => '%Y-%u',
            default => '%Y-%m-%d',
        };

        return $query->selectRaw("
                DATE_FORMAT(created_at, '{$format}') as period,
                SUM(final_price) as revenue,
                COUNT(*) as orders_count,
                AVG(final_price) as avg_order_value
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getRevenueComparisonData($request)
    {
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->subDays(30);
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now();

        $daysDiff = $dateTo->diffInDays($dateFrom);

        // Current period
        $currentQuery = Order::where('status', 'completed')->whereBetween('created_at', [$dateFrom, $dateTo]);

        // Previous period (same duration)
        $previousFrom = $dateFrom->copy()->subDays($daysDiff);
        $previousTo = $dateFrom->copy();

        $previousQuery = Order::where('status', 'completed')->whereBetween('created_at', [$previousFrom, $previousTo]);

        $currentRevenue = $currentQuery->sum('final_price');
        $previousRevenue = $previousQuery->sum('final_price');

        $growth = $previousRevenue > 0 ? (($currentRevenue - $previousRevenue) / $previousRevenue) * 100 : 0;

        return [
            'current_period' => [
                'revenue' => $currentRevenue,
                'orders' => $currentQuery->count(),
                'avg_order_value' => $currentQuery->avg('final_price'),
            ],
            'previous_period' => [
                'revenue' => $previousRevenue,
                'orders' => $previousQuery->count(),
                'avg_order_value' => $previousQuery->avg('final_price'),
            ],
            'growth' => [
                'revenue_growth' => round($growth, 2),
                'orders_growth' => $this->calculateGrowthPercentage($previousQuery->count(), $currentQuery->count()),
            ],
        ];
    }

    // Helper methods for calculations

    private function getTopCoursesSales($orders)
    {
        return $orders
            ->flatMap
            ->orderCourses
            ->groupBy('course_id')
            ->map(static fn($orderCourses) => [
                'course' => $orderCourses->first()->course,
                'total_sales' => $orderCourses->sum('price'),
                'total_orders' => $orderCourses->count(),
            ])
            ->sortByDesc('total_sales')
            ->take(10)
            ->values();
    }

    private function getTopEarningInstructors($commissions)
    {
        return $commissions
            ->groupBy('instructor_id')
            ->map(static fn($instructorCommissions) => [
                'instructor' => $instructorCommissions->first()->instructor,
                'total_commission' => $instructorCommissions->sum('instructor_commission_amount'),
                'commission_count' => $instructorCommissions->count(),
            ])
            ->sortByDesc('total_commission')
            ->take(10)
            ->values();
    }

    private function getCommissionByCourse($commissions)
    {
        return $commissions
            ->groupBy('course_id')
            ->map(static fn($courseCommissions) => [
                'course' => $courseCommissions->first()->course,
                'total_commission' => $courseCommissions->sum('instructor_commission_amount'),
                'commission_count' => $courseCommissions->count(),
            ])
            ->sortByDesc('total_commission')
            ->values();
    }

    private function getCoursesByCategory($courses)
    {
        return $courses->groupBy('category.name')->map->count();
    }

    private function getTopInstructorsByCourses($instructors)
    {
        return $instructors
            ->map(static fn($instructor) => [
                'instructor' => $instructor,
                'courses_count' => $instructor->user->courses->count(),
            ])
            ->sortByDesc('courses_count')
            ->take(10)
            ->values();
    }

    private function calculateCompletionRate($enrollments)
    {
        $total = $enrollments->count();
        $completed = $enrollments->where('status', 'completed')->count();

        return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
    }

    private function getEnrollmentsByCourse($enrollments)
    {
        return $enrollments
            ->groupBy('course_id')
            ->map(static fn($courseEnrollments) => [
                'course' => $courseEnrollments->first()->course,
                'enrollment_count' => $courseEnrollments->count(),
                'completed_count' => $courseEnrollments->where('status', 'completed')->count(),
            ])
            ->sortByDesc('enrollment_count')
            ->values();
    }

    private function getEnrollmentsByMonth($enrollments)
    {
        return $enrollments
            ->groupBy(static fn($enrollment) => $enrollment->created_at->format('Y-m'))
            ->map->count()->sortKeys();
    }

    private function getRevenueByCategory($orders)
    {
        return $orders
            ->flatMap
            ->orderCourses
            ->groupBy('course.category.name')
            ->map(static fn($orderCourses) => $orderCourses->sum('price'))
            ->sortDesc();
    }

    private function getTopRevenueCourses($orders)
    {
        return $orders
            ->flatMap
            ->orderCourses
            ->groupBy('course_id')
            ->map(static fn($orderCourses) => [
                'course' => $orderCourses->first()->course,
                'revenue' => $orderCourses->sum('price'),
                'orders_count' => $orderCourses->count(),
            ])
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
    }

    private function getRevenueTrend($orders)
    {
        return $orders
            ->groupBy(static fn($order) => $order->created_at->format('Y-m-d'))
            ->map(static fn($dailyOrders) => $dailyOrders->sum('final_price'))
            ->sortKeys();
    }

    private function calculateGrowthPercentage($old, $new)
    {
        if ($old == 0) {
            return $new > 0 ? 100 : 0;
        }

        return round((($new - $old) / $old) * 100, 2);
    }
}
