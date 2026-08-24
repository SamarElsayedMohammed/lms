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
use App\Services\Reports\InstructorCourseMetrics;
use App\Services\Reports\ReportingPeriodService;
use App\Services\Reports\ReportMoneySql;
use App\Services\Reports\UnifiedSalesTransactionQuery;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
            $period = (new ReportingPeriodService())->applyToRequest($request);
            $validator = Validator::make($request->all(), [
                'date_from'      => 'nullable|date',
                'date_to'        => 'nullable|date|after_or_equal:date_from',
                'preset'         => 'nullable|string|max:40',
                'course_id'      => 'nullable|exists:courses,id',
                'instructor_id'  => 'nullable|exists:users,id',
                'status'         => 'nullable|in:pending,completed,cancelled,failed',
                'payment_method' => 'nullable|string|max:50',
                'category_id'    => 'nullable|exists:categories,id',
                'report_type'    => 'nullable|in:summary,detailed,chart,export',
                'group_by'       => 'nullable|in:day,week,month,year',
                'per_page'       => 'nullable|integer|min:1|max:100',
                'product_type'   => 'nullable|in:all,course,course_order,subscription',
                'transaction_type' => 'nullable|in:all,course,course_order,subscription',
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
                'export' => $this->getExportedSalesData($query, $request),
                default => $this->getSalesSummaryData($query, $request),
            };

            return ApiResponseService::successResponse('Sales report generated successfully', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (ValidationException $e) {
            return ApiResponseService::validationError($e->getMessage());
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

            $courseQuery = Course::query();
            $this->applyCourseReportFilters($courseQuery, $request);

            $instructorQuery = Instructor::query();
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
                'status' => 'nullable|in:active,inactive,published,publish,draft,pending,rejected,all',
                'approval_status' => 'nullable|in:pending,approved,rejected,all',
                'course_type' => 'nullable|in:free,paid,all',
                'level' => 'nullable|in:beginner,intermediate,advanced,all',
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
                'instructor_type' => 'nullable|in:individual,team,all',
                'status' => 'nullable|in:pending,approved,rejected,suspended,active,all',
                'report_type' => 'nullable|in:summary,detailed,performance',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Instructor::with(['user']);

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
                                            ->limit(500)
                                            ->get(),
                'instructors'         => \Illuminate\Support\Facades\DB::table('users')
                                            ->whereNull('deleted_at')
                                            ->whereExists(function ($q) {
                                                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                                                  ->from('instructors')
                                                  ->whereColumn('instructors.user_id', 'users.id');
                                            })
                                            ->select('id', 'name', 'email')
                                            ->limit(500)
                                            ->get(),
                'categories'          => \Illuminate\Support\Facades\DB::table('categories')
                                            ->whereNull('deleted_at')
                                            ->select('id', 'name')
                                            ->orderBy('name')
                                            ->limit(500)
                                            ->get(),
                'order_statuses'      => ['pending', 'completed', 'cancelled', 'failed'],
                'commission_statuses' => ['pending', 'paid', 'cancelled'],
                'payment_methods'     => ['stripe', 'razorpay', 'flutterwave', 'wallet'],
                'instructor_types'    => ['individual', 'team'],
                'course_statuses'     => ['published', 'draft', 'active', 'inactive', 'pending'],
                'course_types'        => ['free', 'paid'],
                'course_levels'       => ['beginner', 'intermediate', 'advanced'],
                'enrollment_statuses' => ['started', 'in_progress', 'completed'],
                'approval_statuses'   => ['pending', 'approved', 'rejected', 'suspended'],
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
        $tz = (string) config('app.timezone', 'UTC');
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;
        $isExpression = str_contains($column, '(');

        if (!empty($dateFrom) && !empty($dateTo)) {
            $start = Carbon::parse($dateFrom, $tz)->startOfDay();
            $end = Carbon::parse($dateTo, $tz)->endOfDay();
            if ($isExpression) {
                $query->whereRaw("{$column} BETWEEN ? AND ?", [$start, $end]);
            } else {
                $query->whereBetween($column, [$start, $end]);
            }
        } elseif (!empty($dateFrom)) {
            $start = Carbon::parse($dateFrom, $tz)->startOfDay();
            if ($isExpression) {
                $query->whereRaw("{$column} >= ?", [$start]);
            } else {
                $query->where($column, '>=', $start);
            }
        } elseif (!empty($dateTo)) {
            $end = Carbon::parse($dateTo, $tz)->endOfDay();
            if ($isExpression) {
                $query->whereRaw("{$column} <= ?", [$end]);
            } else {
                $query->where($column, '<=', $end);
            }
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
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;
        if (!empty($dateFrom) || !empty($dateTo)) {
            $this->applyDateFilter($query, $request, 'courses.created_at');
        }

        if ($request->filled('course_id')) {
            $query->where('courses.id', $request->course_id);
        }
        if ($request->filled('instructor_id')) {
            $query->where('courses.user_id', $request->instructor_id);
        }
        if ($request->filled('category_id')) {
            $query->where('courses.category_id', $request->category_id);
        }
        if ($request->filled('status')) {
            if ($request->status === 'all') {
                // Do not apply status restriction when 'all' is explicitly requested
            } elseif ($request->status === 'active' || $request->status === 'published' || $request->status === 'publish') {
                $query->where('courses.is_active', true)->where('courses.status', 'publish');
            } elseif ($request->status === 'inactive') {
                $query->where('courses.is_active', false);
            } elseif ($request->status === 'draft') {
                $query->where('courses.status', 'draft');
            } elseif ($request->status === 'pending') {
                $query->where('courses.status', 'pending');
            } elseif ($request->status === 'rejected') {
                $query->where('courses.status', 'rejected');
            } else {
                $query->where(static fn ($courses) => $courses
                    ->where('courses.is_active', false)
                    ->orWhere('courses.status', '!=', 'publish'));
            }
        } else {
            $query->where('courses.is_active', true)->where('courses.status', 'publish');
        }
        if ($request->filled('approval_status') && $request->approval_status !== 'all') {
            $query->where('courses.approval_status', $request->approval_status);
        }
        if ($request->filled('course_type') && $request->course_type !== 'all') {
            $query->where('courses.is_free', $request->course_type === 'free' ? 1 : 0);
        }
        if ($request->filled('level') && $request->level !== 'all') {
            $query->where('courses.level', $request->level);
        }
    }

    private function applyInstructorReportFilters($query, $request)
    {
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;
        if (!empty($dateFrom) || !empty($dateTo)) {
            $this->applyDateFilter($query, $request, 'instructors.created_at');
        }

        if ($request->filled('instructor_id')) {
            $query->where('user_id', $request->instructor_id);
        }
        if ($request->filled('instructor_type') && $request->instructor_type !== 'all') {
            $query->where('type', $request->instructor_type);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $status = $request->status;
            if ($status === 'active') {
                $query->where('status', 'approved');
            } else {
                $query->where('status', $status);
            }
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
            $this->applyDateFilter($subQuery, $request, ReportMoneySql::subscriptionPaymentDateSql());
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
        $this->applyDateFilter($refundQuery, $request, ReportMoneySql::refundDateSql());
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
            ->map(static function ($o): array {
                $isCompleted = $o->status === 'completed';
                $orderAmount = (float) ($o->amount_egp ?? (($o->final_price ?? 0) * ($o->exchange_rate_snapshot ?? 1)));

                return [
                    'id'             => $o->id,
                    'status'         => $o->status,
                    'final_price'    => (float) ($o->final_price ?? 0),
                    'net_payable'    => (float) ($o->final_price ?? 0),
                    'paid_amount'    => $isCompleted ? (float) ($o->final_price ?? 0) : 0.0,
                    'amount'         => $isCompleted ? $orderAmount : 0.0,
                    'payment_method' => $o->payment_method,
                    'created_at'     => $o->created_at,
                    'user'           => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
                ];
            });

        // Recent subscriptions (limit 10)
        $recentSubscriptions = collect();
        if (!$hasCourseFilters) {
            $recentSubscriptions = (clone $subQuery)
                ->orderByRaw(ReportMoneySql::subscriptionPaymentDateSql() . ' DESC')
                ->limit(10)
                ->get()
                ->map(static function ($s): array {
                    $isCompleted = $s->status === SubscriptionPayment::STATUS_COMPLETED;
                    $finalAmount = (float) ($s->final_amount ?? $s->amount ?? 0);

                    return [
                        'id'             => $s->id,
                        'status'         => $s->status,
                        'amount'         => $isCompleted ? $finalAmount : 0.0,
                        'net_payable'    => $finalAmount,
                        'paid_amount'    => $isCompleted ? $finalAmount : 0.0,
                        'payment_method' => $s->payment_method,
                        'created_at'     => $s->paid_at ?? $s->created_at,
                        'paid_at'        => $isCompleted ? $s->paid_at : null,
                        'product_type'   => 'subscription',
                        'course'         => 'اشتراك',
                    ];
                });
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
            'financial_time_model' => [
                'course_orders' => 'orders.created_at',
                'subscription_payments' => 'coalesce(paid_at, created_at)',
                'refunds' => 'refund_recognition_date',
                'refund_date_sql' => 'coalesce(processed_at, created_at)',
                'gross' => 'settled_in_period',
                'net' => 'gross_in_period_minus_refunds_recognized_in_period',
            ],
            'timezone' => (string) config('app.timezone', 'UTC'),
            'generated_at' => Carbon::now((string) config('app.timezone', 'UTC'))->toIso8601String(),
        ];
    }

    private function getDetailedSalesData($query, $request)
    {
        $summary = $this->getSalesSummaryData($query, $request);
        $hasCourseFilters = $request->filled('course_id') || $request->filled('category_id') || $request->filled('instructor_id');
        $pageResult = (new UnifiedSalesTransactionQuery())->paginate($request, !$hasCourseFilters);
        $result = $pageResult['paginator']->toArray();
        $result['summary'] = $summary;
        $result['pagination_mode'] = $pageResult['pagination_mode'];
        $result['table_scope'] = 'filtered_course_orders_and_subscription_payments';
        $result['export_scope'] = 'all_filtered_rows_via_export_endpoint';
        return array_merge($result, $summary);
    }

    private function getExportedSalesData($query, $request)
    {
        $hasCourseFilters = $request->filled('course_id') || $request->filled('category_id') || $request->filled('instructor_id');
        $exported = (new UnifiedSalesTransactionQuery())->export($request, !$hasCourseFilters);
        $summary = $this->getSalesSummaryData($query, $request);

        return array_merge($summary, $exported);
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
        $this->applyDateFilter($subsChart, $request, ReportMoneySql::subscriptionPaymentDateSql());
        if ($request->filled('payment_method')) {
            $subsChart->where('subscription_payments.payment_method', $request->payment_method);
        }
        if ($request->boolean('card_gateways_only')) {
            $subsChart->whereIn('subscription_payments.payment_method', self::CARD_GATEWAY_METHODS);
        }

        $subDateSql = $this->dateFormatSql(ReportMoneySql::subscriptionPaymentDateSql(), $format);
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
        $base = $this->aggregateClone($query);
        $courseIds = $this->courseIdSubquery($base);

        $counts = (clone $base)->selectRaw("
            COUNT(*) as total_courses,
            SUM(CASE WHEN courses.is_active = 1 THEN 1 ELSE 0 END) as active_courses,
            SUM(CASE WHEN courses.is_free = 1 THEN 1 ELSE 0 END) as free_courses,
            SUM(CASE WHEN courses.is_free = 0 THEN 1 ELSE 0 END) as paid_courses
        ")->first();

        $purchaseCount = (int) DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->whereIn('order_courses.course_id', $courseIds)
            ->count();

        $uniqueStudentsCount = (int) DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->whereIn('order_courses.course_id', $courseIds)
            ->distinct()
            ->count('orders.user_id');

        $progressCount = (int) UserCourseProgress::query()
            ->whereIn('course_id', $courseIds)
            ->count();

        $orderCourseRevenueSql = ReportMoneySql::orderCourseRevenueEgpSql('order_courses');
        $totalRevenue = (float) DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->whereIn('order_courses.course_id', $courseIds)
            ->sum(DB::raw($orderCourseRevenueSql));

        $averageRating = (float) DB::table('ratings')
            ->where('rateable_type', Course::class)
            ->whereIn('rateable_id', $courseIds)
            ->avg('rating');

        $coursesByCategory = (clone $base)
            ->leftJoin('categories', 'categories.id', '=', 'courses.category_id')
            ->selectRaw("COALESCE(categories.name, '') as category_name, COUNT(courses.id) as total")
            ->groupBy('category_name')
            ->pluck('total', 'category_name');

        $coursesByLevel = (clone $base)
            ->selectRaw('courses.level as level, COUNT(*) as total')
            ->groupBy('courses.level')
            ->pluck('total', 'level');

        $topRatedIds = (clone $base)
            ->leftJoin('ratings', function ($join): void {
                $join->on('ratings.rateable_id', '=', 'courses.id')
                    ->where('ratings.rateable_type', Course::class);
            })
            ->select('courses.id')
            ->groupBy('courses.id')
            ->orderByRaw('AVG(ratings.rating) DESC')
            ->limit(10)
            ->pluck('id');

        $topRatedCourses = $topRatedIds->isEmpty()
            ? collect()
            : Course::query()
                ->whereIn('id', $topRatedIds)
                ->get()
                ->sortBy(fn ($course) => array_search($course->id, $topRatedIds->all(), true))
                ->values();

        return [
            'total_courses'        => (int) ($counts->total_courses ?? 0),
            'active_courses'       => (int) ($counts->active_courses ?? 0),
            'free_courses'         => (int) ($counts->free_courses ?? 0),
            'paid_courses'         => (int) ($counts->paid_courses ?? 0),
            'average_rating'       => round($averageRating, 2),
            'total_enrollments'    => $purchaseCount,
            'course_purchases_count' => $purchaseCount,
            'progress_records_count' => $progressCount,
            'enrollment_grain'     => 'completed_course_purchases_not_progress_rows',
            'total_students'       => $uniqueStudentsCount,
            'students_grain'       => 'unique_users_with_completed_course_purchases',
            'total_revenue_egp'    => round($totalRevenue, 2),
            'courses_by_category'  => $coursesByCategory,
            'courses_by_level'     => $coursesByLevel,
            'top_rated_courses'    => $topRatedCourses,
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
            ->with(['user', 'category'])
            ->orderBy('courses.created_at', 'desc')
            ->paginate($perPage);

        $paginatedResult->getCollection()->transform(function ($course) {
            $courseArray = $course->toArray();
            $courseArray['students_count'] = (int) ($course->order_courses_count ?? 0);
            $courseArray['enrollments_count'] = (int) ($course->order_courses_count ?? 0);
            $courseArray['total_enrollments'] = (int) ($course->order_courses_count ?? 0);
            $courseArray['avg_rating'] = round((float) ($course->ratings_avg_rating ?? 0), 2);
            $courseArray['average_rating'] = round((float) ($course->ratings_avg_rating ?? 0), 2);
            $courseArray['rating'] = round((float) ($course->ratings_avg_rating ?? 0), 2);
            $courseArray['revenue'] = round((float) ($course->revenue ?? 0), 2);
            $courseArray['total_sales'] = round((float) ($course->revenue ?? 0), 2);
            $courseArray['instructor_name'] = $course->user?->name ?? 'غير معروف';
            $courseArray['category_name'] = $course->category?->name ?? 'غير مصنف';
            return $courseArray;
        });

        $summary = $this->getCourseSummaryData($query, $request);
        $result = $paginatedResult->toArray();
        $result['summary'] = $summary;
        return array_merge($result, $summary);
    }

    private function getCoursePerformanceData($query, $request)
    {
        $perPage = max(1, min(100, (int) ($request->per_page ?? 15)));
        $orderCourseRevenueSql = ReportMoneySql::orderCourseRevenueEgpSql('order_courses');

        $paginated = (clone $query)
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

        $paginated->getCollection()->transform(function ($course) {
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

        return $paginated->toArray();
    }

    private function getInstructorSummaryData($query, $request)
    {
        $base = $this->aggregateClone($query);
        $counts = (clone $base)->selectRaw("
            COUNT(*) as total_instructors,
            SUM(CASE WHEN instructors.type = 'individual' THEN 1 ELSE 0 END) as individual_instructors,
            SUM(CASE WHEN instructors.type = 'team' THEN 1 ELSE 0 END) as team_instructors,
            SUM(CASE WHEN instructors.status = 'approved' THEN 1 ELSE 0 END) as approved_instructors,
            SUM(CASE WHEN instructors.status = 'pending' THEN 1 ELSE 0 END) as pending_instructors,
            SUM(CASE WHEN instructors.status = 'rejected' THEN 1 ELSE 0 END) as rejected_instructors,
            SUM(CASE WHEN instructors.status = 'suspended' THEN 1 ELSE 0 END) as suspended_instructors
        ")->first();

        $userIds = (clone $base)
            ->pluck('instructors.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $courseMetrics = InstructorCourseMetrics::countsForUsers($userIds);
        $engagement = InstructorCourseMetrics::engagementForUsers($userIds);
        $global = InstructorCourseMetrics::globalEngagement($userIds);

        $rankedUserIds = collect($engagement)
            ->map(static function (array $stats, int|string $userId) use ($courseMetrics): array {
                return [
                    'user_id' => (int) $userId,
                    'courses_count' => $courseMetrics[(int) $userId]['courses_count'] ?? 0,
                    'students_count' => (int) ($stats['students_count'] ?? 0),
                    'average_rating' => $stats['average_rating'] ?? null,
                ];
            })
            ->filter(static fn (array $row): bool => ($row['students_count'] ?? 0) > 0 || ($row['average_rating'] ?? 0) > 0)
            ->sortByDesc('courses_count')
            ->take(10)
            ->pluck('user_id')
            ->all();

        $topInstructors = $rankedUserIds === []
            ? collect()
            : (clone $base)->with(['user'])->whereIn('instructors.user_id', $rankedUserIds)->get();
        $ranked = $this->getTopInstructorsByCourses($topInstructors, $courseMetrics, $engagement);

        return [
            'total_instructors'          => (int) ($counts->total_instructors ?? 0),
            'individual_instructors'     => (int) ($counts->individual_instructors ?? 0),
            'team_instructors'           => (int) ($counts->team_instructors ?? 0),
            'approved_instructors'       => (int) ($counts->approved_instructors ?? 0),
            'pending_instructors'        => (int) ($counts->pending_instructors ?? 0),
            'rejected_instructors'       => (int) ($counts->rejected_instructors ?? 0),
            'suspended_instructors'      => (int) ($counts->suspended_instructors ?? 0),
            'total_courses_created'      => (int) array_sum(array_column($courseMetrics, 'courses_count')),
            'total_enrollments'          => $global['total_enrollments'],
            'total_students'             => $global['total_students'],
            'total_revenue_egp'          => $global['total_revenue_egp'],
            'top_instructors_by_courses' => $ranked['rows'],
            'ranking_insufficient_data'  => $ranked['insufficient'],
            'instructor_course_rule'     => 'owned_courses_user_id_union_assigned_course_instructors',
            'students_grain'             => 'unique_completed_course_purchasers_on_owned_or_assigned_courses',
        ];
    }

    private function getDetailedInstructorData($query, $request)
    {
        $perPage = (int) ($request->per_page ?? 15);
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery
            ->with(['user', 'personal_details'])
            ->orderBy('instructors.created_at', 'desc')
            ->paginate($perPage);

        $metrics = InstructorCourseMetrics::countsForInstructors($paginatedResult->getCollection());
        $engagement = InstructorCourseMetrics::engagementForUsers(array_keys($metrics));
        $paginatedResult->getCollection()->transform(function ($instructor) use ($metrics, $engagement) {
            $userId = (int) $instructor->user_id;
            $counts = $metrics[$userId] ?? [
                'courses_count' => 0,
                'owned_courses_count' => 0,
                'assigned_courses_count' => 0,
                'published_courses_count' => 0,
            ];
            $stats = $engagement[$userId] ?? [
                'students_count' => 0,
                'average_rating' => null,
            ];
            $instructorArray = $instructor->toArray();
            $instructorArray['name'] = $instructor->user?->name ?? 'غير معروف';
            $instructorArray['email'] = $instructor->user?->email ?? '';
            $instructorArray['user_id'] = $userId;
            $instructorArray['courses_count'] = $counts['courses_count'];
            $instructorArray['owned_courses_count'] = $counts['owned_courses_count'];
            $instructorArray['assigned_courses_count'] = $counts['assigned_courses_count'];
            $instructorArray['published_courses_count'] = $counts['published_courses_count'];
            $instructorArray['total_courses'] = $counts['courses_count'];
            $instructorArray['students_count'] = $stats['students_count'];
            $instructorArray['total_students'] = $stats['students_count'];
            $instructorArray['students'] = $stats['students_count'];
            $instructorArray['avg_rating'] = $stats['average_rating'];
            $instructorArray['average_rating'] = $stats['average_rating'];
            $instructorArray['rating'] = $stats['average_rating'];

            return $instructorArray;
        });

        $summary = $this->getInstructorSummaryData($query, $request);
        $result = $paginatedResult->toArray();
        $result['summary'] = $summary;
        return array_merge($result, $summary);
    }

    private function getInstructorPerformanceData($query, $request)
    {
        $perPage = max(1, min(100, (int) ($request->per_page ?? 15)));
        $paginated = (clone $query)
            ->with(['user'])
            ->orderBy('instructors.created_at', 'desc')
            ->paginate($perPage);

        $courseMetrics = InstructorCourseMetrics::countsForInstructors($paginated->getCollection());
        $engagement = InstructorCourseMetrics::engagementForUsers(array_keys($courseMetrics));

        $paginated->getCollection()->transform(static function ($instructor) use ($courseMetrics, $engagement) {
            $userId = (int) $instructor->user_id;
            $counts = $courseMetrics[$userId] ?? ['courses_count' => 0];
            $stats = $engagement[$userId] ?? ['students_count' => 0, 'average_rating' => null];

            return [
                'instructor' => $instructor,
                'performance_metrics' => [
                    'total_courses' => $counts['courses_count'],
                    'total_enrollments' => $stats['students_count'],
                    'average_rating' => $stats['average_rating'],
                ],
            ];
        });

        return $paginated->toArray();
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
            'access_grain'            => 'progress_records_on_published_courses',
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

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $instructors
     * @param  array<int, array{courses_count: int, owned_courses_count: int, assigned_courses_count: int, published_courses_count: int}>  $courseMetrics
     * @param  array<int, array{students_count: int, average_rating: float|null, course_ids?: list<int>}>  $engagement
     * @return array{rows: array<int, array<string, mixed>>, insufficient: bool}
     */
    private function getTopInstructorsByCourses($instructors, array $courseMetrics = [], array $engagement = []): array
    {
        $rows = $instructors
            ->map(function ($instructor) use ($courseMetrics, $engagement) {
                $userId = (int) $instructor->user_id;
                $counts = $courseMetrics[$userId] ?? InstructorCourseMetrics::countsForUser($userId);
                $stats = $engagement[$userId] ?? ['students_count' => 0, 'average_rating' => null];
                $studentsCount = (int) $stats['students_count'];
                $avgRating = $stats['average_rating'];

                return [
                    'instructor'      => $instructor,
                    'id'              => $instructor->id,
                    'user_id'         => $userId,
                    'name'            => $instructor->user?->name ?? 'غير معروف',
                    'email'           => $instructor->user?->email ?? '',
                    'courses_count'   => $counts['courses_count'],
                    'owned_courses_count' => $counts['owned_courses_count'],
                    'assigned_courses_count' => $counts['assigned_courses_count'],
                    'courses'         => $counts['courses_count'],
                    'students_count'  => $studentsCount,
                    'students'        => $studentsCount,
                    'total_students'  => $studentsCount,
                    'average_rating'  => $avgRating,
                    'rating'          => $avgRating,
                ];
            })
            ->filter(static fn (array $row) => ($row['students_count'] ?? 0) > 0 || ($row['average_rating'] ?? 0) > 0)
            ->sortByDesc('courses_count')
            ->take(10)
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'insufficient' => $rows === [],
        ];
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
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->selectRaw(ReportMoneySql::unassignedCountrySql('orders.resolved_country', 'users.country_code') . ' as country_code')
            ->selectRaw("UPPER(COALESCE(orders.currency_code, 'EGP')) as currency_code")
            ->selectRaw('COUNT(orders.id) as transactions_count')
            ->selectRaw('SUM(' . ReportMoneySql::orderRevenueLocalSql('orders') . ') as revenue_local')
            ->selectRaw('SUM(' . ReportMoneySql::orderRevenueEgpSql('orders') . ') as revenue_egp')
            ->groupBy(DB::raw(ReportMoneySql::unassignedCountrySql('orders.resolved_country', 'users.country_code') . ", UPPER(COALESCE(orders.currency_code, 'EGP'))"))
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

        $this->applyDateFilter($subsByCountryQuery, $request, ReportMoneySql::subscriptionPaymentDateSql());

        $subsByCountry = $subsByCountryQuery
            ->selectRaw(ReportMoneySql::unassignedCountrySql('subscription_payments.resolved_country') . ' as country_code')
            ->selectRaw("UPPER(COALESCE(subscription_payments.currency_code, 'EGP')) as currency_code")
            ->selectRaw('COUNT(subscription_payments.id) as transactions_count')
            ->selectRaw('SUM(' . ReportMoneySql::subscriptionRevenueLocalSql('subscription_payments') . ') as revenue_local')
            ->selectRaw('SUM(' . ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments') . ') as revenue_egp')
            ->groupBy(DB::raw(ReportMoneySql::unassignedCountrySql('subscription_payments.resolved_country') . ", UPPER(COALESCE(subscription_payments.currency_code, 'EGP'))"))
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

    private function aggregateClone($query)
    {
        $clone = clone $query;
        $clone->setEagerLoads([]);
        $clone->getQuery()->orders = null;
        $clone->getQuery()->limit = null;
        $clone->getQuery()->offset = null;
        $clone->getQuery()->groups = null;
        $clone->getQuery()->havings = null;
        $clone->getQuery()->columns = null;
        $clone->getQuery()->joins = null;

        return $clone;
    }

    private function courseIdSubquery($query)
    {
        $ids = $this->aggregateClone($query);
        $ids->select('courses.id');
        $ids->getQuery()->columns = ['courses.id'];
        $ids->getQuery()->distinct = true;

        return $ids;
    }
}
