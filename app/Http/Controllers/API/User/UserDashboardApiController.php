<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\OrderCourse;
use App\Models\Subscription;
use App\Models\UserCurriculumTracking;
use App\Models\WebinarRegistration;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserDashboardApiController extends Controller
{
    public function __construct(private readonly PricingService $pricingService) {}

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

            // 7. Notifications
            $unreadNotificationsCount = $user->unreadNotifications()->count();

            $data = [
                'stats' => $stats,
                'subscription' => $subscription,
                'wallet' => $wallet,
                'recent_courses' => $recentCourses,
                'learning_activity' => $learningActivity,
                'upcoming_webinars' => $upcomingWebinars,
                'unread_notifications_count' => $unreadNotificationsCount,
                'generated_at' => Carbon::now()->toDateTimeString(),
            ];

            return ApiResponseService::successResponse('User dashboard data retrieved successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to load dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Get overview stats for the user
     */
    private function getStatsOverview($user)
    {
        $enrolledCourseIds = OrderCourse::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'completed');
        })->pluck('course_id')->unique()->toArray();

        $totalEnrolled = count($enrolledCourseIds);
        
        $completedCount = 0;
        $inProgressCount = 0;

        foreach ($enrolledCourseIds as $courseId) {
            $progress = $this->calculateCourseProgress($user->id, $courseId);
            if ($progress >= 100) {
                $completedCount++;
            } elseif ($progress > 0) {
                $inProgressCount++;
            }
        }

        $certificatesCount = CourseCertificate::where('user_id', $user->id)->count();
        $wishlistCount = Wishlist::where('user_id', $user->id)->count();

        return [
            'enrolled_courses' => $totalEnrolled,
            'in_progress' => $inProgressCount,
            'completed_courses' => $completedCount,
            'certificates' => $certificatesCount,
            'wishlist_count' => $wishlistCount,
        ];
    }

    /**
     * Get active subscription info
     */
    private function getSubscriptionInfo($user)
    {
        $sub = Subscription::where('user_id', $user->id)
            ->active()
            ->with('plan')
            ->latest('created_at')
            ->first();

        if (!$sub) {
            return [
                'has_active' => false,
                'plan_name' => null,
                'status' => 'inactive',
                'starts_at' => null,
                'ends_at' => null,
                'days_remaining' => 0,
            ];
        }

        return [
            'has_active' => true,
            'plan_name' => $sub->plan->name ?? 'N/A',
            'status' => $sub->status,
            'starts_at' => $sub->starts_at?->toDateString(),
            'ends_at' => $sub->ends_at?->toDateString(),
            'days_remaining' => $sub->days_remaining,
        ];
    }

    /**
     * Get recently accessed courses
     */
    private function getRecentCourses($user)
    {
        $recentTrackings = UserCurriculumTracking::where('user_id', $user->id)
            ->with('chapter.course')
            ->latest('updated_at')
            ->get()
            ->unique(function ($item) {
                return $item->chapter->course_id ?? null;
            })
            ->take(5);

        return $recentTrackings->map(function ($track) use ($user) {
            $course = $track->chapter->course ?? null;
            if (!$course) return null;

            return [
                'id' => $course->id,
                'title' => $course->title,
                'thumbnail' => $course->thumbnail,
                'progress' => round($this->calculateCourseProgress($user->id, $course->id), 2),
                'last_accessed' => $track->updated_at->toDateTimeString(),
            ];
        })->filter()->values();
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
            ->map(function ($track) {
                $type = 'activity';
                if (str_contains($track->model_type, 'Lecture')) $type = 'lecture';
                elseif (str_contains($track->model_type, 'Quiz')) $type = 'quiz';
                elseif (str_contains($track->model_type, 'Assignment')) $type = 'assignment';

                return [
                    'activity' => 'Completed ' . $type,
                    'course_title' => $track->chapter->course->title ?? 'N/A',
                    'date' => $track->updated_at->toDateTimeString(),
                ];
            });
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
     * Duplicate logic from UserReportApiController for standalone use or better performance
     */
    private function calculateCourseProgress($userId, $courseId)
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

        if ($totalItems === 0) return 0;

        $completedItems = UserCurriculumTracking::where('user_id', $userId)
            ->whereHas('chapter', function($q) use ($courseId) {
                $q->where('course_id', $courseId);
            })
            ->where('status', 'completed')
            ->count();

        return ($completedItems / $totalItems) * 100;
    }
}
