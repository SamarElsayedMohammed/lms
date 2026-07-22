<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\UserCourseProgress;
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            $request->merge([
                'date_from' => $request->date_from ?? $request->from_date,
                'date_to' => $request->date_to ?? $request->to_date
            ]);
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
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

            $query = UserCourseProgress::with(['user', 'course.category', 'course.user']);

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
        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            // course_type=free maps to is_free=1, course_type=paid maps to is_free=0
            $query->where('is_free', $request->course_type === 'free' ? 1 : 0);
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

        $subscriptionPayments = collect();
        $subscriptionRevenue = 0;
        
        if (!$request->filled('course_id') && !$request->filled('category_id')) {
            $subQuery = \App\Models\SubscriptionPayment::query();
            
            if ($request->filled('date_from')) {
                $subQuery->whereDate('subscription_payments.created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $subQuery->whereDate('subscription_payments.created_at', '<=', $request->date_to);
            }
            if ($request->filled('payment_method')) {
                $subQuery->where('subscription_payments.payment_method', $request->payment_method);
            }
            if ($request->filled('status')) {
                if ($request->status === 'completed') {
                    $subQuery->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED);
                } elseif ($request->status === 'pending') {
                    $subQuery->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_PENDING);
                } elseif ($request->status === 'cancelled' || $request->status === 'failed') {
                    $subQuery->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_FAILED);
                }
            }

            $subscriptionPayments = $subQuery->get();
            
            $completedSubsQuery = clone $subQuery;
            $subscriptionRevenue = $completedSubsQuery->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->select(\Illuminate\Support\Facades\DB::raw('SUM(subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1)) as total_revenue'))
                ->value('total_revenue') ?? 0;
        }

        $allOrdersCount    = $orders->count() + $subscriptionPayments->count();
        $completedOrders   = $orders->where('status', 'completed');
        $completedSubs     = $subscriptionPayments->where('status', SubscriptionPayment::STATUS_COMPLETED);
        $pendingSubs       = $subscriptionPayments->where('status', SubscriptionPayment::STATUS_PENDING);
        $failedSubs        = $subscriptionPayments->where('status', SubscriptionPayment::STATUS_FAILED);

        // Revenue from completed transactions only
        $orderRevenue       = $completedOrders->sum(static fn($o) => $o->amount_egp ?? $o->final_price);
        $totalRevenue       = $orderRevenue + $subscriptionRevenue;
        // Average is calculated over completed transactions only to avoid skewing by failed/pending
        $completedCount     = $completedOrders->count() + $completedSubs->count();
        $avgOrderValue      = $completedCount > 0 ? round($totalRevenue / $completedCount, 2) : 0;

        // دمج طرق الدفع من الأوردرات والاشتراكات معاً
        $orderPaymentMethods = $orders
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->map->count();

        $subPaymentMethods = $subscriptionPayments
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->map->count();

        $allPaymentMethods = $orderPaymentMethods->mergeRecursive($subPaymentMethods)
            ->map(fn($v) => is_array($v) ? array_sum($v) : $v)
            ->sortDesc();

        $recentOrders = $orders->sortByDesc('created_at')->take(10)->values()->map(fn($o) => [
            'id'             => $o->id,
            'status'         => $o->status,
            'final_price'    => $o->final_price,
            'payment_method' => $o->payment_method,
            'created_at'     => $o->created_at,
            'user'           => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
        ]);

        $recentSubscriptions = $subscriptionPayments->sortByDesc('created_at')->take(10)->values()->map(fn($s) => [
            'id'             => $s->id,
            'status'         => $s->status,
            'amount'         => $s->final_amount,
            'payment_method' => $s->payment_method,
            'created_at'     => $s->created_at,
        ]);

        return [
            'total_orders'         => $allOrdersCount,
            'total_revenue'        => $totalRevenue,
            'average_order_value'  => $avgOrderValue,
            'completed_orders'     => $completedOrders->count() + $completedSubs->count(),
            'pending_orders'       => $orders->where('status', 'pending')->count() + $pendingSubs->count(),
            'cancelled_orders'     => $orders->where('status', 'cancelled')->count(),
            'failed_orders'        => $orders->where('status', 'failed')->count() + $failedSubs->count(),
            'payment_methods'      => $allPaymentMethods,
            'recent_orders'        => $recentOrders,
            'recent_subscriptions' => $recentSubscriptions,
            'subscription_revenue' => $subscriptionRevenue,
            'subscription_count'   => $subscriptionPayments->count(),
        ];
    }

    private function getDetailedSalesData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        $paginated = clone $query;
        $paginatedResult = $paginated->orderBy('created_at', 'desc')->paginate($perPage);
        $summary = $this->getSalesSummaryData($query, $request);
        return array_merge($paginatedResult->toArray(), $summary);
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
                SUM(COALESCE(amount_egp, final_price)) as revenue,
                AVG(COALESCE(amount_egp, final_price)) as avg_order_value
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getCommissionSummaryData($query, $request)
    {
        $commissions = $query->get();

        return [
            'total_commissions' => $commissions->count(),
            'total_admin_commission' => $commissions->sum(static fn($c) => $c->admin_commission_amount * ($c->order->exchange_rate_snapshot ?? 1)),
            'paid_commissions' => $commissions->where('status', 'paid')->count(),
            'pending_commissions' => $commissions->where('status', 'pending')->count(),
            'commission_by_course' => $this->getCommissionByCourse($commissions),
            'recent_commissions' => $commissions->sortByDesc('created_at')->take(10)->values(),
        ];
    }

    private function getDetailedCommissionData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        $paginatedQuery = clone $query;
        $paginated = $paginatedQuery->orderBy('created_at', 'desc')->paginate($perPage);
        // Instructor data is required in the admin panel so it's not stripped.

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

        return $query->join('orders', 'commissions.order_id', '=', 'orders.id')->selectRaw("
                DATE_FORMAT(commissions.created_at, '{$format}') as period,
                COUNT(*) as commissions_count,
                SUM(commissions.admin_commission_amount * COALESCE(orders.exchange_rate_snapshot, 1)) as admin_total
            ")->groupBy('period')->orderBy('period')->get();
    }

    private function getCourseSummaryData($query, $request)
    {
        $courses = $query->withCount([
            'orderCourses' => fn($q) => $q->whereHas('order', fn($oq) => $oq->where('status', 'completed')),
            'ratings',
        ])->withAvg('ratings', 'rating')->get();

        return [
            'total_courses'        => $courses->count(),
            'active_courses'       => $courses->where('is_active', true)->count(),
            'free_courses'         => $courses->where('is_free', true)->count(),
            'paid_courses'         => $courses->where('is_free', false)->count(),
            'average_rating'       => round($courses->avg('ratings_avg_rating') ?? 0, 2),
            'total_enrollments'    => $courses->sum('order_courses_count'),
            'courses_by_category'  => $this->getCoursesByCategory($courses),
            'courses_by_level'     => $courses->groupBy('level')->map->count(),
            'top_rated_courses'    => $courses->sortByDesc('ratings_avg_rating')->take(10)->values(),
        ];
    }

    private function getDetailedCourseData($query, $request)
    {
        $perPage        = $request->per_page ?? 15;
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery
            ->withCount([
                'orderCourses' => fn($q) => $q->whereHas('order', fn($oq) => $oq->where('status', 'completed')),
                'ratings',
            ])
            ->withAvg('ratings', 'rating')
            // Use a subquery to compute revenue per course in a single round-trip
            // instead of firing one query per course (N+1).
            ->withSum(
                [
                    'orderCourses as revenue' => fn($q) => $q
                        ->whereHas('order', fn($oq) => $oq->where('status', 'completed'))
                        ->select(\Illuminate\Support\Facades\DB::raw('COALESCE(amount_egp, price)')),
                ],
                \Illuminate\Support\Facades\DB::raw('COALESCE(amount_egp, price)')
            )
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $summary = $this->getCourseSummaryData($query, $request);
        return array_merge($paginatedResult->toArray(), $summary);
    }

    private function getCoursePerformanceData($query, $request)
    {
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
                        ->select(\Illuminate\Support\Facades\DB::raw('COALESCE(amount_egp, price)')),
                ],
                \Illuminate\Support\Facades\DB::raw('COALESCE(amount_egp, price)')
            )
            ->get()
            ->map(function ($course) {
                return [
                    'course'              => $course,
                    'performance_metrics' => [
                        'enrollments'    => $course->order_courses_count,
                        'revenue'        => $course->revenue ?? 0,
                        'rating'         => round($course->ratings_avg_rating ?? 0, 2),
                        'reviews_count'  => $course->ratings_count,
                    ],
                ];
            });
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
        ];
    }

    private function getDetailedInstructorData($query, $request)
    {
        $perPage = $request->per_page ?? 15;
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery
            ->with(['user.courses' => static function ($q): void {
                $q->withCount('orderCourses');
            }])
            ->orderBy('created_at', 'desc')
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
            $courses = $instructor->user->courses;

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
        $paginatedQuery = clone $query;
        $paginatedResult = $paginatedQuery->orderBy('created_at', 'desc')->paginate($perPage);
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

        return $query->selectRaw("
                DATE_FORMAT(created_at, '{$format}') as period,
                COUNT(*) as enrollment_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
            ")->groupBy('period')->orderBy('period')->get();
    }


    // Helper methods for calculations

    private function getTopCoursesSales($orders)
    {
        return $orders
            ->flatMap(fn($o) => $o->orderCourses ?? collect())
            ->groupBy('course_id')
            ->map(static fn($orderCourses) => [
                'course'       => $orderCourses->first()?->course,
                'total_sales'  => $orderCourses->sum(static fn($oc) => $oc->amount_egp ?? $oc->price),
                'total_orders' => $orderCourses->count(),
            ])
            ->sortByDesc('total_sales')
            ->take(10)
            ->values();
    }

    private function getCommissionByCourse($commissions)
    {
        return $commissions
            ->groupBy('course_id')
            ->map(static fn($courseCommissions) => [
                'course' => $courseCommissions->first()->course,
                'total_commission' => $courseCommissions->sum(static fn($c) => $c->admin_commission_amount * ($c->order->exchange_rate_snapshot ?? 1)),
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


    private function calculateGrowthPercentage($old, $new)
    {
        if ($old == 0) {
            return $new > 0 ? 100 : 0;
        }

        return round((($new - $old) / $old) * 100, 2);
    }
}
