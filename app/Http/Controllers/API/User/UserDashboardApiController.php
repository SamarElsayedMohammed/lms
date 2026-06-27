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
use App\Services\UserEnrollmentService;
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
                'overview_stats' => $stats,
                'dashboard_stats' => $stats,
                'subscription' => $subscription,
                'current_subscription' => $subscription,
                'wallet' => $wallet,
                'recent_courses' => $recentCourses,
                'latest_courses' => $recentCourses,
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
        // Use UserEnrollmentService to resolve all sources (orders, tracks, subscription)
        $enrollmentService = app(UserEnrollmentService::class);
        $enrolled = $enrollmentService->resolveEnrolledCourses((int) $user->id);
        $enrolledCourseIds = $enrolled->pluck('course_id')->toArray();

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
            'enrolled_courses'       => $totalEnrolled,
            'total_courses'          => $totalEnrolled,
            'total_enrolled_courses' => $totalEnrolled,
            'in_progress'            => $inProgressCount,
            'in_learning'            => $inProgressCount,
            'in_progress_courses'    => $inProgressCount,
            'completed_courses'      => $completedCount,
            'certificates'           => $certificatesCount,
            'total_certificates'     => $certificatesCount,
            'wishlist_count'         => $wishlistCount,
            'favorites'              => $wishlistCount,
            'favorite_courses'       => $wishlistCount,
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
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'image' => $course->thumbnail,
                    'progress' => round((float) $progress->progress_percentage, 2),
                    'progress_percentage' => round((float) $progress->progress_percentage, 2),
                    'last_accessed' => $progress->last_accessed_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();

        $recentTrackings = UserCurriculumTracking::where('user_id', $user->id)
            ->with('chapter.course')
            ->latest('updated_at')
            ->get()
            ->unique(function ($item) {
                return $item->chapter->course_id ?? null;
            })
            ->map(function ($track) use ($user, $enrolledCourseIds) {
                $course = $track->chapter->course ?? null;
                if (!$course || !$enrolledCourseIds->contains((int) $course->id)) return null;

                $progress = round($this->calculateCourseProgress($user->id, $course->id), 2);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'image' => $course->thumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                    'last_accessed' => $track->updated_at->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();

        return $recent
            ->merge($recentTrackings)
            ->merge($enrolled->sortByDesc('purchase_date')->map(function (array $item) use ($user) {
                $course = $item['course'];
                $progress = round($this->calculateCourseProgress($user->id, $course->id), 2);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'image' => $course->thumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                    'last_accessed' => $item['purchase_date']?->toDateTimeString(),
                ];
            }))
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
        $cachedProgress = UserCourseProgress::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        if ($cachedProgress && (float) $cachedProgress->progress_percentage > 0) {
            return (float) $cachedProgress->progress_percentage;
        }

        if ($cachedProgress && $cachedProgress->status === 'completed') {
            return 100.0;
        }

        $totalItems = DB::table('course_chapter_lectures')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapters.is_active', 1)
            ->where('course_chapter_lectures.is_active', 1)
            ->count()
            + DB::table('course_chapter_quizzes')
            ->join('course_chapters', 'course_chapter_quizzes.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapters.is_active', 1)
            ->where('course_chapter_quizzes.is_active', 1)
            ->count()
            + DB::table('course_chapter_assignments')
            ->join('course_chapters', 'course_chapter_assignments.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapters.is_active', 1)
            ->where('course_chapter_assignments.is_active', 1)
            ->count()
            + DB::table('course_chapter_resources')
            ->join('course_chapters', 'course_chapter_resources.course_chapter_id', '=', 'course_chapters.id')
            ->where('course_chapters.course_id', $courseId)
            ->where('course_chapters.is_active', 1)
            ->where('course_chapter_resources.is_active', 1)
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
