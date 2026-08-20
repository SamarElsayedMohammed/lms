<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\DashboardReportRequest;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Commission;
use App\Models\ContactMessage;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\Course\CourseDiscussion;
use App\Models\HelpdeskQuestion;
use App\Models\HelpdeskReply;
use App\Models\Instructor;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\PaymentTransaction;
use App\Models\Rating;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletHistory;
use App\Models\Wishlist;
use App\Services\HelperService;
use App\Services\Reports\ReportMoneySql;
use App\Services\Reports\ReportingPeriodService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    private function orderAmountSql(string $alias = 'orders'): string
    {
        return ReportMoneySql::orderRevenueEgpSql($alias);
    }

    private function subscriptionAmountSql(string $alias = 'subscription_payments'): string
    {
        return ReportMoneySql::subscriptionRevenueEgpSql($alias);
    }

    private function refundAmountSql(string $alias = 'refund_requests'): string
    {
        return ReportMoneySql::refundAmountEgpSql($alias);
    }

    /**
     * Cache for course by category statistics to avoid duplicate queries
     * @var Collection|null
     */
    private null|Collection $courseByCategoryCache = null;

    /**
     * Cache for most popular courses to avoid duplicate queries
     * @var Collection|null
     */
    private null|Collection $mostPopularCoursesCache = null;

    /**
     * Cache for total users count to avoid duplicate queries
     * @var int|null
     */
    private null|int $totalUsersCache = null;

    /**
     * Cache for user growth chart data to avoid duplicate queries
     * @var array|null
     */
    private null|array $userGrowthChartCache = null;

    /**
     * Resolve date boundaries for given period filter
     */
    private function resolvePeriodDates(DashboardReportRequest $request): array
    {
        $rawPreset = (string) ($request->validated('period') ?? $request->validated('date_range') ?? '30_days');
        $period = app(ReportingPeriodService::class)->resolve([
            'preset' => $rawPreset,
            'date_from' => $request->validated('from') ?? $request->validated('date_from'),
            'date_to' => $request->validated('to') ?? $request->validated('date_to'),
        ]);

        return [
            'period' => $period->preset,
            'start' => $period->start,
            'end' => $period->end,
            'prev_start' => $period->previousStart,
            'prev_end' => $period->previousEnd,
            'timezone' => $period->timezone,
        ];
    }

    /**
     * Get comprehensive dashboard data for admin panel
     */
    public function getDashboardData(DashboardReportRequest $request)
    {
        try {
            // Get currency symbol from settings
            $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

            $dates = $this->resolvePeriodDates($request);

            $data = [
                'overview_stats' => $this->getOverviewStats($dates),
                'financial_stats' => $this->getFinancialStats($dates),
                'course_stats' => $this->getCourseStats($dates),
                'user_stats' => $this->getUserStats($request, $dates),
                'engagement_stats' => $this->getEngagementStats($dates),
                'monthly_charts' => $this->getMonthlyCharts($dates),
                'recent_activities' => $this->getRecentActivities(),
                'top_performers' => $this->getTopPerformers(),
                'system_health' => $this->getSystemHealth(),
                'subscription_stats' => $this->getSubscriptionStats($dates),
                'monthly_financial_summary' => $this->getMonthlyFinancialSummary($dates),
                'report_period' => [
                    'preset' => $dates['period'],
                    'from' => $dates['start']->toDateString(),
                    'to' => $dates['end']->toDateString(),
                    'previous_from' => $dates['prev_start']->toDateString(),
                    'previous_to' => $dates['prev_end']->toDateString(),
                    'timezone' => config('app.timezone', 'Africa/Cairo'),
                ],
                'currency_symbol' => $currencySymbol,
            ];

            return response()->json(
                [
                    'status' => true,
                    'message' => 'Dashboard data retrieved successfully',
                    'data' => $data,
                ],
                200,
                [],
                JSON_UNESCAPED_UNICODE,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Dashboard API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(
                [
                    'status' => false,
                    'message' => 'Failed to load dashboard data: ' . $e->getMessage(),
                    'error' => config('app.debug') ? $e->getTraceAsString() : null,
                ],
                500,
                [],
                JSON_UNESCAPED_UNICODE,
            );
        }
    }

    /**
     * GET /api/dashboard-charts
     *
     * Lightweight endpoint that returns only the three chart datasets needed
     * for performance analytics chart:
     *   - revenue    -> الإيرادات
     *   - enrollment -> التسجيل
     *   - courses    -> الدورات
     */
    public function getChartsData(DashboardReportRequest $request)
    {
        try {
            $dates = $this->resolvePeriodDates($request);
            $startDate = $dates['start'];
            $endDate = $dates['end'];

            // ─── Revenue chart (إجمالي الإيرادات) ──────────────────────────────
            $revenueRows = Order::where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(COALESCE(NULLIF(amount_egp, 0), final_price, 0)) as total')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(fn($r) => sprintf('%04d-%02d', $r->year, $r->month));

            $subRevenueRows = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$startDate, $endDate])
                ->selectRaw('YEAR(COALESCE(subscription_payments.paid_at, subscription_payments.created_at)) as year, MONTH(COALESCE(subscription_payments.paid_at, subscription_payments.created_at)) as month, SUM(' . $this->subscriptionAmountSql() . ') as total')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(fn($r) => sprintf('%04d-%02d', $r->year, $r->month));

            // ─── Enrollment chart (تسجيل مستخدمين جدد) ──────────────────────
            $enrollmentRows = User::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(fn($r) => sprintf('%04d-%02d', $r->year, $r->month));

            // ─── Courses chart (تسجيلات الدورات) ─────────────────────────────
            $coursesRows = OrderCourse::join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->selectRaw('YEAR(orders.created_at) as year, MONTH(orders.created_at) as month, COUNT(DISTINCT CONCAT(orders.user_id, ":", order_courses.course_id)) as total')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(fn($r) => sprintf('%04d-%02d', $r->year, $r->month));

            // ─── Build 12-month series ─────────────────────────────────────────
            $revenue    = [];
            $enrollment = [];
            $courses    = [];

            $cursor = $startDate->copy()->startOfMonth();
            $lastMonth = $endDate->copy()->startOfMonth();
            while ($cursor->lte($lastMonth)) {
                $date = $cursor->copy();
                $key   = $date->format('Y-m');
                $label = $date->format('M Y');

                $revVal = (float) (($revenueRows->get($key)->total ?? 0) + ($subRevenueRows->get($key)->total ?? 0));
                $revenue[]    = ['month' => $label, 'value' => $revVal];
                $enrollment[] = ['month' => $label, 'value' => (int) ($enrollmentRows->get($key)->total ?? 0)];
                $courses[]    = ['month' => $label, 'value' => (int) ($coursesRows->get($key)->total ?? 0)];
                $cursor->addMonth();
            }

            return response()->json([
                'status'  => true,
                'message' => 'Chart data retrieved successfully',
                'data'    => [
                    'revenue'    => $revenue,
                    'enrollment' => $enrollment,
                    'courses'    => $courses,
                    'period'     => $dates['period'],
                    'from'       => $startDate->format('Y-m-d'),
                    'to'         => $endDate->format('Y-m-d'),
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Dashboard Charts API Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to load chart data: ' . $e->getMessage(),
            ], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get comprehensive overview statistics
     */
    private function getOverviewStats(array $dates)
    {
        try {
            $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

            $revenueStats = Order::where('status', 'completed')
                ->selectRaw('
                    SUM(' . $this->orderAmountSql() . ') as total_earnings,
                    SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN ' . $this->orderAmountSql() . ' ELSE 0 END) as current_revenue,
                    SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN ' . $this->orderAmountSql() . ' ELSE 0 END) as previous_revenue
                ', [$dates['start'], $dates['end'], $dates['prev_start'], $dates['prev_end']])
                ->first();

            $subStats = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->selectRaw('
                    SUM(' . $this->subscriptionAmountSql() . ') as total_earnings,
                    SUM(CASE WHEN COALESCE(subscription_payments.paid_at, subscription_payments.created_at) BETWEEN ? AND ? THEN ' . $this->subscriptionAmountSql() . ' ELSE 0 END) as current_revenue,
                    SUM(CASE WHEN COALESCE(subscription_payments.paid_at, subscription_payments.created_at) BETWEEN ? AND ? THEN ' . $this->subscriptionAmountSql() . ' ELSE 0 END) as previous_revenue
                ', [$dates['start'], $dates['end'], $dates['prev_start'], $dates['prev_end']])
                ->first();

            $refundStats = \App\Models\RefundRequest::whereIn('status', ['approved', 'processed'])
                ->selectRaw('
                    SUM(' . $this->refundAmountSql() . ') as total_refunds,
                    SUM(CASE WHEN COALESCE(processed_at, updated_at) BETWEEN ? AND ? THEN ' . $this->refundAmountSql() . ' ELSE 0 END) as current_refunds,
                    SUM(CASE WHEN COALESCE(processed_at, updated_at) BETWEEN ? AND ? THEN ' . $this->refundAmountSql() . ' ELSE 0 END) as previous_refunds
                ', [$dates['start'], $dates['end'], $dates['prev_start'], $dates['prev_end']])
                ->first();

            $totalEarnings = max(0, (($revenueStats->total_earnings ?? 0) + ($subStats->total_earnings ?? 0)) - ($refundStats->total_refunds ?? 0));
            $currentRevenue = max(0, (($revenueStats->current_revenue ?? 0) + ($subStats->current_revenue ?? 0)) - ($refundStats->current_refunds ?? 0));
            $previousRevenue = max(0, (($revenueStats->previous_revenue ?? 0) + ($subStats->previous_revenue ?? 0)) - ($refundStats->previous_refunds ?? 0));

            $revenueGrowth = $this->calculatePercentageChange($previousRevenue, $currentRevenue);

            $userStatusStats = User::selectRaw('COUNT(CASE WHEN is_active = 1 THEN 1 END) as active, COUNT(CASE WHEN is_active = 0 THEN 1 END) as suspended')->first();
            $totalUsers = User::where('created_at', '<=', $dates['end'])->count();
            $activeUsers = (int) ($userStatusStats->active ?? 0);
            $suspendedUsers = (int) ($userStatusStats->suspended ?? 0);
            $totalInstructors = Instructor::count();
            $paidEnrollments = DB::table('order_courses')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->selectRaw('orders.user_id, order_courses.course_id');
            $totalEnrollments = DB::query()
                ->fromSub(
                    $paidEnrollments->union(
                        DB::table('user_course_tracks')->whereNull('deleted_at')->select('user_id', 'course_id')
                    ),
                    'enrollments'
                )
                ->count();
            $totalCategories = Category::count();

            // courses.status: ['draft','pending','publish'] | courses.approval_status: ['approved','rejected'] (nullable = not reviewed)
            $coursesStats = Course::without('taxes')->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = "publish" AND is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_approval
                ')->first();
            $totalCourses = $coursesStats->total;
            $activeCourses = $coursesStats->active;
            $pendingApprovals = $coursesStats->pending_approval;

            $previousTotalUsers = User::where('created_at', '<=', $dates['prev_end'])->count();
            $userGrowth = $this->calculatePercentageChange($previousTotalUsers, $totalUsers);
            $totalCoursesAtEnd = Course::without('taxes')->withTrashed()->where('created_at', '<=', $dates['end'])->count();
            $previousTotalCourses = Course::without('taxes')->withTrashed()->where('created_at', '<=', $dates['prev_end'])->count();
            $courseGrowth = $this->calculatePercentageChange($previousTotalCourses, $totalCoursesAtEnd);
            $enrollmentGrowth = $this->calculateGrowthBetweenDates('order_courses', 'created_at', $dates);

            return [
                'total_users' => [
                    'count' => $totalUsers,
                    'growth' => $userGrowth,
                    'icon' => 'fas fa-users',
                    'color' => 'primary',
                    'label' => 'Total Users',
                ],
                'active_users' => [
                    'count' => $activeUsers,
                    'growth' => 0,
                    'icon' => 'fas fa-user-check',
                    'color' => 'success',
                    'label' => 'Active Users',
                ],
                'suspended_users' => [
                    'count' => $suspendedUsers,
                    'growth' => 0,
                    'icon' => 'fas fa-user-slash',
                    'color' => 'danger',
                    'label' => 'Suspended Users',
                ],
                'stopped_users' => [
                    // User model has no 'status' column; is_active=0 is the only suspension indicator
                    'count' => $suspendedUsers,
                    'growth' => 0,
                    'icon' => 'fas fa-user-slash',
                    'color' => 'danger',
                    'label' => 'Stopped Users',
                ],
                'total_courses' => [
                    'count' => $totalCourses,
                    'growth' => $courseGrowth,
                    'icon' => 'fas fa-graduation-cap',
                    'color' => 'success',
                    'label' => 'Total Courses',
                ],
                'total_instructors' => [
                    'count' => $totalInstructors,
                    'growth' => 0,
                    'icon' => 'fas fa-chalkboard-teacher',
                    'color' => 'info',
                    'label' => 'Total Instructors',
                ],
                'total_earnings' => [
                    'amount' => round((float) $totalEarnings, 2),
                    'currency_code' => 'EGP',
                    'currency_symbol' => 'ج.م',
                    'formatted' => number_format($totalEarnings, 2) . ' ج.م',
                    'count' => round((float) $totalEarnings, 2),
                    'growth' => $revenueGrowth,
                    'icon' => 'fas fa-coins',
                    'color' => 'success',
                    'label' => 'Total Earnings',
                ],
                'total_enrollments' => [
                    'count' => $totalEnrollments,
                    'growth' => $enrollmentGrowth,
                    'icon' => 'fas fa-user-graduate',
                    'color' => 'warning',
                    'label' => 'Total Enrollments',
                ],
                'active_courses' => [
                    'count' => $activeCourses,
                    'growth' => 0,
                    'icon' => 'fas fa-play-circle',
                    'color' => 'info',
                    'label' => 'Active Courses',
                ],
                'pending_approvals' => [
                    'count' => $pendingApprovals,
                    'growth' => 0,
                    'icon' => 'fas fa-clock',
                    'color' => 'danger',
                    'label' => 'Pending Approvals',
                ],
                'total_categories' => [
                    'count' => $totalCategories,
                    'growth' => 0,
                    'icon' => 'fas fa-tags',
                    'color' => 'secondary',
                    'label' => 'Total Categories',
                ],
            ];
        } catch (\Exception) {
            return $this->getDefaultOverviewStats();
        }
    }

    /**
     * Get financial statistics
     */
    private function getFinancialStats(array $dates)
    {
        try {
            $thisPeriodRevenueFromOrders = Order::where('status', 'completed')
                ->whereBetween('created_at', [$dates['start'], $dates['end']])
                ->sum(DB::raw($this->orderAmountSql())) ?? 0;

            $thisPeriodRevenueFromSubscriptions = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['start'], $dates['end']])
                ->sum(DB::raw($this->subscriptionAmountSql())) ?? 0;

            $thisPeriodRefunds = \App\Models\RefundRequest::whereIn('status', ['approved', 'processed'])
                ->whereBetween(DB::raw('COALESCE(processed_at, updated_at)'), [$dates['start'], $dates['end']])
                ->sum(DB::raw($this->refundAmountSql())) ?? 0;

            $thisPeriodRevenue = max(0, ($thisPeriodRevenueFromOrders + $thisPeriodRevenueFromSubscriptions) - $thisPeriodRefunds);

            $lastPeriodRevenueFromOrders = Order::where('status', 'completed')
                ->whereBetween('created_at', [$dates['prev_start'], $dates['prev_end']])
                ->sum(DB::raw($this->orderAmountSql())) ?? 0;

            $lastPeriodRevenueFromSubscriptions = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['prev_start'], $dates['prev_end']])
                ->sum(DB::raw($this->subscriptionAmountSql())) ?? 0;

            $lastPeriodRefunds = \App\Models\RefundRequest::whereIn('status', ['approved', 'processed'])
                ->whereBetween(DB::raw('COALESCE(processed_at, updated_at)'), [$dates['prev_start'], $dates['prev_end']])
                ->sum(DB::raw($this->refundAmountSql())) ?? 0;

            $lastPeriodRevenue = max(0, ($lastPeriodRevenueFromOrders + $lastPeriodRevenueFromSubscriptions) - $lastPeriodRefunds);

            $orderStats = Order::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN status = "processing" THEN 1 END) as processing,
                COALESCE(SUM(CASE WHEN status = "pending" THEN amount_egp END), 0) as total_pending_payments,
                AVG(CASE WHEN status = "completed" THEN ' . $this->orderAmountSql() . ' END) as avg_completed_order
            ')->first();

            $completedOrdersCount = $orderStats->completed;
            $totalPendingPayments = $orderStats->total_pending_payments;
            $totalRefunds = \App\Models\RefundRequest::whereIn('status', ['approved', 'processed'])
                ->sum(DB::raw($this->refundAmountSql())) ?? 0;

            $averageOrderValue = 0;
            if ($completedOrdersCount > 0) {
                $averageOrderValue = $orderStats->avg_completed_order ?? 0;
            } elseif (PaymentTransaction::where('payment_status', 'success')->count() > 0) {
                $averageOrderValue = PaymentTransaction::where('payment_status', 'success')->avg('amount') ?? 0;
            }

            return [
                'monthly_revenue' => [
                    'current' => round((float) $thisPeriodRevenue, 2),
                    'previous' => round((float) $lastPeriodRevenue, 2),
                    'growth' => $this->calculatePercentageChange($lastPeriodRevenue, $thisPeriodRevenue),
                ],
                'total_pending' => round((float) $totalPendingPayments, 2),
                'total_refunds' => round((float) $totalRefunds, 2),
                'average_order_value' => round((float) $averageOrderValue, 2),
                'payment_methods' => $this->getPaymentMethodStats($dates),
                'revenue_by_category' => $this->getRevenueByCategoryStats($dates),
                'revenue_by_package' => $this->getRevenueByPackageStats($dates),
                'revenue_by_country' => $this->getRevenueByCountryStats($dates),
            ];
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Dashboard Financial Stats Error: ' . $e->getMessage());
            return $this->getDefaultFinancialStats();
        }
    }

    /**
     * Get course statistics
     */
    private function getCourseStats(array $dates)
    {
        try {
            // courses.status enum: ['draft','pending','publish'] | approval_status enum: ['approved','rejected'], nullable
            $courseStats = Course::without('taxes')
                ->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = "publish" AND is_active = 1 THEN 1 END) as published,
                    COUNT(CASE WHEN status = "draft" THEN 1 END) as draft,
                    COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_approval,
                    COUNT(CASE WHEN approval_status = "approved" THEN 1 END) as approved,
                    COUNT(CASE WHEN approval_status = "rejected" THEN 1 END) as rejected
                ')
                ->first();

            $publishedCourses = (int) ($courseStats->published ?? 0);
            $draftCourses     = (int) ($courseStats->draft ?? 0);
            $pendingApproval  = (int) ($courseStats->pending_approval ?? 0);
            $approvedCourses  = (int) ($courseStats->approved ?? 0);
            $rejectedCourses  = (int) ($courseStats->rejected ?? 0);
            // Soft-deleted courses are treated as archived
            $archivedCourses  = Course::without('taxes')->withTrashed()->whereNotNull('deleted_at')->count();

            $totalLectures = CourseChapterLecture::count();
            $totalQuizzes = CourseChapterQuiz::count();
            $totalAssignments = CourseChapterAssignment::count();
            $totalChapters = CourseChapter::count();

            $averageCourseRating = Rating::where('rateable_type', \App\Models\Course\Course::class)->avg('rating');
            $totalCourseRatings = Rating::where('rateable_type', \App\Models\Course\Course::class)->count();
            $averageInstructorRating = Rating::where('rateable_type', \App\Models\Instructor::class)->avg('rating');
            $totalInstructorRatings = Rating::where('rateable_type', \App\Models\Instructor::class)->count();

            $ratingBreakdown = Rating::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN rating = 5 THEN 1 END) as 5_stars,
                COUNT(CASE WHEN rating = 4 THEN 1 END) as 4_stars,
                COUNT(CASE WHEN rating = 3 THEN 1 END) as 3_stars,
                COUNT(CASE WHEN rating = 2 THEN 1 END) as 2_stars,
                COUNT(CASE WHEN rating = 1 THEN 1 END) as 1_star
            ')->first()->toArray();

            $recentRatings = Rating::with(['user', 'rateable'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            return [
                'course_status' => [
                    'published' => $publishedCourses,
                    'draft' => $draftCourses,
                    'pending_approval' => $pendingApproval,
                    'approved' => $approvedCourses,
                    'rejected' => $rejectedCourses,
                    'archived' => $archivedCourses,
                ],
                'content_stats' => [
                    'total_lectures' => $totalLectures,
                    'total_quizzes' => $totalQuizzes,
                    'total_assignments' => $totalAssignments,
                    'total_chapters' => $totalChapters,
                ],
                'rating_stats' => [
                    'course_ratings' => [
                        'average' => round($averageCourseRating ?? 0, 2),
                        'total' => $totalCourseRatings,
                    ],
                    'instructor_ratings' => [
                        'average' => round($averageInstructorRating ?? 0, 2),
                        'total' => $totalInstructorRatings,
                    ],
                    'overall_average' => round(Rating::avg('rating') ?? 0, 2),
                    'total_ratings' => $ratingBreakdown['total'] ?? 0,
                    'rating_breakdown' => $ratingBreakdown,
                    'recent_ratings' => $recentRatings,
                ],
                'course_by_category' => $this->getCourseByCategoryStats(),
                'most_popular_courses' => $this->getMostPopularCourses(),
            ];
        } catch (\Exception) {
            return $this->getDefaultCourseStats();
        }
    }

    /**
     * Get user statistics
     */
    private function getUserStats(Request $request, array $dates)
    {
        try {
            $userStats = User::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as suspended,
                COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as new_current_period,
                COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as new_prev_period
            ', [$dates['start'], $dates['end'], $dates['prev_start'], $dates['prev_end']])->first();

            $totalUsers = (int) ($userStats->total ?? 0);
            $activeUsers = (int) ($userStats->active ?? 0);
            $suspendedUsers = (int) ($userStats->suspended ?? 0);
            $inactiveUsers = $suspendedUsers;
            $newUsersThisPeriod = (int) ($userStats->new_current_period ?? 0);
            $newUsersPrevPeriod = (int) ($userStats->new_prev_period ?? 0);
            $newUsersGrowth = $this->calculatePercentageChange($newUsersPrevPeriod, $newUsersThisPeriod);

            $usersWithOrders = DB::query()->fromSub(
                DB::table('orders')->where('status', 'completed')->select('user_id')
                    ->union(DB::table('subscription_payments')->where('status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)->select('user_id')),
                'purchasing_users'
            )->distinct()->count('user_id');
            $usersLimit = min(max((int) $request->input('users_limit', 10), 1), 50);

            $users = User::with([
                    'activeSubscription:id,user_id,plan_id,status,starts_at,ends_at',
                    'activeSubscription.plan:id,name',
                ])
                ->latest('created_at')
                ->limit($usersLimit)
                ->get(['id', 'name', 'email', 'mobile', 'is_active', 'type', 'profile', 'wallet_balance', 'created_at'])
                ->map(function (User $user): array {
                    $subscription = $user->activeSubscription;

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'mobile' => $user->mobile,
                        'profile' => $user->profile,
                        'type' => $user->type,
                        'is_active' => (bool) $user->is_active,
                        'status' => $user->is_active ? 'active' : 'suspended',
                        'status_label' => $user->is_active ? 'نشط' : 'موقوف',
                        'wallet_balance' => (float) $user->wallet_balance,
                        'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                        'active_subscription' => $subscription ? [
                            'id' => $subscription->id,
                            'plan_id' => $subscription->plan_id,
                            'plan_name' => $subscription->plan?->name,
                            'status' => $subscription->status,
                            'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                            'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                        ] : null,
                    ];
                })
                ->values();

            $instructorStats = Instructor::selectRaw('
                COUNT(CASE WHEN status = ? THEN 1 END) as pending,
                COUNT(CASE WHEN status = ? THEN 1 END) as approved,
                COUNT(CASE WHEN status = ? THEN 1 END) as rejected
            ', ['pending', 'approved', 'rejected'])->first();

            return [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'suspended_users' => $suspendedUsers,
                'stopped_users' => $suspendedUsers,
                'inactive_users' => $inactiveUsers,
                'new_users_this_month' => $newUsersThisPeriod,
                'users_with_purchases' => $usersWithOrders,
                'users_limit' => $usersLimit,
                'users' => $users,
                'recent_users' => $users,
                'user_activity' => [
                    'total' => $totalUsers,
                    'total_users' => $totalUsers,
                    'active' => $activeUsers,
                    'active_users' => $activeUsers,
                    'inactive' => $inactiveUsers,
                    'inactive_users' => $inactiveUsers,
                    'suspended' => $suspendedUsers,
                    'suspended_users' => $suspendedUsers,
                    'stopped' => $suspendedUsers,
                    'stopped_users' => $suspendedUsers,
                    'new_this_month' => $newUsersThisPeriod,
                    'new_users_this_month' => $newUsersThisPeriod,
                    'growth' => $newUsersGrowth,
                    'with_purchases' => $usersWithOrders,
                    'users_with_purchases' => $usersWithOrders,
                ],
                'instructor_stats' => [
                    'pending_requests' => (int) ($instructorStats->pending ?? 0),
                    'approved' => (int) ($instructorStats->approved ?? 0),
                    'rejected' => (int) ($instructorStats->rejected ?? 0),
                ],
                'user_growth_chart' => $this->getUserGrowthChartData(),
                'user_registration_sources' => $this->getUserRegistrationSources(),
            ];
        } catch (\Exception $e) {
            Log::error('Dashboard User Stats Error: ' . $e->getMessage());
            return $this->getDefaultUserStats();
        }
    }

    /**
     * Get engagement statistics
     */
    private function getEngagementStats(array $dates)
    {
        try {
            $discussionStats = CourseDiscussion::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as active
            ', [Carbon::now()->subDays(7)])->first();

            $quizStats = UserQuizAttempt::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as recent
            ', [Carbon::now()->subDays(7)])->first();

            $totalAssignmentSubmissions = UserAssignmentSubmission::count();
            $totalWishlists = Wishlist::count();
            $totalCarts = Cart::count();
            $totalHelpdeskQuestions = HelpdeskQuestion::count();
            $totalHelpdeskReplies = HelpdeskReply::count();

            // ContactMessage statuses: new | read | waiting_admin | replied | closed
            // pending = new + waiting_admin (not yet actioned by admin)
            $contactStats = ContactMessage::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status IN ("new", "waiting_admin") THEN 1 END) as pending,
                COUNT(CASE WHEN status = "replied" THEN 1 END) as replied
            ')->first();

            return [
                'discussion_stats' => [
                    'total_discussions' => (int) ($discussionStats->total ?? 0),
                    'active_this_week' => (int) ($discussionStats->active ?? 0),
                ],
                'assessment_stats' => [
                    'total_quiz_attempts' => (int) ($quizStats->total ?? 0),
                    'recent_attempts' => (int) ($quizStats->recent ?? 0),
                    'total_assignments' => $totalAssignmentSubmissions,
                ],
                'shopping_stats' => [
                    'total_wishlists' => $totalWishlists,
                    'active_carts' => $totalCarts,
                ],
                'support_stats' => [
                    'helpdesk_questions' => $totalHelpdeskQuestions,
                    'helpdesk_replies' => $totalHelpdeskReplies,
                    'contact_messages' => (int) ($contactStats->total ?? 0),
                    'pending_contact_messages' => (int) ($contactStats->pending ?? 0),
                    'replied_contact_messages' => (int) ($contactStats->replied ?? 0),
                ],
                'engagement_trends' => $this->getEngagementTrends(),
            ];
        } catch (\Exception) {
            return $this->getDefaultEngagementStats();
        }
    }

    /**
     * Get monthly chart data for different metrics
     */
    private function getMonthlyCharts(array $dates)
    {
        try {
            return [
                'revenue_chart' => $this->getRevenueChartData($dates),
                'user_registration_chart' => $this->getUserRegistrationChartData($dates),
                'course_enrollment_chart' => $this->getCourseEnrollmentChartData($dates),
                'course_creation_chart' => $this->getCourseCreationChartData($dates),
            ];
        } catch (\Exception) {
            return $this->getDefaultChartData();
        }
    }

    /**
     * Get recent activities across the platform - returns exactly 6 items sorted by real time
     */
    private function getRecentActivities()
    {
        try {
            $activities = [];

            // Recent user registrations
            $recentUsers = User::latest()->limit(4)->get();
            foreach ($recentUsers as $user) {
                $activities[] = [
                    'type' => 'user_registration',
                    'icon' => 'fas fa-user-plus',
                    'color' => 'success',
                    'title' => 'تسجيل طالب جديد',
                    'subtitle' => $user->name . ' انضم للمنصة حديثاً',
                    'description' => $user->name . ' انضم للمنصة حديثاً',
                    'time' => $this->getTimeAgo($user->created_at),
                    'raw_time' => $user->created_at,
                    'link' => '/admin/students',
                ];
            }

            // Recent course creations
            $recentCourses = Course::without('taxes')->latest()->limit(4)->get();
            foreach ($recentCourses as $course) {
                $activities[] = [
                    'type' => 'course_creation',
                    'icon' => 'fas fa-graduation-cap',
                    'color' => 'primary',
                    'title' => 'إضافة دورة جديدة',
                    'subtitle' => '"' . $course->title . '" تم إنشاؤها',
                    'description' => '"' . $course->title . '" تم إنشاؤها',
                    'time' => $this->getTimeAgo($course->created_at),
                    'raw_time' => $course->created_at,
                    'link' => '/admin/courses',
                ];
            }

            // Recent orders
            $recentOrders = Order::with('user')->latest()->limit(4)->get();
            foreach ($recentOrders as $order) {
                if (!$order->user) continue;
                $activities[] = [
                    'type' => 'new_order',
                    'icon' => 'fas fa-shopping-cart',
                    'color' => 'warning',
                    'title' => 'طلب شراء جديد',
                    'subtitle' => $order->user->name . ' قام بشراء طلب #' . $order->order_number,
                    'description' => $order->user->name . ' قام بشراء طلب #' . $order->order_number,
                    'time' => $this->getTimeAgo($order->created_at),
                    'raw_time' => $order->created_at,
                    'link' => '/admin/orders',
                ];
            }

            $activities = collect($activities)
                ->sortByDesc('raw_time')
                ->take(6)
                ->map(function ($item) {
                    unset($item['raw_time']);
                    return $item;
                })
                ->values();

            return $activities;
        } catch (\Exception) {
            return $this->getDefaultActivities();
        }
    }

    /**
     * Get top performers data
     */
    private function getTopPerformers()
    {
        try {
            return [
                'top_instructors' => $this->getTopInstructors(),
                'top_courses' => $this->getTopCourses(),
                'top_categories' => $this->getTopCategories(),
                'top_earning_courses' => $this->getTopEarningCourses(),
            ];
        } catch (\Exception) {
            return $this->getDefaultTopPerformers();
        }
    }

    /**
     * Get system health metrics
     */
    private function getSystemHealth()
    {
        try {
            $totalNotifications = Notification::count();
            $unreadNotifications = Notification::whereNull('read_at')->count();

            $dbHealthy = false;
            try {
                $dbHealthy = (bool) DB::connection()->getPdo();
            } catch (\Exception) {
                $dbHealthy = false;
            }

            $storageHealthy = false;
            try {
                $storageHealthy = \Illuminate\Support\Facades\Storage::disk('public')->exists('');
            } catch (\Exception) {
                $storageHealthy = false;
            }

            $failedJobsCount = 0;
            try {
                $failedJobsCount = DB::table('failed_jobs')->count();
            } catch (\Exception) {
                $failedJobsCount = 0;
            }

            return [
                'notifications' => [
                    'total' => $totalNotifications,
                    'unread' => $unreadNotifications,
                ],
                'database' => [
                    'status' => $dbHealthy ? 'healthy' : 'degraded',
                    'label' => $dbHealthy ? 'متصلة' : 'معطلة',
                ],
                'api' => [
                    'status' => 'healthy',
                    'label' => 'سليمة',
                ],
                'storage' => [
                    'status' => $storageHealthy ? 'healthy' : 'degraded',
                    'label' => $storageHealthy ? 'سليمة' : 'معطلة',
                ],
                'queue' => [
                    'status' => $failedJobsCount === 0 ? 'healthy' : 'degraded',
                    'failed_jobs' => $failedJobsCount,
                ],
                'system_performance' => [
                    'error_logs' => $failedJobsCount,
                ],
                'database_stats' => $this->getDatabaseStats(),
                'storage_stats' => $this->getStorageStats(),
            ];
        } catch (\Exception) {
            return $this->getDefaultSystemHealth();
        }
    }

    private function calculateGrowthBetweenDates($table, $dateColumn, array $dates)
    {
        try {
            $stats = DB::table($table)
                ->selectRaw('
                    COUNT(CASE WHEN ' . $dateColumn . ' BETWEEN ? AND ? THEN 1 END) as current_period,
                    COUNT(CASE WHEN ' . $dateColumn . ' BETWEEN ? AND ? THEN 1 END) as previous_period
                ', [$dates['start'], $dates['end'], $dates['prev_start'], $dates['prev_end']])
                ->first();

            return $this->calculatePercentageChange($stats?->previous_period, $stats?->current_period);
        } catch (\Exception) {
            return 0;
        }
    }

    private function calculatePercentageChange($oldValue, $newValue)
    {
        $old = (float) $oldValue;
        $new = (float) $newValue;
        if ($old <= 0) {
            return $new > 0 ? 100 : 0;
        }

        return round((($new - $old) / $old) * 100, 1);
    }

    private function getPaymentMethodStats(array $dates)
    {
        try {
            $orderStats = Order::where('status', 'completed')
                ->whereBetween('created_at', [$dates['start'], $dates['end']])
                ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(' . $this->orderAmountSql() . ') as total'))
                ->groupBy('payment_method')
                ->get();

            $subscriptionStats = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['start'], $dates['end']])
                ->selectRaw('COALESCE(subscription_payments.payment_method, "unknown") as payment_method, COUNT(*) as count, SUM(' . $this->subscriptionAmountSql() . ') as total')
                ->groupBy('subscription_payments.payment_method')
                ->get();

            return $orderStats->concat($subscriptionStats)
                ->groupBy(static fn($item) => $item->payment_method ?? 'unknown')
                ->map(static fn(Collection $items, string $method) => [
                    'method' => $method,
                    'count' => (int) $items->sum('count'),
                    'total' => round((float) $items->sum('total'), 2),
                ])->values();
        } catch (\Exception $e) {
            Log::error('Payment Method Stats Error: ' . $e->getMessage());
            return [];
        }
    }

    private function getRevenueByCategoryStats(array $dates)
    {
        try {
            return DB::table('order_courses')
                ->join('courses', 'order_courses.course_id', '=', 'courses.id')
                ->join('categories', 'courses.category_id', '=', 'categories.id')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', [$dates['start'], $dates['end']])
                ->select('categories.name as category', DB::raw('SUM(order_courses.price) as total_revenue'))
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('total_revenue', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception) {
            return [];
        }
    }

    private function getRevenueByPackageStats(array $dates): array
    {
        try {
            $plans = DB::table('subscription_plans')
                ->whereNull('deleted_at')
                ->get();

            if ($plans->isEmpty()) {
                return [];
            }

            $palette = ["#eb2027", "#f59e0b", "#0ea5e9", "#10b981", "#a855f7"];
            $totalSubRev = (float) DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['start'], $dates['end']])
                ->sum(DB::raw($this->subscriptionAmountSql()));

            $result = [];
            foreach ($plans as $index => $plan) {
                $subCount = DB::table('subscriptions')
                    ->where('plan_id', $plan->id)
                    ->whereNull('deleted_at')
                    ->count();

                $activeCount = DB::table('subscriptions')
                    ->where('plan_id', $plan->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->count();

                $rev = (float) DB::table('subscription_payments')
                    ->join('subscriptions', 'subscription_payments.subscription_id', '=', 'subscriptions.id')
                    ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                    ->where('subscriptions.plan_id', $plan->id)
                    ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                    ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['start'], $dates['end']])
                    ->sum(DB::raw($this->subscriptionAmountSql()));

                $pct = $totalSubRev > 0 ? round(($rev / $totalSubRev) * 100, 1) : 0;

                $result[] = [
                    'package_id' => $plan->id,
                    'package_name' => $plan->name,
                    'package_slug' => $plan->slug,
                    'subscriptions_count' => $subCount,
                    'active_subscriptions_count' => $activeCount,
                    'gross_revenue' => round($rev, 2),
                    'refunded_revenue' => 0.0,
                    'net_revenue' => round($rev, 2),
                    'percentage' => $pct,
                    'currency_code' => 'EGP',
                    'name' => $plan->name,
                    'revenue' => round($rev, 2),
                    'color' => $palette[$index % count($palette)],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Revenue by package error: ' . $e->getMessage());
            return [];
        }
    }

    private function getRevenueByCountryStats(array $dates): array
    {
        try {
            $countryNames = [
                'EG' => ['ar' => 'مصر', 'en' => 'Egypt', 'flag' => '🇪🇬'],
                'SA' => ['ar' => 'المملكة العربية السعودية', 'en' => 'Saudi Arabia', 'flag' => '🇸🇦'],
                'AE' => ['ar' => 'الإمارات العربية المتحدة', 'en' => 'United Arab Emirates', 'flag' => '🇦🇪'],
                'KW' => ['ar' => 'الكويت', 'en' => 'Kuwait', 'flag' => '🇰🇼'],
                'QA' => ['ar' => 'قطر', 'en' => 'Qatar', 'flag' => '🇶🇦'],
                'BH' => ['ar' => 'البحرين', 'en' => 'Bahrain', 'flag' => '🇧🇭'],
                'OM' => ['ar' => 'عُمان', 'en' => 'Oman', 'flag' => '🇴🇲'],
                'JO' => ['ar' => 'الأردن', 'en' => 'Jordan', 'flag' => '🇯🇴'],
            ];

            $countryRevenues = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$dates['start'], $dates['end']])
                ->select(
                    DB::raw("COALESCE(resolved_country, 'UNKNOWN') as code"),
                    DB::raw('COUNT(*) as tx_count'),
                    DB::raw('COUNT(DISTINCT user_id) as cust_count'),
                    DB::raw('SUM(' . $this->subscriptionAmountSql() . ') as total_rev')
                )
                ->groupBy(DB::raw("COALESCE(resolved_country, 'UNKNOWN')"))
                ->orderBy('total_rev', 'desc')
                ->get();

            $orderCountryRevenues = DB::table('orders')
                ->leftJoin('user_billing_details', 'user_billing_details.user_id', '=', 'orders.user_id')
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', [$dates['start'], $dates['end']])
                ->select(
                    DB::raw("COALESCE(user_billing_details.country_code, 'UNKNOWN') as code"),
                    DB::raw('COUNT(orders.id) as tx_count'),
                    DB::raw('COUNT(DISTINCT orders.user_id) as cust_count'),
                    DB::raw('SUM(' . $this->orderAmountSql() . ') as total_rev')
                )
                ->groupBy(DB::raw("COALESCE(user_billing_details.country_code, 'UNKNOWN')"))
                ->get();

            $countryRevenues = $countryRevenues->concat($orderCountryRevenues)
                ->groupBy('code')
                ->map(function (Collection $items, string $code) {
                    return (object) [
                        'code' => $code,
                        'tx_count' => $items->sum('tx_count'),
                        'cust_count' => $items->sum('cust_count'),
                        'total_rev' => $items->sum('total_rev'),
                    ];
                })->values()->sortByDesc('total_rev')->values();

            $total = (float) $countryRevenues->sum('total_rev');

            return $countryRevenues->map(function ($item) use ($countryNames, $total) {
                $code = strtoupper((string) $item->code);
                $info = $countryNames[$code] ?? ['ar' => $code, 'en' => $code, 'flag' => '🌐'];
                $rev = (float) $item->total_rev;
                $pct = $total > 0 ? round(($rev / $total) * 100, 1) : 0;

                return [
                    'country_code' => $code,
                    'country_name_ar' => $info['ar'],
                    'country_name_en' => $info['en'],
                    'country' => $info['ar'],
                    'flag' => $info['flag'],
                    'transactions_count' => (int) $item->tx_count,
                    'customers_count' => (int) $item->cust_count,
                    'gross_revenue' => round($rev, 2),
                    'net_revenue' => round($rev, 2),
                    'revenue' => round($rev, 2),
                    'percentage' => $pct,
                    'currency_code' => 'EGP',
                ];
            })->values()->toArray();
        } catch (\Exception $e) {
            Log::error('Revenue by country error: ' . $e->getMessage());
            return [];
        }
    }

    private function getCourseByCategoryStats(): Collection
    {
        if ($this->courseByCategoryCache !== null) {
            return $this->courseByCategoryCache;
        }

        try {
            $result = Category::withCount(['courses' => static function ($q): void {
                $q->where('is_active', true)
                  ->where('status', 'publish')
                  ->where('approval_status', 'approved');
            }])
                ->orderBy('courses_count', 'desc')
                ->limit(10)
                ->get()
                ->map(static fn($category) => [
                    'category' => $category->name,
                    'count' => $category->courses_count,
                ]);

            $this->courseByCategoryCache = $result;
            return $result;
        } catch (\Exception) {
            return collect([]);
        }
    }

    private function getMostPopularCourses(): Collection
    {
        if ($this->mostPopularCoursesCache !== null) {
            return $this->mostPopularCoursesCache;
        }

        try {
            $result = Course::without('taxes')
                ->select('courses.*', DB::raw('COUNT(order_courses.id) as enrollments_count'))
                ->leftJoin('order_courses', 'courses.id', '=', 'order_courses.course_id')
                ->leftJoin('orders', static function ($join): void {
                    $join->on('order_courses.order_id', '=', 'orders.id')->where('orders.status', 'completed');
                })
                ->where('courses.is_active', 1)
                ->where('courses.status', 'publish')
                ->where('courses.approval_status', 'approved')
                ->with('user:id,name')
                ->groupBy('courses.id')
                ->having('enrollments_count', '>', 0)
                ->orderBy('enrollments_count', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($course) => [
                    'title' => $course->title,
                    'courseName' => $course->title,
                    'enrollments' => (int) $course->enrollments_count,
                    'instructor' => $course->user->name ?? 'مدرس',
                    'status' => $course->is_active ? 'نشطة' : 'مسودة',
                ]);

            $this->mostPopularCoursesCache = $result;
            return $result;
        } catch (\Exception) {
            return collect([]);
        }
    }

    private function getUserGrowthChartData(): array
    {
        if ($this->userGrowthChartCache !== null) {
            return $this->userGrowthChartCache;
        }

        try {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();

            $users = User::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    COUNT(*) as count
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $data = [];
            for ($i = 11; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i)->startOfMonth();
                $key = $date->format('Y-m');

                $data[] = [
                    'month' => $date->format('M Y'),
                    'count' => (int) ($users->get($key)->count ?? 0),
                    'value' => (int) ($users->get($key)->count ?? 0),
                ];
            }

            $this->userGrowthChartCache = $data;
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getUserRegistrationSources()
    {
        $totalUsers = $this->getTotalUsersCount();
        return [
            ['source' => 'غير معروف (Unknown)', 'count' => $totalUsers],
        ];
    }

    private function getEngagementTrends()
    {
        try {
            $trends = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $discussions = CourseDiscussion::whereDate('created_at', $date)->count();
                $quizAttempts = UserQuizAttempt::whereDate('created_at', $date)->count();

                $trends[] = [
                    'date' => $date->format('M d'),
                    'discussions' => $discussions,
                    'quiz_attempts' => $quizAttempts,
                ];
            }
            return $trends;
        } catch (\Exception) {
            return [];
        }
    }

    private function getRevenueChartData(array $dates)
    {
        try {
            $startDate = $dates['start'];
            $endDate = $dates['end'];

            $revenues = Order::where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    SUM(COALESCE(NULLIF(amount_egp, 0), final_price, 0)) as revenue
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $subRevenues = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$startDate, $endDate])
                ->selectRaw('
                    YEAR(COALESCE(subscription_payments.paid_at, subscription_payments.created_at)) as year,
                    MONTH(COALESCE(subscription_payments.paid_at, subscription_payments.created_at)) as month,
                    SUM(' . $this->subscriptionAmountSql() . ') as revenue
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $data = [];
            $cursor = $startDate->copy()->startOfMonth();
            $lastMonth = $endDate->copy()->startOfMonth();
            while ($cursor->lte($lastMonth)) {
                $date = $cursor->copy();
                $key = $date->format('Y-m');
                $totalRevVal = (float) (($revenues->get($key)->revenue ?? 0) + ($subRevenues->get($key)->revenue ?? 0));

                $data[] = [
                    'month' => $date->format('M Y'),
                    'revenue' => round($totalRevVal, 2),
                    'revenue_egp' => round($totalRevVal, 2),
                    'value' => round($totalRevVal, 2),
                ];
                $cursor->addMonth();
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getUserRegistrationChartData(array $dates)
    {
        return $this->getUserGrowthChartDataForPeriod($dates);
    }

    private function getUserGrowthChartDataForPeriod(array $dates): array
    {
        $users = User::whereBetween('created_at', [$dates['start'], $dates['end']])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as count')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

        $data = [];
        $cursor = $dates['start']->copy()->startOfMonth();
        $lastMonth = $dates['end']->copy()->startOfMonth();
        while ($cursor->lte($lastMonth)) {
            $key = $cursor->format('Y-m');
            $count = (int) ($users->get($key)->count ?? 0);
            $data[] = ['month' => $cursor->format('M Y'), 'count' => $count, 'value' => $count];
            $cursor->addMonth();
        }

        return $data;
    }

    private function getCourseEnrollmentChartData(array $dates)
    {
        try {
            $startDate = $dates['start'];
            $endDate = $dates['end'];

            $enrollments = OrderCourse::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    COUNT(*) as enrollments
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $trackEnrollments = \App\Models\Course\UserCourseTrack::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    COUNT(*) as enrollments
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $data = [];
            $cursor = $startDate->copy()->startOfMonth();
            $lastMonth = $endDate->copy()->startOfMonth();
            while ($cursor->lte($lastMonth)) {
                $date = $cursor->copy();
                $key = $date->format('Y-m');

                $data[] = [
                    'month' => $date->format('M Y'),
                    'enrollments' => (int) (($enrollments->get($key)->enrollments ?? 0) + ($trackEnrollments->get($key)->enrollments ?? 0)),
                    'value' => (int) (($enrollments->get($key)->enrollments ?? 0) + ($trackEnrollments->get($key)->enrollments ?? 0)),
                ];
                $cursor->addMonth();
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getCourseCreationChartData(array $dates)
    {
        try {
            $startDate = $dates['start'];
            $endDate = $dates['end'];
            $courses = Course::without('taxes')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    COUNT(*) as count
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $data = [];
            $cursor = $startDate->copy()->startOfMonth();
            $lastMonth = $endDate->copy()->startOfMonth();
            while ($cursor->lte($lastMonth)) {
                $date = $cursor->copy();
                $key = $date->format('Y-m');

                $data[] = [
                    'month' => $date->format('M Y'),
                    'courses' => (int) ($courses->get($key)->count ?? 0),
                    'value' => (int) ($courses->get($key)->count ?? 0),
                ];
                $cursor->addMonth();
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getTopInstructors()
    {
        try {
            return User::whereHas('instructor_details', static function ($query): void {
                $query->where('status', 'approved');
            })
                ->withCount(['courses as total_courses'])
                ->with(['instructor_details'])
                ->get()
                ->map(function ($instructor) {
                    $instructorCourseIds = Course::where('user_id', $instructor->id)->pluck('id');
                    $studentsCount = OrderCourse::whereIn('course_id', $instructorCourseIds)
                        ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                        ->where('orders.status', 'completed')
                        ->distinct('orders.user_id')
                        ->count('orders.user_id');

                    return [
                        'name' => $instructor->name,
                        'email' => $instructor->email,
                        'total_courses' => (int) $instructor->total_courses,
                        'courses' => (int) $instructor->total_courses,
                        'students' => $studentsCount,
                        'status' => $instructor->instructor_details->status ?? 'approved',
                    ];
                })
                ->sortByDesc('students')
                ->take(5)
                ->values()
                ->toArray();
        } catch (\Exception) {
            return [];
        }
    }

    private function getTopCourses()
    {
        return $this->getMostPopularCourses();
    }

    private function getTopCategories()
    {
        return $this->getCourseByCategoryStats();
    }

    private function getTopEarningCourses()
    {
        try {
            return Course::without('taxes')
                ->select('courses.*', DB::raw('SUM(order_courses.price) as total_earnings'))
                ->join('order_courses', 'courses.id', '=', 'order_courses.course_id')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->with('user:id,name')
                ->groupBy('courses.id')
                ->orderBy('total_earnings', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($course) => [
                    'title' => $course->title,
                    'courseName' => $course->title,
                    'total_earnings' => round((float) $course->total_earnings, 2),
                    'instructor' => $course->user->name ?? 'مدرس',
                ]);
        } catch (\Exception) {
            return [];
        }
    }

    private function getDatabaseStats()
    {
        try {
            return [
                'total_users' => $this->getTotalUsersCount(),
                'total_courses' => Course::count(),
                'total_orders' => Order::count(),
                'database_size' => 'N/A',
            ];
        } catch (\Exception) {
            return [];
        }
    }

    private function getStorageStats()
    {
        try {
            return [
                'total_files' => 'N/A',
                'storage_used' => 'N/A',
                'storage_available' => 'N/A',
            ];
        } catch (\Exception) {
            return [];
        }
    }

    private function getTimeAgo($datetime)
    {
        try {
            $now = Carbon::now();
            $diff = $now->diffInMinutes($datetime);

            if ($diff < 1)
                return 'الآن';
            if ($diff < 60)
                return $diff . ' دقيقة';
            if ($diff < 1440)
                return round($diff / 60) . ' ساعة';

            return round($diff / 1440) . ' يوم';
        } catch (\Exception) {
            return 'الآن';
        }
    }

    private function getTotalUsersCount(): int
    {
        if ($this->totalUsersCache === null) {
            $this->totalUsersCache = User::count();
        }
        return $this->totalUsersCache;
    }

    private function getDefaultOverviewStats()
    {
        $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

        return [
            'total_users' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-users',
                'color' => 'primary',
                'label' => 'Total Users',
            ],
            'active_users' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-user-check',
                'color' => 'success',
                'label' => 'Active Users',
            ],
            'suspended_users' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-user-slash',
                'color' => 'danger',
                'label' => 'Suspended Users',
            ],
            'stopped_users' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-user-slash',
                'color' => 'danger',
                'label' => 'Stopped Users',
            ],
            'total_courses' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-graduation-cap',
                'color' => 'success',
                'label' => 'Total Courses',
            ],
            'total_instructors' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-chalkboard-teacher',
                'color' => 'info',
                'label' => 'Total Instructors',
            ],
            'total_earnings' => [
                'count' => $currencySymbol . '0.00',
                'growth' => 0,
                'icon' => 'fas fa-rupee-sign',
                'color' => 'success',
                'label' => 'Total Earnings',
            ],
            'total_enrollments' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-user-graduate',
                'color' => 'warning',
                'label' => 'Total Enrollments',
            ],
            'active_courses' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-play-circle',
                'color' => 'info',
                'label' => 'Active Courses',
            ],
            'pending_approvals' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-clock',
                'color' => 'danger',
                'label' => 'Pending Approvals',
            ],
            'total_categories' => [
                'count' => 0,
                'growth' => 0,
                'icon' => 'fas fa-tags',
                'color' => 'secondary',
                'label' => 'Total Categories',
            ],
        ];
    }

    private function getDefaultFinancialStats()
    {
        return [
            'monthly_revenue' => ['current' => 0, 'previous' => 0, 'growth' => 0],
            'total_pending' => 0,
            'total_refunds' => 0,
            'average_order_value' => 0,
            'payment_methods' => [],
            'revenue_by_category' => [],
            'revenue_by_package' => [],
            'revenue_by_country' => [],
        ];
    }

    private function getDefaultCourseStats()
    {
        return [
            'course_status' => [
                'published' => 0,
                'draft' => 0,
                'pending_approval' => 0,
                'approved' => 0,
                'rejected' => 0,
                'archived' => 0,
            ],
            'content_stats' => [
                'total_lectures' => 0,
                'total_quizzes' => 0,
                'total_assignments' => 0,
                'total_chapters' => 0,
            ],
            'rating_stats' => [
                'course_ratings' => ['average' => 0, 'total' => 0],
                'instructor_ratings' => ['average' => 0, 'total' => 0],
                'overall_average' => 0,
                'total_ratings' => 0,
                'rating_breakdown' => ['5_stars' => 0, '4_stars' => 0, '3_stars' => 0, '2_stars' => 0, '1_star' => 0],
                'recent_ratings' => [],
            ],
            'course_by_category' => [],
            'most_popular_courses' => [],
        ];
    }

    private function getDefaultUserStats()
    {
        return [
            'total_users' => 0,
            'active_users' => 0,
            'suspended_users' => 0,
            'stopped_users' => 0,
            'inactive_users' => 0,
            'new_users_this_month' => 0,
            'users_with_purchases' => 0,
            'users_limit' => 0,
            'users' => [],
            'recent_users' => [],
            'user_activity' => [
                'total' => 0,
                'total_users' => 0,
                'active' => 0,
                'active_users' => 0,
                'inactive' => 0,
                'inactive_users' => 0,
                'suspended' => 0,
                'suspended_users' => 0,
                'stopped' => 0,
                'stopped_users' => 0,
                'new_this_month' => 0,
                'new_users_this_month' => 0,
                'growth' => 0,
                'with_purchases' => 0,
                'users_with_purchases' => 0,
            ],
            'instructor_stats' => ['pending_requests' => 0, 'approved' => 0, 'rejected' => 0],
            'user_growth_chart' => [],
            'user_registration_sources' => [],
        ];
    }

    private function getDefaultEngagementStats()
    {
        return [
            'discussion_stats' => ['total_discussions' => 0, 'active_this_week' => 0],
            'assessment_stats' => ['total_quiz_attempts' => 0, 'recent_attempts' => 0, 'total_assignments' => 0],
            'shopping_stats' => ['total_wishlists' => 0, 'active_carts' => 0],
            'support_stats' => ['helpdesk_questions' => 0, 'helpdesk_replies' => 0, 'contact_messages' => 0, 'pending_contact_messages' => 0, 'replied_contact_messages' => 0],
            'engagement_trends' => [],
        ];
    }

    private function getDefaultChartData()
    {
        return [
            'revenue_chart' => [],
            'user_registration_chart' => [],
            'course_enrollment_chart' => [],
            'course_creation_chart' => [],
        ];
    }

    private function getDefaultActivities()
    {
        return [
            [
                'type' => 'system',
                'icon' => 'fas fa-info-circle',
                'color' => 'info',
                'title' => 'حالة النظام',
                'subtitle' => 'النظام يعمل بشكل طبيعي',
                'description' => 'النظام يعمل بشكل طبيعي',
                'time' => 'الآن',
                'link' => '#',
            ],
        ];
    }

    private function getDefaultTopPerformers()
    {
        return [
            'top_instructors' => [],
            'top_courses' => [],
            'top_categories' => [],
            'top_earning_courses' => [],
        ];
    }

    private function getDefaultSystemHealth()
    {
        return [
            'notifications' => ['total' => 0, 'unread' => 0],
            'system_performance' => ['error_logs' => 0, 'load_metrics' => []],
            'database_stats' => [],
            'storage_stats' => [],
        ];
    }

    /**
     * Get subscription statistics grouped by billing_cycle from the plans table
     */
    private function getSubscriptionStats(array $dates)
    {
        try {
            $stats = DB::table('subscriptions')
                ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
                ->where('subscriptions.status', 'active')
                ->where(function ($q) {
                    $q->whereNull('subscriptions.ends_at')
                      ->orWhere('subscriptions.ends_at', '>=', now());
                })
                ->whereNull('subscriptions.deleted_at')
                ->select('subscription_plans.billing_cycle', DB::raw('COUNT(*) as count'))
                ->groupBy('subscription_plans.billing_cycle')
                ->get()
                ->keyBy('billing_cycle');

            $expiredCount = (int) DB::table('subscriptions')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('status', 'expired')
                      ->orWhere(function ($q2) {
                          $q2->where('status', 'active')
                             ->whereNotNull('ends_at')
                             ->where('ends_at', '<', now());
                      });
                })
                ->count();

            $activeCount = (int) DB::table('subscriptions')
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                      ->orWhere('ends_at', '>=', now());
                })
                ->count();

            $cancelledCount = (int) DB::table('subscriptions')->whereNull('deleted_at')->where('status', 'cancelled')->count();
            $pendingCount = (int) DB::table('subscriptions')->whereNull('deleted_at')->where('status', 'pending')->count();
            $pendingApprovalCount = (int) DB::table('subscriptions')->whereNull('deleted_at')->where('status', 'pending_approval')->count();
            $totalSubscriptions = (int) DB::table('subscriptions')->whereNull('deleted_at')->count();
            $knownCount = $activeCount + $expiredCount + $cancelledCount + $pendingCount + $pendingApprovalCount;
            $otherCount = max(0, $totalSubscriptions - $knownCount);

            return [
                'monthly'    => (int) ($stats->get('monthly')->count ?? 0),
                'quarterly'  => (int) ($stats->get('quarterly')->count ?? 0),
                'semi_annual' => (int) ($stats->get('semi_annual')->count ?? 0),
                'yearly'     => (int) ($stats->get('yearly')->count ?? 0),
                'lifetime'   => (int) ($stats->get('lifetime')->count ?? 0),
                'custom'     => (int) ($stats->get('custom')->count ?? 0),
                'expired'    => $expiredCount,
                'cancelled'  => $cancelledCount,
                'canceled'   => $cancelledCount,
                'pending'    => $pendingCount,
                'pending_approval' => $pendingApprovalCount,
                'suspended'  => 0,
                'other'      => $otherCount,
                'total_active' => $activeCount,
                'total_subscriptions' => $totalSubscriptions,
            ];
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Dashboard subscription stats error: ' . $e->getMessage());
            return [
                'monthly'    => 0,
                'quarterly'  => 0,
                'semi_annual' => 0,
                'yearly'     => 0,
                'lifetime'   => 0,
                'custom'     => 0,
                'expired'    => 0,
                'canceled'   => 0,
                'pending'    => 0,
                'pending_approval' => 0,
                'suspended'  => 0,
                'other'      => 0,
                'total_active' => 0,
                'total_subscriptions' => 0,
            ];
        }
    }

    /**
     * Get monthly financial summary: last 6 months with sales, commission, net_profit
     */
    private function getMonthlyFinancialSummary(array $dates): array
    {
        try {
            $data = [];
            $cursor = $dates['start']->copy()->startOfMonth();
            $lastMonth = $dates['end']->copy()->startOfMonth();

            while ($cursor->lte($lastMonth)) {
                $monthStart = $cursor->copy()->max($dates['start']);
                $monthEnd = $cursor->copy()->endOfMonth()->min($dates['end']);
                $monthLabel = $monthStart->format('Y-m');
                $monthName  = $monthStart->translatedFormat('F Y');

                $sales = (float) Order::where('status', 'completed')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw($this->orderAmountSql()));

                $subSales = (float) DB::table('subscription_payments')
                    ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                    ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                    ->whereBetween(DB::raw('COALESCE(subscription_payments.paid_at, subscription_payments.created_at)'), [$monthStart, $monthEnd])
                    ->sum(DB::raw($this->subscriptionAmountSql()));

                $sales += $subSales;

                $commission = (float) Commission::join('orders', 'commissions.order_id', '=', 'orders.id')
                    ->where('orders.status', 'completed')
                    ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('commissions.admin_commission_amount * COALESCE(orders.exchange_rate_snapshot, 1)'));

                $instructorPayout = (float) Commission::join('orders', 'commissions.order_id', '=', 'orders.id')
                    ->where('orders.status', 'completed')
                    ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('commissions.instructor_commission_amount * COALESCE(orders.exchange_rate_snapshot, 1)'));

                $refunds = (float) \App\Models\RefundRequest::whereIn('status', ['approved', 'processed'])
                    ->whereBetween(DB::raw('COALESCE(processed_at, updated_at)'), [$monthStart, $monthEnd])
                    ->sum(DB::raw($this->refundAmountSql()));

                $netProfit = $sales - $refunds - $instructorPayout;

                $data[] = [
                    'month'            => $monthLabel,
                    'month_name'       => $monthName,
                    'sales'            => round($sales, 2),
                    'refunds'          => round($refunds, 2),
                    'commission'       => round($commission, 2),
                    'instructor_payout' => round($instructorPayout, 2),
                    'net_profit'       => round($netProfit, 2),
                ];
                $cursor->addMonth();
            }

            return $data;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Dashboard monthly financial summary error: ' . $e->getMessage());
            return [];
        }
    }
}
