<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\UserCourseProgress;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\Reports\ReportMoneySql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportsApiController extends Controller
{
    private const CARD_GATEWAY_METHODS = ['stripe', 'razorpay', 'flutterwave', 'kashier'];

    /**
     * Get sales reports with filters
     */
    public function getSalesReport(Request $request)
    {
        try {
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
            $validator = Validator::make($request->all(), [
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
                'course_id'      => 'nullable|exists:courses,id',
                'instructor_id'  => 'nullable|exists:users,id',
                'status'         => 'nullable|in:pending,completed,cancelled,failed',
                'payment_method' => 'nullable|string|max:50',
                'category_id'    => 'nullable|exists:categories,id',
                'report_type'    => 'nullable|in:summary,detailed,chart',
                'group_by'       => 'nullable|in:day,week,month,year',
                'per_page'       => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Order::with(['orderCourses.course.category', 'user']);

            // Apply filters
            $this->applyDateFilter($query, $request, 'orders.created_at');
            $this->applyCourseFilter($query, $request);
            $this->applyInstructorFilter($query, $request);
            $this->applyStatusFilter($query, $request);
            $this->applyPaymentMethodFilter($query, $request);
            $this->applyCategoryFilter($query, $request);
            if ($request->boolean('card_gateways_only')) {
                $query->whereIn('payment_method', self::CARD_GATEWAY_METHODS);
            }

            // Get report type
            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getSalesChartData($query, $request),
                'detailed' => $this->getDetailedSalesData($query, $request),
                default => $this->getSalesSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Sales report generated successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate sales report: ' . $e->getMessage());
        }
    }

    /** Revenue shares the canonical sales transaction population and money rules. */
    public function getRevenueReport(Request $request)
    {
        return $this->getSalesReport($request);
    }

    public function getCreditCardsRevenueReport(Request $request)
    {
        $request->merge(['card_gateways_only' => true]);

        return $this->getSalesReport($request);
    }

    public function getComprehensiveReport(Request $request)
    {
        try {
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date,
            ]);
            $validator = Validator::make($request->all(), [
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
                'course_id'      => 'nullable|exists:courses,id',
                'instructor_id'  => 'nullable|exists:users,id',
                'category_id'    => 'nullable|exists:categories,id',
                'payment_method' => 'nullable|string|max:50',
                'status'         => 'nullable|string|max:50',
            ]);
            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $salesQuery = Order::with(['orderCourses.course.category', 'user']);
            $this->applyDateFilter($salesQuery, $request, 'orders.created_at');
            $this->applyCourseFilter($salesQuery, $request);
            $this->applyInstructorFilter($salesQuery, $request);
            $this->applyStatusFilter($salesQuery, $request);
            $this->applyPaymentMethodFilter($salesQuery, $request);
            $this->applyCategoryFilter($salesQuery, $request);
            if ($request->boolean('card_gateways_only')) {
                $salesQuery->whereIn('orders.payment_method', self::CARD_GATEWAY_METHODS);
            }

            $courseQuery = Course::with(['user', 'category', 'ratings', 'orderCourses']);
            $this->applyCourseReportFilters($courseQuery, $request);

            $instructorQuery = Instructor::with([
                'user',
                'user.courses' => static fn ($courses) => $courses
                    ->where('is_active', true)
                    ->where('status', 'publish'),
            ]);
            $this->applyInstructorReportFilters($instructorQuery, $request);

            $enrollmentQuery = UserCourseProgress::with(['user', 'course.category', 'course.user'])
                ->whereHas('course', static fn ($courses) => $courses
                    ->where('is_active', true)
                    ->where('status', 'publish'));
            $this->applyEnrollmentFilters($enrollmentQuery, $request);

            return ApiResponseService::successResponse('Comprehensive report generated successfully', [
                'sales_summary' => $this->getSalesSummaryData($salesQuery, $request),
                'course_summary' => $this->getCourseSummaryData($courseQuery, $request),
                'instructor_summary' => $this->getInstructorSummaryData($instructorQuery, $request),
                'enrollment_summary' => $this->getEnrollmentSummaryData($enrollmentQuery, $request),
                'generated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate comprehensive report: ' . $e->getMessage());
        }
    }


    /**
     * Get commission reports with filters
     */
    public function getCommissionReport(Request $request)
    {
        try {
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
                'instructor_id' => 'nullable|exists:users,id',
                'instructor_type' => 'nullable|in:individual,team',
                'status' => 'nullable|in:pending,paid,cancelled',
                'report_type' => 'nullable|in:summary,detailed,chart',
                'group_by' => 'nullable|in:day,week,month,year',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Commission::with(['course.category', 'order']);

            // Apply filters
            $this->applyCommissionFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getCommissionChartData($query, $request),
                'detailed' => $this->getDetailedCommissionData($query, $request),
                default => $this->getCommissionSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Commission report generated successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
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

            $query = Instructor::with([
                'user',
                'user.courses' => static fn ($courses) => $courses
                    ->where('is_active', true)
                    ->where('status', 'publish'),
            ]);

            // Apply filters
            $this->applyInstructorReportFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'performance' => $this->getInstructorPerformanceData($query, $request),
                'detailed' => $this->getDetailedInstructorData($query, $request),
                default => $this->getInstructorSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Instructor report generated successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
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

            $query = UserCourseProgress::with(['user', 'course.category', 'course.user'])
                ->whereHas('course', static fn ($courses) => $courses
                    ->where('is_active', true)
                    ->where('status', 'publish'));

            // Apply filters
            $this->applyEnrollmentFilters($query, $request);

            $reportType = $request->report_type ?? 'summary';

            $data = match ($reportType) {
                'chart' => $this->getEnrollmentChartData($query, $request),
                'detailed' => $this->getDetailedEnrollmentData($query, $request),
                default => $this->getEnrollmentSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Enrollment report generated successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate enrollment report: ' . $e->getMessage());
        }
    }

    /**
     * Get all available filter options for reports
     */
    public function getReportFilters(Request $request)
    {
        try {
            $data = [
                'courses'             => \Illuminate\Support\Facades\DB::table('courses')
                                            ->whereNull('deleted_at')
                                            ->where('is_active', true)
                                            ->where('status', 'publish')
                                            ->select('id', 'title')
                                            ->orderBy('title')
                                            ->get(),
                'instructors'         => \Illuminate\Support\Facades\DB::table('users')
                                            ->whereNull('deleted_at')
                                            ->whereExists(function ($q) {
                                                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                                                  ->from('instructors')
                                                  ->whereColumn('instructors.user_id', 'users.id');
                                            })
                                            ->select('id', 'name', 'email')
                                            ->get(),
                'categories'          => \Illuminate\Support\Facades\DB::table('categories')
                                            ->whereNull('deleted_at')
                                            ->select('id', 'name')
                                            ->orderBy('name')
                                            ->get(),
                'order_statuses'      => ['pending', 'completed', 'cancelled', 'failed'],
                'commission_statuses' => ['pending', 'paid', 'cancelled'],
                'payment_methods'     => ['stripe', 'razorpay', 'flutterwave', 'wallet'],
                'instructor_types'    => ['individual', 'team'],
                'course_statuses'     => ['active', 'inactive'],
                'course_types'        => ['free', 'paid'],
                'course_levels'       => ['beginner', 'intermediate', 'advanced'],
                'enrollment_statuses' => ['started', 'in_progress', 'completed'],
                'approval_statuses'   => ['pending', 'approved', 'rejected'],
                'report_types'        => [
                    'sales'       => ['summary', 'detailed', 'chart'],
                    'commission'  => ['summary', 'detailed', 'chart'],
                    'course'      => ['summary', 'detailed', 'performance'],
                    'instructor'  => ['summary', 'detailed', 'performance'],
                    'enrollment'  => ['summary', 'detailed', 'chart'],
                    'revenue'     => ['summary', 'detailed', 'chart', 'comparison'],
                ],
                'group_by_options'    => ['day', 'week', 'month', 'year'],
            ];

            return ApiResponseService::successResponse('Report filters retrieved successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorInfo = get_class($e) . ' - ' . $e->getMessage() . ' at line ' . $e->getLine();
            return ApiResponseService::errorResponse('Failed to retrieve report filters: ' . $errorInfo);
        }
    }

    // Private helper methods for filtering and data processing

    private function applyDateFilter($query, Request $request, string $column = 'created_at'): void
    {
        $tz = config('app.timezone', 'Africa/Cairo');
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;

        if (!empty($dateFrom) && !empty($dateTo)) {
            $start = Carbon::parse($dateFrom, $tz)->startOfDay();
            $end = Carbon::parse($dateTo, $tz)->endOfDay();
            $query->whereBetween($column, [$start, $end]);
        } elseif (!empty($dateFrom)) {
            $start = Carbon::parse($dateFrom, $tz)->startOfDay();
            $query->where($column, '>=', $start);
        } elseif (!empty($dateTo)) {
            $end = Carbon::parse($dateTo, $tz)->endOfDay();
            $query->where($column, '<=', $end);
        }
    }

    private function dateFormatSql(string $column, string $format = '%Y-%m-%d'): string
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return "strftime('{$format}', {$column})";
        }
        return "DATE_FORMAT({$column}, '{$format}')";
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
        $this->applyDateFilter($query, $request, 'commissions.created_at');

        if ($request->filled('course_id')) {
            $query->where('commissions.course_id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $query->where('commissions.instructor_id', $request->instructor_id);
        }
        if ($request->filled('instructor_type')) {
            $query->where('commissions.instructor_type', $request->instructor_type);
        }
        if ($request->filled('status')) {
            $query->where('commissions.status', $request->status);
        }
    }

    private function applyCourseReportFilters($query, $request)
    {
        $this->applyDateFilter($query, $request, 'courses.created_at');

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
            if ($request->status === 'active') {
                $query->where('is_active', true)->where('status', 'publish');
            } else {
                $query->where(static fn ($courses) => $courses
                    ->where('is_active', false)
                    ->orWhere('status', '!=', 'publish'));
            }
        } else {
            // The default must agree with student-facing totals. Operators can
            // explicitly request inactive rows through status=inactive.
            $query->where('is_active', true)->where('status', 'publish');
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }
        if ($request->filled('course_type')) {
            // course_type=free maps to is_free=1, course_type=paid maps to is_free=0
            $query->where('is_free', $request->course_type === 'free' ? 1 : 0);
        }
        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }
    }

    private function applyInstructorReportFilters($query, $request)
    {
        if ($request->filled('date_from') || $request->filled('from_date') || $request->filled('date_to') || $request->filled('to_date')) {
            $query->whereHas('user', function ($q) use ($request): void {
                $this->applyDateFilter($q, $request, 'users.created_at');
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
        $this->applyDateFilter($query, $request, 'user_course_progress.created_at');

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
            $status = $request->status;
            if ($status === 'started') {
                $query->whereIn('status', ['started', 'not_started']);
            } else {
                $query->where('status', $status);
            }
        }
    }

    // Data processing methods for different report types

    private function getSalesSummaryData($query, $request)
    {
        $orderRevenueSql = ReportMoneySql::orderRevenueEgpSql('orders');
        $ordersAgg = (clone $query);
        $ordersAgg->getQuery()->orders = null;
        $ordersAgg->getQuery()->limit = null;
        $ordersAgg->getQuery()->offset = null;

        $orderStats = $ordersAgg->selectRaw("
            COUNT(*) as total_count,
            COUNT(CASE WHEN orders.status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN orders.status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN orders.status = 'cancelled' THEN 1 END) as cancelled_count,
            COUNT(CASE WHEN orders.status = 'failed' THEN 1 END) as failed_count,
            SUM(CASE WHEN orders.status = 'completed' THEN {$orderRevenueSql} ELSE 0 END) as completed_revenue
        ")->first();

        $orderTotalCount     = (int) ($orderStats->total_count ?? 0);
        $orderCompletedCount = (int) ($orderStats->completed_count ?? 0);
        $orderPendingCount   = (int) ($orderStats->pending_count ?? 0);
        $orderCancelledCount = (int) ($orderStats->cancelled_count ?? 0);
        $orderFailedCount    = (int) ($orderStats->failed_count ?? 0);
        $orderRevenue        = (float) ($orderStats->completed_revenue ?? 0);

        $hasCourseFilters = $request->filled('course_id') || $request->filled('category_id') || $request->filled('instructor_id');
        $subTotalCount = 0;
        $subCompletedCount = 0;
        $subPendingCount = 0;
        $subFailedCount = 0;
        $subRevenue = 0.0;

        $subQuery = SubscriptionPayment::query();
        if ($hasCourseFilters) {
            $subQuery->whereRaw('1 = 0');
        } else {
            $this->applyDateFilter($subQuery, $request, 'subscription_payments.created_at');
            if ($request->filled('payment_method')) {
                $subQuery->where('subscription_payments.payment_method', $request->payment_method);
            }
            if ($request->boolean('card_gateways_only')) {
                $subQuery->whereIn('subscription_payments.payment_method', self::CARD_GATEWAY_METHODS);
            }
            if ($request->filled('status')) {
                if ($request->status === 'completed') {
                    $subQuery->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED);
                } elseif ($request->status === 'pending') {
                    $subQuery->where('subscription_payments.status', SubscriptionPayment::STATUS_PENDING);
                } elseif ($request->status === 'cancelled' || $request->status === 'failed') {
                    $subQuery->where('subscription_payments.status', SubscriptionPayment::STATUS_FAILED);
                }
            }

            $subRevenueSql = ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments');
            $subStats = (clone $subQuery)->selectRaw("
                COUNT(*) as total_count,
                COUNT(CASE WHEN subscription_payments.status = '" . SubscriptionPayment::STATUS_COMPLETED . "' THEN 1 END) as completed_count,
                COUNT(CASE WHEN subscription_payments.status = '" . SubscriptionPayment::STATUS_PENDING . "' THEN 1 END) as pending_count,
                COUNT(CASE WHEN subscription_payments.status = '" . SubscriptionPayment::STATUS_FAILED . "' THEN 1 END) as failed_count,
                SUM(CASE WHEN subscription_payments.status = '" . SubscriptionPayment::STATUS_COMPLETED . "' THEN {$subRevenueSql} ELSE 0 END) as completed_revenue
            ")->first();

            $subTotalCount     = (int) ($subStats->total_count ?? 0);
            $subCompletedCount = (int) ($subStats->completed_count ?? 0);
            $subPendingCount   = (int) ($subStats->pending_count ?? 0);
            $subFailedCount    = (int) ($subStats->failed_count ?? 0);
            $subRevenue        = (float) ($subStats->completed_revenue ?? 0);
        }

        $allOrdersCount = $orderTotalCount + $subTotalCount;
        $grossRevenue = $orderRevenue + $subRevenue;

        $refundQuery = RefundRequest::query()->where('refund_requests.status', 'approved');
        $this->applyDateFilter($refundQuery, $request, 'refund_requests.created_at');
        if ($request->filled('course_id')) {
            $refundQuery->where('refund_requests.course_id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $refundQuery->whereHas('course', fn ($course) => $course->where('user_id', $request->instructor_id));
        }
        if ($request->filled('category_id')) {
            $refundQuery->whereHas('course', fn ($course) => $course->where('category_id', $request->category_id));
        }
        if ($request->filled('payment_method')) {
            $refundQuery->whereHas('transaction', fn ($transaction) => $transaction->where('payment_method', $request->payment_method));
        }
        if ($request->boolean('card_gateways_only')) {
            $refundQuery->whereHas('transaction', fn ($transaction) => $transaction->whereIn('payment_method', self::CARD_GATEWAY_METHODS));
        }
        $refundsAmount = (float) $refundQuery->select(DB::raw('SUM(' . ReportMoneySql::refundAmountEgpSql('refund_requests') . ') as total_refunds'))->value('total_refunds');

        $totalRevenue   = max(0, $grossRevenue - $refundsAmount);
        $completedCount = $orderCompletedCount + $subCompletedCount;
        $avgOrderValue  = $completedCount > 0 ? round($totalRevenue / $completedCount, 2) : 0.0;

        // Payment methods aggregation via database GROUP BY
        $orderPaymentMethods = (clone $query)
            ->whereNotNull('orders.payment_method')
            ->selectRaw('orders.payment_method, COUNT(*) as count')
            ->groupBy('orders.payment_method')
            ->pluck('count', 'payment_method')
            ->toArray();

        $subPaymentMethods = [];
        if (!$hasCourseFilters) {
            $subPaymentMethods = (clone $subQuery)
                ->whereNotNull('subscription_payments.payment_method')
                ->selectRaw('subscription_payments.payment_method, COUNT(*) as count')
                ->groupBy('subscription_payments.payment_method')
                ->pluck('count', 'payment_method')
                ->toArray();
        }

        $allPaymentMethods = collect($orderPaymentMethods);
        foreach ($subPaymentMethods as $method => $count) {
            $allPaymentMethods[$method] = ($allPaymentMethods[$method] ?? 0) + $count;
        }
        $allPaymentMethods = $allPaymentMethods->sortDesc();

        // Recent orders (limit 10)
        $recentOrders = (clone $query)
            ->with(['user:id,name,email'])
            ->orderBy('orders.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($o) => [
                'id'             => $o->id,
                'status'         => $o->status,
                'final_price'    => (float) $o->final_price,
                'amount'         => (float) ($o->amount_egp ?? (($o->final_price ?? 0) * ($o->exchange_rate_snapshot ?? 1))),
                'payment_method' => $o->payment_method,
                'created_at'     => $o->created_at,
                'user'           => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
            ]);

        // Recent subscriptions (limit 10)
        $recentSubscriptions = collect();
        if (!$hasCourseFilters) {
            $recentSubscriptions = (clone $subQuery)
                ->orderBy('subscription_payments.created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn($s) => [
                    'id'             => $s->id,
                    'status'         => $s->status,
                    'amount'         => (float) $s->final_amount,
                    'payment_method' => $s->payment_method,
                    'created_at'     => $s->created_at,
                ]);
        }

        // Top courses via database SQL aggregation
        $topCourses = $this->getTopCoursesSalesViaSql($request);

        return [
            'total_orders'         => $allOrdersCount,
            'total_transactions'   => $allOrdersCount,
            'total_revenue'        => $totalRevenue,
            'gross_revenue'        => $grossRevenue,
            'net_revenue'          => $totalRevenue,
            'total_refunds'        => $refundsAmount,
            'average_order_value'  => $avgOrderValue,
            'completed_orders'     => $completedCount,
            'pending_orders'       => $orderPendingCount + $subPendingCount,
            'cancelled_orders'     => $orderCancelledCount,
            'failed_orders'        => $orderFailedCount + $subFailedCount,
            'payment_methods'      => $allPaymentMethods,
            'recent_orders'        => $recentOrders,
            'recent_subscriptions' => $recentSubscriptions,
            'subscription_revenue' => $subRevenue,
            'subscription_count'   => $subTotalCount,
            'top_courses'          => $topCourses,
            'revenue_by_country'   => $this->getRevenueByCountry($request),
        ];
    }

    private function getDetailedSalesData($query, $request)
    {
        $perPage = (int) ($request->per_page ?? 15);
        $paginated = (clone $query)->orderBy('orders.created_at', 'desc')->paginate($perPage);
        $summary = $this->getSalesSummaryData($query, $request);
        return array_merge($paginated->toArray(), $summary);
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

        $orderDateSql = $this->dateFormatSql('orders.created_at', $format);
        $ordersChart = (clone $query)
            ->where('orders.status', 'completed')
            ->selectRaw("
                {$orderDateSql} as period,
                COUNT(*) as orders_count,
                SUM(" . ReportMoneySql::orderRevenueEgpSql('orders') . ") as revenue,
                AVG(" . ReportMoneySql::orderRevenueEgpSql('orders') . ") as avg_order_value
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        $subsChart = SubscriptionPayment::query()
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->when(
                $request->filled('course_id') || $request->filled('category_id') || $request->filled('instructor_id'),
                fn ($query) => $query->whereRaw('1 = 0')
            );
        $this->applyDateFilter($subsChart, $request, 'subscription_payments.created_at');
        if ($request->filled('payment_method')) {
            $subsChart->where('subscription_payments.payment_method', $request->payment_method);
        }
        if ($request->boolean('card_gateways_only')) {
            $subsChart->whereIn('subscription_payments.payment_method', self::CARD_GATEWAY_METHODS);
        }

        $subDateSql = $this->dateFormatSql('subscription_payments.created_at', $format);
        $subsResult = $subsChart->selectRaw("
                {$subDateSql} as period,
                COUNT(*) as subs_count,
                SUM(" . ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments') . ") as subs_revenue
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        $periods = $ordersChart->keys()->merge($subsResult->keys())->unique()->sort()->values();

        return $periods->map(function ($period) use ($ordersChart, $subsResult) {
            $orderRow = $ordersChart->get($period);
            $subRow = $subsResult->get($period);
            $ordersCount = (int) ($orderRow->orders_count ?? 0);
            $subsCount = (int) ($subRow->subs_count ?? 0);
            $totalCount = $ordersCount + $subsCount;
            $revenue = (float) ($orderRow->revenue ?? 0) + (float) ($subRow->subs_revenue ?? 0);

            return [
                'period' => $period,
                'orders_count' => $totalCount,
                'revenue' => round($revenue, 2),
                'avg_order_value' => $totalCount > 0 ? round($revenue / $totalCount, 2) : 0.0,
            ];
        })->values();
    }

    private function getCommissionSummaryData($query, $request)
    {
        $baseQuery = (clone $query);
        $baseQuery->getQuery()->orders = null;
        $baseQuery->getQuery()->limit = null;
        $baseQuery->getQuery()->offset = null;

        $joinedQuery = (clone $baseQuery)->join('orders', 'commissions.order_id', '=', 'orders.id');

        $adminEgpSql = ReportMoneySql::commissionAdminEgpSql('commissions', 'orders');
        $instructorEgpSql = ReportMoneySql::commissionInstructorEgpSql('commissions', 'orders');
        $totalEgpSql = ReportMoneySql::commissionTotalEgpSql('commissions', 'orders');

        $metrics = $joinedQuery->selectRaw("
            COUNT(commissions.id) as total_count,
            COUNT(CASE WHEN commissions.status = 'paid' THEN 1 END) as paid_count,
            COUNT(CASE WHEN commissions.status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN commissions.status = 'cancelled' THEN 1 END) as cancelled_count,
            SUM({$totalEgpSql}) as total_commission_amount,
            SUM({$adminEgpSql}) as total_admin_commission_amount,
            SUM(CASE WHEN commissions.status = 'paid' THEN {$adminEgpSql} ELSE 0 END) as paid_commission_amount,
            SUM(CASE WHEN commissions.status = 'pending' THEN {$adminEgpSql} ELSE 0 END) as pending_commission_amount
        ")->first();

        $totalCommissionAmount   = round((float) ($metrics->total_commission_amount ?? 0), 2);
        $totalAdminCommission    = round((float) ($metrics->total_admin_commission_amount ?? 0), 2);
        $paidCommissionAmount    = round((float) ($metrics->paid_commission_amount ?? 0), 2);
        $pendingCommissionAmount = round((float) ($metrics->pending_commission_amount ?? 0), 2);
        $totalCount              = (int) ($metrics->total_count ?? 0);
        $paidCount               = (int) ($metrics->paid_count ?? 0);
        $pendingCount            = (int) ($metrics->pending_count ?? 0);

        // Commission by course via database GROUP BY
        $commissionByCourse = (clone $baseQuery)
            ->join('orders', 'commissions.order_id', '=', 'orders.id')
            ->join('courses', 'commissions.course_id', '=', 'courses.id')
            ->selectRaw("
                commissions.course_id,
                courses.title as course_title,
                courses.slug as course_slug,
                SUM({$adminEgpSql}) as total_commission,
                COUNT(commissions.id) as commission_count
            ")
            ->groupBy('commissions.course_id', 'courses.title', 'courses.slug')
            ->orderByDesc('total_commission')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'course' => [
                    'id' => $row->course_id,
                    'title' => $row->course_title,
                    'slug' => $row->course_slug,
                ],
                'total_commission' => round((float) $row->total_commission, 2),
                'commission_count' => (int) $row->commission_count,
            ])
            ->values();

        $recentCommissions = (clone $query)
            ->with(['course:id,title,slug', 'order:id,order_number,amount_egp,exchange_rate_snapshot'])
            ->orderBy('commissions.created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_commission_amount'       => $totalCommissionAmount,
            'total_admin_commission_amount' => $totalAdminCommission,
            'paid_commission_amount'        => $paidCommissionAmount,
            'pending_commission_amount'     => $pendingCommissionAmount,
            'total_commission_count'        => $totalCount,
            'paid_commission_count'         => $paidCount,
            'pending_commission_count'      => $pendingCount,
            'total_commissions'             => $totalCount,
            'total_admin_commission'        => $totalAdminCommission,
            'paid_commissions'              => $paidCount,
            'pending_commissions'           => $pendingCount,
            'commission_by_course'          => $commissionByCourse,
            'recent_commissions'            => $recentCommissions,
        ];
    }

    private function getDetailedCommissionData($query, $request)
    {
        $perPage = (int) ($request->per_page ?? 15);
        $paginated = (clone $query)->orderBy('commissions.created_at', 'desc')->paginate($perPage);
        $summary = $this->getCommissionSummaryData($query, $request);
        return array_merge($paginated->toArray(), $summary);
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

        $adminEgpSql = ReportMoneySql::commissionAdminEgpSql('commissions', 'orders');
        $commDateSql = $this->dateFormatSql('commissions.created_at', $format);

        return (clone $query)
            ->join('orders', 'commissions.order_id', '=', 'orders.id')
            ->selectRaw("
                {$commDateSql} as period,
                COUNT(*) as commissions_count,
                SUM({$adminEgpSql}) as admin_total
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    private function getCourseSummaryData($query, $request)
    {
        $courses = $query->withCount([
            'orderCourses' => fn($q) => $q->whereHas('order', fn($oq) => $oq->where('status', 'completed')),
            'ratings',
        ])->withAvg('ratings', 'rating')->get();
        $courseIds = $courses->pluck('id')->filter()->values();
        $totalEnrollments = $courseIds->isEmpty() ? 0 : UserCourseProgress::whereIn('course_id', $courseIds)->count();

        return [
            'total_courses'        => $courses->count(),
            'active_courses'       => $courses->where('is_active', true)->count(),
            'free_courses'         => $courses->where('is_free', true)->count(),
            'paid_courses'         => $courses->where('is_free', false)->count(),
            'average_rating'       => round($courses->avg('ratings_avg_rating') ?? 0, 2),
            'total_enrollments'    => $totalEnrollments,
            'courses_by_category'  => $this->getCoursesByCategory($courses),
            'courses_by_level'     => $courses->groupBy('level')->map->count(),
            'top_rated_courses'    => $courses->sortByDesc('ratings_avg_rating')->take(10)->values(),
        ];
    }

    private function getDetailedCourseData($query, $request)
    {
        $perPage        = (int) ($request->per_page ?? 15);
        $paginatedQuery = clone $query;
        $orderCourseRevenueSql = ReportMoneySql::orderCourseRevenueEgpSql('order_courses');

        $paginatedResult = $paginatedQuery
            ->withCount([
                'orderCourses' => fn($q) => $q->whereHas('order', fn($oq) => $oq->where('status', 'completed')),
                'ratings',
            ])
            ->withAvg('ratings', 'rating')
            ->withSum(
                [
                    'orderCourses as revenue' => fn($q) => $q
                        ->whereHas('order', fn($oq) => $oq->where('status', 'completed'))
                        ->select(DB::raw($orderCourseRevenueSql)),
                ],
                DB::raw($orderCourseRevenueSql)
            )
            ->orderBy('courses.created_at', 'desc')
            ->paginate($perPage);

        $summary = $this->getCourseSummaryData($query, $request);
        return array_merge($paginatedResult->toArray(), $summary);
    }

    private function getCoursePerformanceData($query, $request)
    {
        $orderCourseRevenueSql = ReportMoneySql::orderCourseRevenueEgpSql('order_courses');

        return $query
            ->withCount([
                'orderCourses' => fn($q) => $q->whereHas('order', fn($oq) => $oq->where('status', 'completed')),
                'ratings',
            ])
            ->withAvg('ratings', 'rating')
            ->withSum(
                [
                    'orderCourses as revenue' => fn($q) => $q
                        ->whereHas('order', fn($oq) => $oq->where('status', 'completed'))
                        ->select(DB::raw($orderCourseRevenueSql)),
                ],
                DB::raw($orderCourseRevenueSql)
            )
            ->get()
            ->map(function ($course) {
                return [
                    'course'              => $course,
                    'performance_metrics' => [
                        'enrollments'    => $course->order_courses_count,
                        'revenue'        => round((float) ($course->revenue ?? 0), 2),
                        'rating'         => round($course->ratings_avg_rating ?? 0, 2),
                        'reviews_count'  => $course->ratings_count,
                    ],
                ];
            });
    }

    private function getInstructorSummaryData($query, $request)
    {
        $instructors = $query->with(['user.courses'])->get();
        $instructorIds = $instructors
            ->map(fn($instructor) => $instructor?->user?->id)
            ->filter()
            ->values()
            ->all();
        $totalRevenue = 0.0;
        if ($instructorIds !== []) {
            $totalRevenue = (float) DB::table('order_courses')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->join('courses', 'order_courses.course_id', '=', 'courses.id')
                ->whereIn('courses.user_id', $instructorIds)
                ->where('orders.status', 'completed')
                ->selectRaw('SUM(' . ReportMoneySql::orderRevenueEgpSql('orders') . ') as total_revenue')
                ->value('total_revenue');
        }

        return [
            'total_instructors' => $instructors->count(),
            'individual_instructors' => $instructors->where('type', 'individual')->count(),
            'team_instructors' => $instructors->where('type', 'team')->count(),
            'approved_instructors' => $instructors->where('status', 'approved')->count(),
            'pending_instructors' => $instructors->where('status', 'pending')->count(),
            'total_courses_created' => $instructors->sum(static fn($instructor) => $instructor->user?->courses?->count() ?? 0),
            'total_revenue_egp' => round($totalRevenue, 2),
            'top_instructors_by_courses' => $this->getTopInstructorsByCourses($instructors),
        ];
    }

    private function getDetailedInstructorData($query, $request)
    {
        $perPage = (int) ($request->per_page ?? 15);
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery
            ->with(['user.courses' => static function ($q): void {
                $q->withCount('orderCourses');
            }])
            ->orderBy('instructors.created_at', 'desc')
            ->paginate($perPage);
            
        $summary = $this->getInstructorSummaryData($query, $request);
        return array_merge($paginatedResult->toArray(), $summary);
    }

    private function getInstructorPerformanceData($query, $request)
    {
        return $query->with([
            'user.courses' => static function ($q): void {
                $q->withCount('orderCourses')->with('ratings:id,course_id,rating');
            },
        ])->get()->map(static function ($instructor) {
            $courses = $instructor->user?->courses ?? collect();

            return [
                'instructor' => $instructor,
                'performance_metrics' => [
                    'total_courses' => $courses->count(),
                    'total_enrollments' => $courses->sum('order_courses_count'),
                    'average_rating' => round(
                        $courses->avg(static fn($course) => $course->ratings->avg('rating')) ?? 0,
                        2,
                    ),
                ],
            ];
        });
    }

    private function getEnrollmentSummaryData($query, $request)
    {
        $aggQuery = (clone $query);
        $aggQuery->getQuery()->orders = null;
        $aggQuery->getQuery()->limit = null;
        $aggQuery->getQuery()->offset = null;

        $metrics = $aggQuery->selectRaw("
            COUNT(*) as total_count,
            COUNT(CASE WHEN user_course_progress.status = 'not_started' OR user_course_progress.status = 'started' THEN 1 END) as not_started_count,
            COUNT(CASE WHEN user_course_progress.status = 'in_progress' THEN 1 END) as in_progress_count,
            COUNT(CASE WHEN user_course_progress.status = 'completed' THEN 1 END) as completed_count
        ")->first();

        $totalEnrollments   = (int) ($metrics->total_count ?? 0);
        $notStartedCount    = (int) ($metrics->not_started_count ?? 0);
        $inProgressCount    = (int) ($metrics->in_progress_count ?? 0);
        $completedCount     = (int) ($metrics->completed_count ?? 0);
        $completionRate     = $totalEnrollments > 0 ? round(($completedCount / $totalEnrollments) * 100, 2) : 0.0;

        // Enrollments by course via database SQL
        $enrollmentsByCourse = (clone $query)
            ->join('courses', 'user_course_progress.course_id', '=', 'courses.id')
            ->selectRaw("
                user_course_progress.course_id,
                courses.title as course_title,
                courses.slug as course_slug,
                COUNT(user_course_progress.id) as enrollment_count,
                COUNT(CASE WHEN user_course_progress.status = 'completed' THEN 1 END) as completed_count
            ")
            ->groupBy('user_course_progress.course_id', 'courses.title', 'courses.slug')
            ->orderByDesc('enrollment_count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'course' => [
                    'id'    => $row->course_id,
                    'title' => $row->course_title,
                    'slug'  => $row->course_slug,
                ],
                'enrollment_count' => (int) $row->enrollment_count,
                'completed_count'  => (int) $row->completed_count,
            ])
            ->values();

        // Enrollments by month via database SQL
        $monthSql = $this->dateFormatSql('user_course_progress.created_at', '%Y-%m');
        $enrollmentsByMonth = (clone $query)
            ->selectRaw("{$monthSql} as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        // Recent enrollments (limit 10)
        $recentEnrollments = (clone $query)
            ->with(['user:id,name,email', 'course:id,title,slug'])
            ->orderBy('user_course_progress.created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_enrollments'       => $totalEnrollments,
            'started_enrollments'     => $notStartedCount,
            'not_started_enrollments' => $notStartedCount,
            'in_progress_enrollments' => $inProgressCount,
            'completed_enrollments'   => $completedCount,
            'completion_rate'         => $completionRate,
            'enrollments_by_course'   => $enrollmentsByCourse,
            'enrollments_by_month'    => $enrollmentsByMonth,
            'recent_enrollments'      => $recentEnrollments,
        ];
    }

    private function getDetailedEnrollmentData($query, $request)
    {
        $perPage = (int) ($request->per_page ?? 15);
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery->orderBy('user_course_progress.created_at', 'desc')->paginate($perPage);
        $summary = $this->getEnrollmentSummaryData($query, $request);
        return array_merge($paginatedResult->toArray(), $summary);
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

        $periodSql = $this->dateFormatSql('user_course_progress.created_at', $format);

        return (clone $query)->selectRaw("
                {$periodSql} as period,
                COUNT(*) as enrollment_count,
                COUNT(CASE WHEN user_course_progress.status = 'completed' THEN 1 END) as completed_count
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getTopCoursesSalesViaSql(Request $request)
    {
        return DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->join('courses', 'order_courses.course_id', '=', 'courses.id')
            ->where('orders.status', 'completed')
            ->when($request->filled('date_from') || $request->filled('from_date') || $request->filled('date_to') || $request->filled('to_date'), function ($q) use ($request) {
                $this->applyDateFilter($q, $request, 'orders.created_at');
            })
            ->when($request->filled('course_id'), fn($q) => $q->where('order_courses.course_id', $request->course_id))
            ->when($request->filled('instructor_id'), fn($q) => $q->where('courses.user_id', $request->instructor_id))
            ->when($request->filled('category_id'), fn($q) => $q->where('courses.category_id', $request->category_id))
            ->when($request->filled('payment_method'), fn($q) => $q->where('orders.payment_method', $request->payment_method))
            ->when($request->boolean('card_gateways_only'), fn($q) => $q->whereIn('orders.payment_method', self::CARD_GATEWAY_METHODS))
            ->selectRaw('
                order_courses.course_id,
                courses.id as course_id_full,
                courses.title as course_title,
                courses.slug as course_slug,
                courses.price as course_price,
                SUM(' . ReportMoneySql::orderCourseRevenueEgpSql('order_courses') . ') as total_sales,
                COUNT(order_courses.id) as total_orders
            ')
            ->groupBy('order_courses.course_id', 'courses.id', 'courses.title', 'courses.slug', 'courses.price')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'course' => [
                    'id' => $row->course_id,
                    'title' => $row->course_title,
                    'slug' => $row->course_slug,
                    'price' => (float) $row->course_price,
                ],
                'total_sales' => round((float) $row->total_sales, 2),
                'total_orders' => (int) $row->total_orders,
            ])
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
                'courses_count' => $instructor->user?->courses?->count() ?? 0,
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
            ->take(10)
            ->values();
    }

    private function getEnrollmentsByMonth($enrollments)
    {
        return $enrollments
            ->groupBy(static fn($enrollment) => $enrollment->created_at->format('Y-m'))
            ->map->count()->sortKeys();
    }

    private function calculateGrowthPercentage($old, $new)
    {
        if ($old == 0) {
            return $new > 0 ? 100 : 0;
        }

        return round((($new - $old) / $old) * 100, 2);
    }

    private function getRevenueByCountry(Request $request)
    {
        $ordersQuery = Order::query()->where('orders.status', 'completed');
        $this->applyCourseFilter($ordersQuery, $request);
        $this->applyInstructorFilter($ordersQuery, $request);
        $this->applyPaymentMethodFilter($ordersQuery, $request);
        $this->applyCategoryFilter($ordersQuery, $request);
        if ($request->filled('status') && $request->status !== 'completed') {
            $ordersQuery->whereRaw('1 = 0');
        }
        if ($request->boolean('card_gateways_only')) {
            $ordersQuery->whereIn('orders.payment_method', self::CARD_GATEWAY_METHODS);
        }

        $this->applyDateFilter($ordersQuery, $request, 'orders.created_at');

        $ordersByCountry = $ordersQuery
            ->selectRaw("COALESCE(orders.resolved_country, users.country_code, 'NA') as country_code")
            ->selectRaw('COALESCE(orders.currency_code, "EGP") as currency_code')
            ->selectRaw('COUNT(*) as transactions_count')
            ->selectRaw('SUM(' . ReportMoneySql::orderRevenueLocalSql('orders') . ') as revenue_local')
            ->selectRaw('SUM(' . ReportMoneySql::orderRevenueEgpSql('orders') . ') as revenue_egp')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->groupBy('country_code', 'currency_code')
            ->get();

        $subsByCountryQuery = SubscriptionPayment::query()
            ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
            ->when(
                $request->filled('course_id') || $request->filled('category_id') || $request->filled('instructor_id'),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->when($request->filled('status') && $request->status !== 'completed', fn ($query) => $query->whereRaw('1 = 0'))
            ->when($request->filled('payment_method'), fn ($query) => $query->where('subscription_payments.payment_method', $request->payment_method))
            ->when($request->boolean('card_gateways_only'), fn ($query) => $query->whereIn('subscription_payments.payment_method', self::CARD_GATEWAY_METHODS));

        $this->applyDateFilter($subsByCountryQuery, $request, 'subscription_payments.created_at');

        $subsByCountry = $subsByCountryQuery
            ->selectRaw("COALESCE(subscription_payments.resolved_country, 'NA') as country_code")
            ->selectRaw('COALESCE(subscription_payments.currency_code, "EGP") as currency_code')
            ->selectRaw('COUNT(*) as transactions_count')
            ->selectRaw('SUM(' . ReportMoneySql::subscriptionRevenueLocalSql('subscription_payments') . ') as revenue_local')
            ->selectRaw('SUM(' . ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments') . ') as revenue_egp')
            ->groupBy('country_code', 'currency_code')
            ->get();

        $merged = $ordersByCountry
            ->concat($subsByCountry)
            ->groupBy(fn($row) => strtoupper((string) $row->country_code) . '|' . strtoupper((string) $row->currency_code))
            ->map(function ($rows) {
                $first = $rows->first();
                $revenueEgp = (float) $rows->sum('revenue_egp');

                return [
                    'country_code' => strtoupper((string) $first->country_code),
                    'currency_code' => strtoupper((string) $first->currency_code),
                    'transactions_count' => (int) $rows->sum('transactions_count'),
                    'revenue_local' => round((float) $rows->sum('revenue_local'), 2),
                    'revenue_egp' => round($revenueEgp, 2),
                ];
            })
            ->sortByDesc('revenue_egp')
            ->values();

        $totalEgp = (float) $merged->sum('revenue_egp');

        return $merged->map(function (array $row) use ($totalEgp) {
            $row['share_percent'] = $totalEgp > 0 ? round(($row['revenue_egp'] / $totalEgp) * 100, 2) : 0;
            return $row;
        })->values();
    }
}
