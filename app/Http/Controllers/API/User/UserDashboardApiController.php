<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\OrderCourse;
use App\Models\Subscription;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\WebinarRegistration;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\PricingService;
use App\Services\StudentDashboardStatisticsService;
use App\Services\UserEnrollmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserDashboardApiController extends Controller
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly StudentDashboardStatisticsService $statisticsService
    ) {}

    /**
     * Get user dashboard data
     */
    public function getDashboardData(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            // 1. Stats Overview
            $stats = $this->getStatsOverview($user);

            // 2. Subscription Info
            $subscription = $this->getSubscriptionInfo($user);

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';
            $walletBalance   = round($user->wallet_balance ?? 0, 2);

            // 3. Wallet Balance
            $wallet = [
                'balance'         => $walletBalance,
                'local_balance'   => $this->pricingService->convertFromEgp($walletBalance, $displayCurrency),
                'currency'        => $displayCurrency,
                'currency_symbol' => $displaySymbol,
            ];

            // 4. Recent Courses (Last accessed)
            $recentCourses = $this->getRecentCourses($user);

            // 5. Learning Activity
            $learningActivity = $this->getLearningActivity($user);

            // 6. Upcoming Webinars
            $upcomingWebinars = $this->getUpcomingWebinars($user);

            // 7. Completed Courses List
            $completedCoursesList = $this->getCompletedCourses($user);

            // 8. Notifications
            $unreadNotificationsCount = $user->unreadNotifications()->count();

            $data = [
                'stats' => $stats,
                'overview_stats' => $stats,
                'dashboard_stats' => $stats,
                'subscription' => $subscription,
                'current_subscription' => $subscription,
                'wallet' => $wallet,
                'recent_courses' => $recentCourses,
                'latest_courses' => $recentCourses,
                'completed_courses_list' => $completedCoursesList,
                'learning_activity' => $learningActivity,
                'upcoming_webinars' => $upcomingWebinars,
                'unread_notifications_count' => $unreadNotificationsCount,
                'generated_at' => Carbon::now()->toDateTimeString(),
            ];

            return ApiResponseService::successResponse('User dashboard data retrieved successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to load dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Get overview stats for the user
     */
    private function getStatsOverview($user)
    {
        return $this->statisticsService->getDashboardStats($user);
    }

    /**
     * Get active subscription info
     */
    private function getSubscriptionInfo($user)
    {
        $sub = Subscription::where('user_id', $user->id)
            ->active()
            ->with('plan')
            ->orderByRaw('ends_at IS NULL DESC') // Lifetime first
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (!$sub) {
            return [
                'has_active' => false,
                'plan_name' => null,
                'status' => 'no_subscription',
                'status_label' => 'لا يوجد اشتراك',
                'starts_at' => null,
                'ends_at' => null,
                'days_remaining' => 0,
                'plan' => null,
            ];
        }

        return [
            'has_active' => true,
            'plan_name' => $sub->plan->name ?? 'N/A',
            'status' => $sub->status,
            'status_label' => $sub->status === Subscription::STATUS_ACTIVE ? 'نشط' : $sub->status,
            'starts_at' => $sub->starts_at?->toDateString(),
            'ends_at' => $sub->ends_at?->toDateString(),
            'days_remaining' => $sub->days_remaining,
            'plan' => $sub->plan ? [
                'id' => $sub->plan->id,
                'name' => $sub->plan->name,
            ] : null,
        ];
    }

    /**
     * Get recently accessed courses
     */
    private function getRecentCourses($user)
    {
        $enrollmentService = app(UserEnrollmentService::class);
        $enrolled = $enrollmentService->resolveEnrolledCourses((int) $user->id);
        $enrolledCourseIds = $enrolled->pluck('course_id')->values();

        if ($enrolledCourseIds->isEmpty()) {
            return collect();
        }

        $coursesById = $enrolled->pluck('course', 'course_id');

        // === Source 1: UserCourseProgress rows (has an explicit last_accessed_at) ===
        $recent = UserCourseProgress::where('user_id', $user->id)
            ->whereIn('course_id', $enrolledCourseIds)
            ->whereNotNull('last_accessed_at')
            ->latest('last_accessed_at')
            ->limit(5)
            ->get()
            ->map(function ($progress) use ($coursesById) {
                $course = $coursesById->get($progress->course_id);
                if (!$course) return null;

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => round((float) $progress->progress_percentage, 2),
                    'progress_percentage' => round((float) $progress->progress_percentage, 2),
                    // Real interaction timestamp — used for sorting priority
                    'last_accessed'       => $progress->last_accessed_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->values()
            ->toBase();

        // === Source 2: UserCurriculumTracking rows (interaction-based, no UserCourseProgress) ===
        $recentTrackings = UserCurriculumTracking::where('user_id', $user->id)
            ->with('chapter.course')
            ->latest('updated_at')
            ->get()
            ->filter(fn($item) => $item->chapter !== null && $item->chapter->course !== null) // Bug 6 fix
            ->unique(function ($item) {
                return $item->chapter->course_id ?? null;
            })
            ->map(function ($track) use ($user, $enrolledCourseIds) {
                $course = $track->chapter->course ?? null;
                if (!$course || !$enrolledCourseIds->contains((int) $course->id)) return null;

                $progress = round($this->calculateCourseProgress($user->id, $course->id), 2);

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $progress,
                    'progress_percentage' => $progress,
                    // Real interaction timestamp from tracking
                    'last_accessed'       => $track->updated_at->toDateTimeString(),
                ];
            })
            ->filter()
            ->values()
            ->toBase();

        // === Source 3: Enrolled courses with no tracking/progress rows yet ===
        // Bug 5 fix: for truly unstarted courses we set last_accessed = null.
        // These are only shown to fill the 5-slot limit after real-activity courses.
        $fallbackEnrolled = $enrolled
            ->sortByDesc('purchase_date')
            ->map(function (array $item) use ($user) {
                $course = $item['course'];
                $progress = round($this->calculateCourseProgress($user->id, $course->id), 2);

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $progress,
                    'progress_percentage' => $progress,
                    // Bug 5 fix: null — this is NOT a real access timestamp.
                    // Never use purchase_date as last_accessed.
                    'last_accessed'       => null,
                ];
            })
            ->toBase();

        // Merge all sources; deduplicate by course ID; real-activity entries win (they appear first).
        // Then take the top 5. Courses with null last_accessed sink to the bottom naturally
        // because sources 1 & 2 come first and dedup removes the fallback entries for those IDs.
        return $recent
            ->merge($recentTrackings)
            ->merge($fallbackEnrolled)
            ->unique('id')
            ->take(5)
            ->values();
    }

    /**
     * Get recent learning activities
     */
    private function getLearningActivity($user)
    {
        return UserCurriculumTracking::where('user_id', $user->id)
            ->with('chapter.course')
            ->latest('updated_at')
            ->limit(10)
            ->get()
            // Bug 6 fix: skip orphaned rows (chapter or course deleted) to prevent
            // "N/A" titles appearing in the learning activity feed.
            ->filter(fn($track) => $track->chapter !== null && $track->chapter->course !== null)
            ->map(function ($track) {
                $type = 'activity';
                if (str_contains($track->model_type, 'Lecture')) $type = 'lecture';
                elseif (str_contains($track->model_type, 'Quiz')) $type = 'quiz';
                elseif (str_contains($track->model_type, 'Assignment')) $type = 'assignment';

                return [
                    'activity'     => 'Completed ' . $type,
                    'course_title' => $track->chapter->course->title,
                    'date'         => $track->updated_at->toDateTimeString(),
                ];
            })
            ->values();
    }

    /**
     * Get upcoming webinars
     */
    private function getUpcomingWebinars($user)
    {
        return WebinarRegistration::where('user_id', $user->id)
            ->whereHas('webinar', function ($q) {
                $q->where('start_at', '>', now());
            })
            ->with(['webinar.instructor'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($reg) {
                return [
                    'id' => $reg->webinar->id,
                    'title' => $reg->webinar->title,
                    'start_at' => $reg->webinar->start_at->toDateTimeString(),
                    'instructor' => $reg->webinar->instructor->name ?? 'N/A',
                ];
            });
    }

    /**
     * Get completed courses
     */
    private function getCompletedCourses($user)
    {
        $enrollmentService = app(UserEnrollmentService::class);
        $enrolled = $enrollmentService->resolveEnrolledCourses((int) $user->id);
        
        $completed = collect();

        foreach ($enrolled as $item) {
            $course = $item['course'];
            if (!$course) continue;

            $progress = round($this->calculateCourseProgress($user->id, $course->id), 2);
            
            if ($progress >= 100) {
                $completed->push([
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $progress,
                    'progress_percentage' => $progress,
                ]);
            }
        }

        return $completed->values();
    }

    /**
     * Duplicate logic from UserReportApiController for standalone use or better performance
     */
    private function calculateCourseProgress($userId, $courseId)
    {
        return (float) app(\App\Services\CourseProgressService::class)
            ->getProgressWithCache($userId, $courseId)
            ->progress_percentage;
    }
}
