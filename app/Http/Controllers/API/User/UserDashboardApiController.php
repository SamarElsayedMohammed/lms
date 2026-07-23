<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\PricingService;
use App\Services\StudentDashboardStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

            // Resolve progress once so every dashboard metric uses the same snapshot.
            $courseProgresses = $this->statisticsService->getEnrolledCourseProgresses($user);
            $stats = $this->statisticsService->getDashboardStatsForCourseProgresses(
                $user,
                $courseProgresses,
            );

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
            $recentCourses = $this->getRecentCourses($user, $courseProgresses);

            // 5. Latest courses are ordered by enrollment, not recent activity.
            $latestCourses = $this->getLatestCourses($courseProgresses);

            // 6. Completed courses use the exact same progress source as stats.
            $completedCourses = $this->getCompletedCourses($courseProgresses);

            // 7. Learning Activity
            $learningActivity = $this->getLearningActivity($user, $courseProgresses);

            // 8. Upcoming Webinars
            $upcomingWebinars = $this->getUpcomingWebinars($user);

            // 9. Notifications
            $unreadNotificationsCount = $user->unreadNotifications()->count();

            $data = [
                'stats' => $stats,
                'subscription' => $subscription,
                'wallet' => $wallet,
                'recent_courses' => $recentCourses,
                'latest_courses' => $latestCourses,
                'completed_courses' => $completedCourses,
                'learning_activity' => $learningActivity,
                'upcoming_webinars' => $upcomingWebinars,
                'unread_notifications_count' => $unreadNotificationsCount,
                'generated_at' => now('UTC')->toIso8601String(),
            ];

            return ApiResponseService::successResponse('User dashboard data retrieved successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unable to load user dashboard', [
                'user_id' => Auth::id(),
                'exception' => $e,
            ]);

            return ApiResponseService::errorResponse('Failed to load dashboard');
        }
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
                'has_active'   => false,
                'plan_name'    => null,
                'status'       => 'no_subscription',
                'status_label' => 'لا يوجد اشتراك',
                'starts_at'    => null,
                'ends_at'      => null,
                'days_remaining' => 0,
                'plan'         => null,
            ];
        }

        $statusLabel = match ($sub->status) {
            \App\Models\Subscription::STATUS_ACTIVE    => 'نشط',
            \App\Models\Subscription::STATUS_EXPIRED   => 'منتهي',
            \App\Models\Subscription::STATUS_CANCELLED => 'ملغي',
            \App\Models\Subscription::STATUS_PENDING   => 'قيد الانتظار',
            \App\Models\Subscription::STATUS_PENDING_APPROVAL => 'قيد المراجعة',
            default                                    => $sub->status,
        };

        return [
            'has_active'   => true,
            'plan_name'    => $sub->plan->name ?? 'N/A',
            'status'       => $sub->status,
            'status_label' => $statusLabel,
            'starts_at'    => $sub->starts_at?->toDateString(),
            'ends_at'      => $sub->ends_at?->toDateString(),
            'days_remaining' => $sub->days_remaining,
            'plan'         => $sub->plan ? [
                'id'   => $sub->plan->id,
                'name' => $sub->plan->name,
            ] : null,
        ];
    }

    /**
     * Get recently accessed courses
     */
    private function getRecentCourses($user, Collection $courseProgresses)
    {
        $courseIds = $courseProgresses->pluck('course_id')->all();
        if ($courseIds === []) {
            return collect();
        }

        $coursesById = $courseProgresses->pluck('course', 'course_id');
        $progressByCourseId = $courseProgresses->pluck('progress_percentage', 'course_id');

        $progressActivities = UserCourseProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('last_accessed_at')
            ->select(['id', 'course_id', 'last_accessed_at'])
            ->latest('last_accessed_at')
            ->limit(5)
            ->get()
            ->map(function ($progress) use ($coursesById, $progressByCourseId) {
                $course = $coursesById->get($progress->course_id);
                if (!$course) return null;

                $snapshotProgress = round((float) $progressByCourseId->get($progress->course_id), 2);

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $snapshotProgress,
                    'progress_percentage' => $snapshotProgress,
                    'last_accessed'       => $progress->last_accessed_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();

        $trackingActivities = UserCurriculumTracking::query()
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->where('user_curriculum_trackings.user_id', $user->id)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->select([
                'user_curriculum_trackings.id',
                'user_curriculum_trackings.course_chapter_id',
                'user_curriculum_trackings.updated_at',
                'course_chapters.course_id as tracked_course_id',
            ])
            ->latest('user_curriculum_trackings.updated_at')
            ->limit(5)
            ->get()
            ->unique('tracked_course_id')
            ->map(function ($track) use ($coursesById, $progressByCourseId) {
                $course = $coursesById->get($track->tracked_course_id);
                if (!$course) return null;

                $snapshotProgress = round((float) $progressByCourseId->get($track->tracked_course_id), 2);

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $snapshotProgress,
                    'progress_percentage' => $snapshotProgress,
                    'last_accessed'       => $track->updated_at->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();

        $fallbackCourses = $courseProgresses
            ->filter(fn($item) => $item['source'] !== 'subscription')
            ->sortByDesc('purchase_date')
            ->take(5)
            ->map(function (array $item) {
                $course = $item['course'];
                $snapshotProgress = round($item['progress_percentage'], 2);

                return [
                    'id'                  => $course->id,
                    'title'               => $course->title,
                    'thumbnail'           => $course->thumbnail,
                    'image'               => $course->thumbnail,
                    'progress'            => $snapshotProgress,
                    'progress_percentage' => $snapshotProgress,
                    'last_accessed'       => null,
                ];
            });

        return $progressActivities
            ->merge($trackingActivities)
            ->sortByDesc('last_accessed')
            ->unique('id')
            ->merge($fallbackCourses)
            ->unique('id')
            ->take(5)
            ->values();
    }

    /**
     * Get valid enrolled courses ordered by the enrollment or purchase timestamp.
     */
    private function getLatestCourses(Collection $courseProgresses)
    {
        return $courseProgresses
            ->sortByDesc('purchase_date')
            ->take(5)
            ->map(static function (array $item): array {
                $course = $item['course'];
                $progress = round($item['progress_percentage'], 2);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'image' => $course->thumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                    'enrolled_at' => $item['purchase_date']?->toIso8601String(),
                ];
            })
            ->values();
    }

    /**
     * Get recent learning activities
     */
    private function getLearningActivity($user, Collection $courseProgresses)
    {
        $courseIds = $courseProgresses->pluck('course_id')->all();

        if ($courseIds === []) {
            return collect();
        }

        return UserCurriculumTracking::where('user_id', $user->id)
            // Activity alone never grants dashboard visibility. Restrict it to
            // the same valid-access course snapshot used by every course metric.
            ->whereHas('chapter', static function ($query) use ($courseIds): void {
                $query->whereIn('course_id', $courseIds);
            })
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

    private function getCompletedCourses(Collection $courseProgresses)
    {
        return $courseProgresses
            ->filter(static fn (array $item): bool => $item['progress_percentage'] >= 100)
            ->map(static function (array $item): array {
                $course = $item['course'];
                $progress = round($item['progress_percentage'], 2);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'image' => $course->thumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                ];
            })
            ->values();
    }


}
