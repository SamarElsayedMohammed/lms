<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
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
     * Get comprehensive dashboard data for admin panel
     */
    public function getDashboardData(Request $request)
    {
        try {
            // Get currency symbol from settings
            $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

            $data = [
                'overview_stats' => $this->getOverviewStats(),
                'financial_stats' => $this->getFinancialStats(),
                'course_stats' => $this->getCourseStats(),
                'user_stats' => $this->getUserStats($request),
                'engagement_stats' => $this->getEngagementStats(),
                'monthly_charts' => $this->getMonthlyCharts(),
                'recent_activities' => $this->getRecentActivities(),
                'top_performers' => $this->getTopPerformers(),
                'system_health' => $this->getSystemHealth(),
                'subscription_stats' => $this->getSubscriptionStats(),
                'monthly_financial_summary' => $this->getMonthlyFinancialSummary(),
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
     * for the performance analytics chart (تحليل الأداء):
     *   - revenue    → الإيرادات  (last 12 months revenue)
     *   - enrollment → التسجيل    (last 12 months user registrations)
     *   - courses    → الدورات    (last 12 months course enrollments)
     *
     * Response shape:
     * {
     *   "status": true,
     *   "data": {
     *     "revenue":    [ { "month": "Jun 2025", "value": 1200.0 }, ... ],
     *     "enrollment": [ { "month": "Jun 2025", "value": 15 }, ... ],
     *     "courses":    [ { "month": "Jun 2025", "value": 3 }, ... ]
     *   }
     * }
     */
    public function getChartsData(Request $request)
    {
        try {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();
            $endDate   = Carbon::now()->endOfMonth();

            // ─── Revenue chart (إجمالي الإيرادات) ──────────────────────────────
            $revenueRows = Order::where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(COALESCE(amount_egp, final_price)) as total')
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
            $coursesRows = OrderCourse::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(fn($r) => sprintf('%04d-%02d', $r->year, $r->month));

            // ─── Build 12-month series ─────────────────────────────────────────
            $revenue    = [];
            $enrollment = [];
            $courses    = [];

            for ($i = 11; $i >= 0; $i--) {
                $date  = Carbon::now()->subMonths($i)->startOfMonth();
                $key   = $date->format('Y-m');
                $label = $date->format('M Y'); // e.g. "Jun 2025"

                $revenue[]    = ['month' => $label, 'value' => (float) ($revenueRows->get($key)->total    ?? 0)];
                $enrollment[] = ['month' => $label, 'value' => (int)   ($enrollmentRows->get($key)->total ?? 0)];
                $courses[]    = ['month' => $label, 'value' => (int)   ($coursesRows->get($key)->total    ?? 0)];
            }

            return response()->json([
                'status'  => true,
                'message' => 'Chart data retrieved successfully',
                'data'    => [
                    // Tab 1: الإيرادات — revenue from completed orders
                    'revenue'    => $revenue,
                    // Tab 2: التسجيل — new user registrations
                    'enrollment' => $enrollment,
                    // Tab 3: الدورات — course enrollments (order_courses)
                    'courses'    => $courses,

                    // Meta
                    'period'     => 'last_12_months',
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
    private function getOverviewStats()
    {
        try {
            // Get currency symbol from settings
            $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

            // Combine total earnings with revenue growth calculation
            $now = Carbon::now();
            $thirtyDaysAgo = $now->copy()->subDays(30);
            $sixtyDaysAgo = $now->copy()->subDays(60);

            $revenueStats = Order::where('status', 'completed')
                ->selectRaw('
                    SUM(COALESCE(amount_egp, final_price)) as total_earnings,
                    SUM(CASE WHEN created_at >= ? THEN COALESCE(amount_egp, final_price) ELSE 0 END) as current_revenue,
                    SUM(CASE WHEN created_at BETWEEN ? AND ? THEN COALESCE(amount_egp, final_price) ELSE 0 END) as previous_revenue
                ', [$thirtyDaysAgo, $sixtyDaysAgo, $thirtyDaysAgo])
                ->first();

            $subStats = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->selectRaw('
                    SUM(subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1)) as total_earnings,
                    SUM(CASE WHEN subscription_payments.created_at >= ? THEN subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1) ELSE 0 END) as current_revenue,
                    SUM(CASE WHEN subscription_payments.created_at BETWEEN ? AND ? THEN subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1) ELSE 0 END) as previous_revenue
                ', [$thirtyDaysAgo, $sixtyDaysAgo, $thirtyDaysAgo])
                ->first();

            $totalEarnings = ($revenueStats->total_earnings ?? 0) + ($subStats->total_earnings ?? 0);
            
            $previousRevenue = ($revenueStats->previous_revenue ?? 0) + ($subStats->previous_revenue ?? 0);
            $currentRevenue = ($revenueStats->current_revenue ?? 0) + ($subStats->current_revenue ?? 0);
            
            $revenueGrowth = $this->calculatePercentageChange(
                $previousRevenue,
                $currentRevenue,
            );

            $userStatusStats = User::selectRaw('COUNT(CASE WHEN is_active = 1 THEN 1 END) as active, COUNT(CASE WHEN is_active = 0 THEN 1 END) as suspended')->first();
            $totalUsers = $this->getTotalUsersCount();
            $activeUsers = (int) ($userStatusStats->active ?? 0);
            $suspendedUsers = (int) ($userStatusStats->suspended ?? 0);
            $totalInstructors = Instructor::count();
            $totalEnrollments = OrderCourse::count() + \App\Models\Course\UserCourseTrack::count();
            $totalCategories = Category::count();

            $coursesStats = Course::without('taxes')->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN approval_status = ? THEN 1 END) as pending_approval
                ', ['pending'])->first();
            $totalCourses = $coursesStats->total;
            $activeCourses = $coursesStats->active;
            $pendingApprovals = $coursesStats->pending_approval;

            // Calculate growth percentages (last 30 days vs previous 30 days)
            $userGrowth = $this->calculateGrowthPercentage('users', 'created_at');
            $courseGrowth = $this->calculateGrowthPercentage('courses', 'created_at');
            $enrollmentGrowth = $this->calculateGrowthPercentage('order_courses', 'created_at');

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
                    'count' => $currencySymbol . number_format($totalEarnings, 2),
                    'growth' => $revenueGrowth,
                    'icon' => 'fas fa-rupee-sign',
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
    private function getFinancialStats()
    {
        try {
            $currentMonth = Carbon::now()->startOfMonth();
            $lastMonth = Carbon::now()->subMonth()->startOfMonth();

            // Get this month revenue - check multiple sources
            // 1. Completed orders
            $thisMonthRevenueFromOrders =
                Order::where('status', 'completed')->where('created_at', '>=', $currentMonth)->sum('amount_egp') ?? 0;

            // Add Subscription Payments for this month
            $thisMonthRevenueFromSubscriptions = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->where('subscription_payments.created_at', '>=', $currentMonth)
                ->sum('subscription_payments.amount_egp') ?? 0;

            // 2. Payment transactions with successful status
            $thisMonthRevenueFromTransactions =
                PaymentTransaction::where('payment_status', 'success')->where('created_at', '>=', $currentMonth)->sum(
                    'amount',
                ) ?? 0;

            // 3. Transaction table (from payment gateways)
            $thisMonthRevenueFromGatewayTransactions = DB::table('transactions')
                ->where('status', 'completed')
                ->where('created_at', '>=', $currentMonth)
                ->sum('amount') ?? 0;

            // Use the highest value or sum them (depending on your business logic)
            // For now, prioritize orders, then transactions
            $thisMonthRevenue = $thisMonthRevenueFromOrders > 0
                ? $thisMonthRevenueFromOrders
                : max($thisMonthRevenueFromTransactions, $thisMonthRevenueFromGatewayTransactions);
                
            $thisMonthRevenue += $thisMonthRevenueFromSubscriptions;

            $thisMonthRefunds = \App\Models\RefundRequest::where('status', 'approved')
                ->where('processed_at', '>=', $currentMonth)
                ->sum('refund_amount') ?? 0;
            $thisMonthRevenue = max(0, $thisMonthRevenue - $thisMonthRefunds);

            // Get last month revenue - same logic
            $lastMonthRevenueFromOrders =
                Order::where('status', 'completed')->whereBetween('created_at', [
                    $lastMonth,
                    $lastMonth->copy()->endOfMonth(),
                ])->sum('amount_egp') ?? 0;

            $lastMonthRevenueFromSubscriptions = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween('subscription_payments.created_at', [$lastMonth, $lastMonth->copy()->endOfMonth()])
                ->sum('subscription_payments.amount_egp') ?? 0;

            $lastMonthRevenueFromTransactions =
                PaymentTransaction::where('payment_status', 'success')->whereBetween('created_at', [
                    $lastMonth,
                    $lastMonth->copy()->endOfMonth(),
                ])->sum('amount') ?? 0;

            $lastMonthRevenueFromGatewayTransactions = DB::table('transactions')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$lastMonth, $lastMonth->copy()->endOfMonth()])
                ->sum('amount') ?? 0;

            $lastMonthRevenue = $lastMonthRevenueFromOrders > 0
                ? $lastMonthRevenueFromOrders
                : max($lastMonthRevenueFromTransactions, $lastMonthRevenueFromGatewayTransactions);
                
            $lastMonthRevenue += $lastMonthRevenueFromSubscriptions;

            $lastMonthRefunds = \App\Models\RefundRequest::where('status', 'approved')
                ->whereBetween('processed_at', [$lastMonth, $lastMonth->copy()->endOfMonth()])
                ->sum('refund_amount') ?? 0;
            $lastMonthRevenue = max(0, $lastMonthRevenue - $lastMonthRefunds);

            // Get all order statistics in a single query
            $orderStats = Order::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN status = "processing" THEN 1 END) as processing,
                COALESCE(SUM(CASE WHEN status = "pending" THEN amount_egp END), 0) as total_pending_payments,
                AVG(CASE WHEN status = "completed" THEN amount_egp END) as avg_completed_order
            ')->first();

            $totalOrders = $orderStats->total;
            $completedOrdersCount = $orderStats->completed;
            $pendingOrdersCount = $orderStats->pending;
            $processingOrdersCount = $orderStats->processing;
            $totalPendingPayments = $orderStats->total_pending_payments;

            $totalRefunds = WalletHistory::where('transaction_type', 'refund')->sum('amount') ?? 0;

            // Calculate average order value with fallback to other sources
            $averageOrderValue = 0;
            if ($completedOrdersCount > 0) {
                $averageOrderValue = $orderStats->avg_completed_order ?? 0;
            } elseif (PaymentTransaction::where('payment_status', 'success')->count() > 0) {
                $averageOrderValue = PaymentTransaction::where('payment_status', 'success')->avg('amount') ?? 0;
            } elseif (DB::table('transactions')->where('status', 'completed')->count() > 0) {
                $averageOrderValue = DB::table('transactions')->where('status', 'completed')->avg('amount') ?? 0;
            }

            return [
                'monthly_revenue' => [
                    'current' => (float) $thisMonthRevenue,
                    'previous' => (float) $lastMonthRevenue,
                    'growth' => $this->calculatePercentageChange($lastMonthRevenue, $thisMonthRevenue),
                ],
                'total_pending' => (float) $totalPendingPayments,
                'total_refunds' => (float) $totalRefunds,
                'average_order_value' => round((float) $averageOrderValue, 2),
                'payment_methods' => $this->getPaymentMethodStats(),
                'revenue_by_category' => $this->getRevenueByCategoryStats(),
                // Debug info (can be removed later)
                '_debug' => [
                    'total_orders' => $totalOrders,
                    'completed_orders' => $completedOrdersCount,
                    'pending_orders' => $pendingOrdersCount,
                    'processing_orders' => $processingOrdersCount,
                    'this_month_revenue_sources' => [
                        'from_orders' => $thisMonthRevenueFromOrders,
                        'from_payment_transactions' => $thisMonthRevenueFromTransactions,
                        'from_gateway_transactions' => $thisMonthRevenueFromGatewayTransactions,
                        'final' => $thisMonthRevenue,
                    ],
                    'last_month_revenue_sources' => [
                        'from_orders' => $lastMonthRevenueFromOrders,
                        'from_payment_transactions' => $lastMonthRevenueFromTransactions,
                        'from_gateway_transactions' => $lastMonthRevenueFromGatewayTransactions,
                        'final' => $lastMonthRevenue,
                    ],
                ],
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
    private function getCourseStats()
    {
        try {
            $courseStats = Course::without('taxes')
                ->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN approval_status = ? THEN 1 END) as pending_approval,
                    COUNT(CASE WHEN approval_status = ? THEN 1 END) as approved,
                    COUNT(CASE WHEN approval_status = ? THEN 1 END) as rejected
                ', ['pending', 'approved', 'rejected'])
                ->first();

            $publishedCourses = $courseStats->active;
            $draftCourses = $courseStats->total - $courseStats->active;
            $pendingApproval = $courseStats->pending_approval;
            $approvedCourses = $courseStats->approved;
            $rejectedCourses = $courseStats->rejected;

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

            // Recent ratings
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
    private function getUserStats(Request $request)
    {
        try {
            $userStats = User::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as suspended,
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as new_this_month
            ', [Carbon::now()->startOfMonth()])->first();

            $totalUsers = (int) ($userStats->total ?? 0);
            $activeUsers = (int) ($userStats->active ?? 0);
            $suspendedUsers = (int) ($userStats->suspended ?? 0);
            $inactiveUsers = $suspendedUsers;
            $newUsersThisMonth = (int) ($userStats->new_this_month ?? 0);
            $usersWithOrders = User::has('orders')->count();
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
            $instructorRequests = $instructorStats->pending;
            $approvedInstructors = $instructorStats->approved;
            $rejectedInstructors = $instructorStats->rejected;

            return [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'suspended_users' => $suspendedUsers,
                'stopped_users' => $suspendedUsers,
                'inactive_users' => $inactiveUsers,
                'new_users_this_month' => $newUsersThisMonth,
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
                    'new_this_month' => $newUsersThisMonth,
                    'new_users_this_month' => $newUsersThisMonth,
                    'with_purchases' => $usersWithOrders,
                    'users_with_purchases' => $usersWithOrders,
                ],
                'instructor_stats' => [
                    'pending_requests' => $instructorRequests,
                    'approved' => $approvedInstructors,
                    'rejected' => $rejectedInstructors,
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
    private function getEngagementStats()
    {
        try {
            $discussionStats = CourseDiscussion::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as active
            ', [Carbon::now()->subDays(7)])->first();
            $totalDiscussions = (int) ($discussionStats->total ?? 0);
            $activeDiscussions = (int) ($discussionStats->active ?? 0);

            $quizStats = UserQuizAttempt::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN created_at >= ? THEN 1 END) as recent
            ', [Carbon::now()->subDays(7)])->first();
            $totalQuizAttempts = (int) ($quizStats->total ?? 0);
            $recentQuizAttempts = (int) ($quizStats->recent ?? 0);

            $totalAssignmentSubmissions = UserAssignmentSubmission::count();
            $totalWishlists = Wishlist::count();
            $totalCarts = Cart::count();
            $totalHelpdeskQuestions = HelpdeskQuestion::count();
            $totalHelpdeskReplies = HelpdeskReply::count();

            // Contact messages stats
            $contactStats = ContactMessage::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = "new" THEN 1 END) as pending,
                COUNT(CASE WHEN status = "replied" THEN 1 END) as replied
            ')->first();
            $totalContactMessages = (int) ($contactStats->total ?? 0);
            $pendingContactMessages = (int) ($contactStats->pending ?? 0);
            $repliedContactMessages = (int) ($contactStats->replied ?? 0);

            return [
                'discussion_stats' => [
                    'total_discussions' => $totalDiscussions,
                    'active_this_week' => $activeDiscussions,
                ],
                'assessment_stats' => [
                    'total_quiz_attempts' => $totalQuizAttempts,
                    'recent_attempts' => $recentQuizAttempts,
                    'total_assignments' => $totalAssignmentSubmissions,
                ],
                'shopping_stats' => [
                    'total_wishlists' => $totalWishlists,
                    'active_carts' => $totalCarts,
                ],
                'support_stats' => [
                    'helpdesk_questions' => $totalHelpdeskQuestions,
                    'helpdesk_replies' => $totalHelpdeskReplies,
                    // Contact & support messages
                    'contact_messages' => $totalContactMessages,
                    'pending_contact_messages' => $pendingContactMessages,
                    'replied_contact_messages' => $repliedContactMessages,
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
    private function getMonthlyCharts()
    {
        try {
            return [
                'revenue_chart' => $this->getRevenueChartData(),
                'user_registration_chart' => $this->getUserRegistrationChartData(),
                'course_enrollment_chart' => $this->getCourseEnrollmentChartData(),
                'course_creation_chart' => $this->getCourseCreationChartData(),
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

            // Recent user registrations (fetch 4 as candidates)
            $recentUsers = User::latest()->limit(4)->get();
            foreach ($recentUsers as $user) {
                $activities[] = [
                    'type' => 'user_registration',
                    'icon' => 'fas fa-user-plus',
                    'color' => 'success',
                    'title' => __('New User Registration'),
                    'description' => $user->name . ' ' . __('joined the platform'),
                    'time' => $this->getTimeAgo($user->created_at),
                    'raw_time' => $user->created_at,
                    'link' => '/users/' . $user->id,
                ];
            }

            // Recent course creations (fetch 4 as candidates)
            $recentCourses = Course::without('taxes')->latest()->limit(4)->get();
            foreach ($recentCourses as $course) {
                $activities[] = [
                    'type' => 'course_creation',
                    'icon' => 'fas fa-graduation-cap',
                    'color' => 'primary',
                    'title' => __('New Course Created'),
                    'description' => '"' . $course->title . '" ' . __('was created'),
                    'time' => $this->getTimeAgo($course->created_at),
                    'raw_time' => $course->created_at,
                    'link' => '/courses/' . $course->id,
                ];
            }

            // Recent orders (fetch 4 as candidates)
            $recentOrders = Order::with('user')->latest()->limit(4)->get();
            foreach ($recentOrders as $order) {
                if (!$order->user) continue;
                $activities[] = [
                    'type' => 'new_order',
                    'icon' => 'fas fa-shopping-cart',
                    'color' => 'warning',
                    'title' => __('New Order Placed'),
                    'description' => $order->user->name . ' ' . __('placed order #') . $order->order_number,
                    'time' => $this->getTimeAgo($order->created_at),
                    'raw_time' => $order->created_at,
                    'link' => '/orders/' . $order->id,
                ];
            }

            // Sort by actual timestamp descending, take exactly 6, then remove raw_time
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
            $errorLogs = 0; // You can implement error log counting
            $systemLoad = $this->getSystemLoadMetrics();

            return [
                'notifications' => [
                    'total' => $totalNotifications,
                    'unread' => $unreadNotifications,
                ],
                'system_performance' => [
                    'error_logs' => $errorLogs,
                    'load_metrics' => $systemLoad,
                ],
                'database_stats' => $this->getDatabaseStats(),
                'storage_stats' => $this->getStorageStats(),
            ];
        } catch (\Exception) {
            return $this->getDefaultSystemHealth();
        }
    }

    // Helper calculation methods
    private function calculateGrowthPercentage($table, $dateColumn)
    {
        try {
            $now = Carbon::now();
            $thirtyDaysAgo = $now->copy()->subDays(30);
            $sixtyDaysAgo = $now->copy()->subDays(60);

            $stats = DB::table($table)
                ->selectRaw('
                    COUNT(CASE WHEN '
                . $dateColumn
                . ' >= ? THEN 1 END) as current_period,
                    COUNT(CASE WHEN '
                . $dateColumn
                . ' BETWEEN ? AND ? THEN 1 END) as previous_period
                ', [$thirtyDaysAgo, $sixtyDaysAgo, $thirtyDaysAgo])
                ->first();

            return $this->calculatePercentageChange($stats?->previous_period, $stats?->current_period);
        } catch (\Exception) {
            return 0;
        }
    }

    private function calculatePercentageChange($oldValue, $newValue)
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100 : 0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }

    // Statistics helper methods
    private function getPaymentMethodStats()
    {
        try {
            // First try completed orders
            $orderStats = Order::where('status', 'completed')
                ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(COALESCE(amount_egp, final_price)) as total'))
                ->groupBy('payment_method')
                ->get();

            // If no completed orders, try payment transactions
            if ($orderStats->isEmpty()) {
                $transactionStats = PaymentTransaction::where('payment_status', 'success')
                    ->select(
                        'payment_gateway as payment_method',
                        DB::raw('COUNT(*) as count'),
                        DB::raw('SUM(amount) as total'),
                    )
                    ->groupBy('payment_gateway')
                    ->get();

                if ($transactionStats->isEmpty()) {
                    // Try gateway transactions table
                    $gatewayStats = DB::table('transactions')
                        ->where('status', 'completed')
                        ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                        ->groupBy('payment_method')
                        ->get();

                    return $gatewayStats->map(static fn($item) => [
                        'method' => $item->payment_method ?? 'unknown',
                        'count' => $item->count,
                        'total' => $item->total,
                    ]);
                }

                return $transactionStats->map(static fn($item) => [
                    'method' => $item->payment_method ?? 'unknown',
                    'count' => $item->count,
                    'total' => $item->total,
                ]);
            }

            return $orderStats->map(static fn($item) => [
                'method' => $item->payment_method ?? 'unknown',
                'count' => $item->count,
                'total' => $item->total,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Payment Method Stats Error: ' . $e->getMessage());
            return [];
        }
    }

    private function getRevenueByCategoryStats()
    {
        try {
            return DB::table('order_courses')
                ->join('courses', 'order_courses.course_id', '=', 'courses.id')
                ->join('categories', 'courses.category_id', '=', 'categories.id')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->select('categories.name as category', DB::raw('SUM(order_courses.price) as total_revenue'))
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('total_revenue', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Get course statistics grouped by category with caching
     * @return Collection
     */
    private function getCourseByCategoryStats(): Collection
    {
        // Return cached result if available
        if ($this->courseByCategoryCache !== null) {
            return $this->courseByCategoryCache;
        }

        try {
            $result = Category::withCount(['courses' => static function ($q): void {
                // Only count courses that are active, published, approved, and have at least one active chapter with curriculum

                $q
                    ->where('is_active', true)
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

            // Cache the result
            $this->courseByCategoryCache = $result;
            return $result;
        } catch (\Exception) {
            return collect([]);
        }
    }

    /**
     * Get most popular courses with caching
     * @return Collection
     */
    private function getMostPopularCourses(): Collection
    {
        // Return cached result if available
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
                    'enrollments' => $course->enrollments_count,
                    'instructor' => $course->user->name ?? 'Unknown',
                ]);

            // Cache the result
            $this->mostPopularCoursesCache = $result;
            return $result;
        } catch (\Exception) {
            return collect([]);
        }
    }

    private function getUserGrowthChartData(): array
    {
        // Return cached result if available
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
                    'count' => $users->get($key)->count ?? 0,
                ];
            }

            // Cache the result
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
            ['source' => 'Direct', 'count' => round($totalUsers * 0.6)],
            ['source' => 'Social Media', 'count' => round($totalUsers * 0.25)],
            ['source' => 'Referral', 'count' => round($totalUsers * 0.15)],
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

    // Chart data methods
    private function getRevenueChartData()
    {
        try {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();

            $revenues = Order::where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    SUM(COALESCE(amount_egp, final_price)) as revenue
                ')
                ->groupBy('year', 'month')
                ->get()
                ->keyBy(static fn($item) => sprintf('%04d-%02d', $item->year, $item->month));

            $subRevenues = DB::table('subscription_payments')
                ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->whereBetween('subscription_payments.created_at', [$startDate, $endDate])
                ->selectRaw('
                    YEAR(subscription_payments.created_at) as year,
                    MONTH(subscription_payments.created_at) as month,
                    SUM(subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1)) as revenue
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
                    'revenue' => ($revenues->get($key)->revenue ?? 0) + ($subRevenues->get($key)->revenue ?? 0),
                ];
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getUserRegistrationChartData()
    {
        return $this->getUserGrowthChartData();
    }

    private function getCourseEnrollmentChartData()
    {
        try {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();

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
            for ($i = 11; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i)->startOfMonth();
                $key = $date->format('Y-m');

                $data[] = [
                    'month' => $date->format('M Y'),
                    'enrollments' => ($enrollments->get($key)->enrollments ?? 0) + ($trackEnrollments->get($key)->enrollments ?? 0),
                ];
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    private function getCourseCreationChartData()
    {
        try {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
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
            for ($i = 11; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i)->startOfMonth();
                $key = $date->format('Y-m');

                $data[] = [
                    'month' => $date->format('M Y'),
                    'courses' => $courses->get($key)->count ?? 0,
                ];
            }
            return $data;
        } catch (\Exception) {
            return [];
        }
    }

    // Top performers methods
    private function getTopInstructors()
    {
        try {
            return User::whereHas('instructor_details', static function ($query): void {
                $query->where('status', 'approved');
            })
                ->withCount(['courses as total_courses'])
                ->with(['instructor_details'])
                ->orderBy('total_courses', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($instructor) => [
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'total_courses' => $instructor->total_courses,
                    'status' => $instructor->instructor_details->status ?? 'pending',
                ]);
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
                    'total_earnings' => $course->total_earnings,
                    'instructor' => $course->user->name ?? 'Unknown',
                ]);
        } catch (\Exception) {
            return [];
        }
    }

    // System methods
    private function getSystemLoadMetrics()
    {
        return [
            'database_queries' => 0,
            'response_time' => 'Normal',
            'uptime' => '99.9%',
        ];
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
                return 'Now';
            if ($diff < 60)
                return $diff . 'm';
            if ($diff < 1440)
                return round($diff / 60) . 'h';

            return round($diff / 1440) . 'd';
        } catch (\Exception) {
            return 'Now';
        }
    }

    /**
     * Get total users count with caching to avoid duplicate queries
     * @return int
     */
    private function getTotalUsersCount(): int
    {
        if ($this->totalUsersCache === null) {
            $this->totalUsersCache = User::count();
        }
        return $this->totalUsersCache;
    }

    // Default data methods
    private function getDefaultOverviewStats()
    {
        // Get currency symbol from settings
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
            'support_stats' => ['helpdesk_questions' => 0, 'helpdesk_replies' => 0],
            'engagement_trends' => [],
        ];
    }

    private function getDefaultChartData()
    {
        return [
            'revenue_chart' => ['labels' => [], 'data' => []],
            'user_registration_chart' => ['labels' => [], 'data' => []],
            'course_enrollment_chart' => ['labels' => [], 'data' => []],
            'course_creation_chart' => ['labels' => [], 'data' => []],
        ];
    }

    private function getDefaultActivities()
    {
        return [
            [
                'type' => 'system',
                'icon' => 'fas fa-info-circle',
                'color' => 'info',
                'title' => 'System Status',
                'description' => 'System is running normally',
                'time' => 'Now',
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
    private function getSubscriptionStats()
    {
        try {
            // Join subscriptions with subscription_plans to get billing_cycle
            $stats = DB::table('subscriptions')
                ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
                ->where('subscriptions.status', 'active')
                ->whereNull('subscriptions.deleted_at')
                ->select('subscription_plans.billing_cycle', DB::raw('COUNT(*) as count'))
                ->groupBy('subscription_plans.billing_cycle')
                ->get()
                ->keyBy('billing_cycle');

            return [
                'monthly'    => (int) ($stats->get('monthly')->count ?? 0),
                'quarterly'  => (int) ($stats->get('quarterly')->count ?? 0),
                'semi_annual' => (int) ($stats->get('semi_annual')->count ?? 0),
                'yearly'     => (int) ($stats->get('yearly')->count ?? 0),
                'lifetime'   => (int) ($stats->get('lifetime')->count ?? 0),
                'custom'     => (int) ($stats->get('custom')->count ?? 0),
                'total_active' => (int) Subscription::where('status', 'active')->count(),
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
                'total_active' => 0,
            ];
        }
    }

    /**
     * Get monthly financial summary: last 6 months with sales, commission, net_profit
     * الملخص المالي الشهري: آخر 6 أشهر
     */
    private function getMonthlyFinancialSummary(): array
    {
        try {
            $data = [];

            for ($i = 5; $i >= 0; $i--) {
                $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
                $monthEnd   = Carbon::now()->subMonths($i)->endOfMonth();
                $monthLabel = $monthStart->format('Y-m'); // e.g. "2026-01"
                $monthName  = $monthStart->translatedFormat('F Y'); // e.g. "January 2026"

                // Sales: sum of completed orders in this month
                $sales = (float) Order::where('status', 'completed')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('COALESCE(amount_egp, final_price)'));

                $subSales = (float) DB::table('subscription_payments')
                    ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
                    ->where('subscription_payments.status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                    ->whereBetween('subscription_payments.created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('subscription_payments.final_amount * COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1)'));

                $sales += $subSales;

                // Commission: sum of admin_commission_amount from commissions table * order's exchange rate
                $commission = (float) Commission::join('orders', 'commissions.order_id', '=', 'orders.id')
                    ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('commissions.admin_commission_amount * COALESCE(orders.exchange_rate_snapshot, 1)'));

                // Net profit = sales - total instructor commissions paid out
                $instructorPayout = (float) Commission::join('orders', 'commissions.order_id', '=', 'orders.id')
                    ->whereBetween('orders.created_at', [$monthStart, $monthEnd])
                    ->sum(DB::raw('commissions.instructor_commission_amount * COALESCE(orders.exchange_rate_snapshot, 1)'));

                $netProfit = $sales - $instructorPayout;

                $data[] = [
                    'month'            => $monthLabel,
                    'month_name'       => $monthName,
                    'sales'            => round($sales, 2),
                    'commission'       => round($commission, 2),
                    'instructor_payout' => round($instructorPayout, 2),
                    'net_profit'       => round($netProfit, 2),
                ];
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