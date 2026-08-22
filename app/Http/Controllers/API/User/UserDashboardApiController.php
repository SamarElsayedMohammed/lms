<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\WebinarRegistration;
use App\Models\Course\CourseCertificate;
use App\Services\ApiResponseService;
use App\Services\PricingService;
use App\Services\StudentDashboardStatisticsService;
use App\Services\SubscriptionService;
use App\Services\UserNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserDashboardApiController extends Controller
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly StudentDashboardStatisticsService $statisticsService,
        private readonly SubscriptionService $subscriptionService,
        private readonly UserNotificationService $notificationService,
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

            // Issued certificate inventory is part of the overview contract so the
            // dashboard does not need a second certificates request to correct it.
            $certificateOverview = $this->getCertificateOverview($user);

            // 7. Learning Activity
            $learningActivity = $this->getLearningActivity($user, $courseProgresses);

            // 8. Upcoming Webinars
            $upcomingWebinars = $this->getUpcomingWebinars($user);

            // 9. Notifications
            $unreadNotificationsCount = $this->notificationService->unreadCount($user);

            $data = [
                'stats' => $stats,
                'subscription' => $subscription,
                'wallet' => $wallet,
                'recent_courses' => $recentCourses,
                'latest_courses' => $latestCourses,
                'completed_courses' => $completedCourses,
                'certificate_preview' => $certificateOverview['preview'],
                'issued_certificate_course_ids' => $certificateOverview['course_ids'],
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
     * Get the primary visible subscription state (active, queued, or awaiting approval).
     */
    private function getSubscriptionInfo($user)
    {
        $sub = $this->subscriptionService->getPrimaryVisibleSubscription($user);

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
            'id'           => $sub->id,
            'has_active'   => (bool) $sub->is_active,
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
                $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
                $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
                $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? null) : $course->thumbnail;

                return [
                    'id'                  => $courseId,
                    'title'               => $courseTitle,
                    'thumbnail'           => $courseThumbnail,
                    'image'               => $courseThumbnail,
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
                $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
                $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
                $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? null) : $course->thumbnail;

                return [
                    'id'                  => $courseId,
                    'title'               => $courseTitle,
                    'thumbnail'           => $courseThumbnail,
                    'image'               => $courseThumbnail,
                    'progress'            => $snapshotProgress,
                    'progress_percentage' => $snapshotProgress,
                    'last_accessed'       => $track->updated_at->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();

        $videoActivities = \App\Models\VideoProgress::query()
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $user->id)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->where('video_progress.watched_seconds', '>', 0)
            ->select([
                'video_progress.id',
                'video_progress.updated_at',
                'course_chapters.course_id as tracked_course_id',
            ])
            ->latest('video_progress.updated_at')
            ->limit(5)
            ->get()
            ->unique('tracked_course_id')
            ->map(function ($progress) use ($coursesById, $progressByCourseId) {
                $course = $coursesById->get($progress->tracked_course_id);
                if (!$course) return null;

                $snapshotProgress = round((float) $progressByCourseId->get($progress->tracked_course_id), 2);
                $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
                $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
                $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? null) : $course->thumbnail;

                return [
                    'id'                  => $courseId,
                    'title'               => $courseTitle,
                    'thumbnail'           => $courseThumbnail,
                    'image'               => $courseThumbnail,
                    'progress'            => $snapshotProgress,
                    'progress_percentage' => $snapshotProgress,
                    'last_accessed'       => $progress->updated_at,
                ];
            })
            ->filter()
            ->values();

        return $progressActivities
            ->toBase()
            ->merge($trackingActivities)
            ->merge($videoActivities)
            ->sortByDesc('last_accessed')
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
            ->sortByDesc('enrolled_at')
            ->take(5)
            ->map(static function (array $item): array {
                $course = $item['course'];
                $progress = round($item['progress_percentage'], 2);
                $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
                $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
                $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? null) : $course->thumbnail;

                return [
                    'id' => $courseId,
                    'title' => $courseTitle,
                    'thumbnail' => $courseThumbnail,
                    'image' => $courseThumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                    'progress_status' => $item['learning_status'],
                    'enrolled_at' => $item['enrolled_at']?->toIso8601String(),
                    'access_started_at' => $item['access_started_at']?->toIso8601String(),
                    'purchase_date' => $item['purchase_date']?->toIso8601String(),
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
            ->whereHas('chapter', static function ($query) use ($courseIds): void {
                $query->whereIn('course_id', $courseIds)->where('is_active', true);
            })
            ->with(['chapter.course', 'trackable'])
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->filter(static function ($track): bool {
                if ($track->chapter === null || $track->chapter->course === null || $track->trackable === null) {
                    return false;
                }

                return !isset($track->trackable->is_active) || (bool) $track->trackable->is_active;
            })
            ->map(function ($track) {
                $type = 'activity';
                if (str_contains($track->model_type, 'Lecture')) $type = 'lecture';
                elseif (str_contains($track->model_type, 'Quiz')) $type = 'quiz';
                elseif (str_contains($track->model_type, 'Assignment')) $type = 'assignment';

                return [
                    'activity'     => ($track->status === 'completed' ? 'Completed ' : 'Started ') . $type,
                    'type'         => $type,
                    'status'       => $track->status,
                    'course_title' => $track->chapter->course->title,
                    'date'         => $track->updated_at?->toIso8601String(),
                ];
            })
            ->values();
    }

    /**
     * Get upcoming webinars
     */
    private function getUpcomingWebinars($user)
    {
        \App\Models\Webinar::syncPublishedLifecycleStatuses();

        return WebinarRegistration::where('user_id', $user->id)
            ->whereIn('payment_status', ['paid', 'free'])
            ->whereHas('webinar', function ($q) {
                $q->where('start_at', '>', now())
                    ->where('status', 'scheduled')
                    ->where('is_published', true);
            })
            ->with(['webinar.instructor'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($reg) {
                return [
                    'id' => $reg->webinar->id,
                    'title' => $reg->webinar->title,
                    'start_at' => $reg->webinar->start_at?->toIso8601String(),
                    'instructor' => $reg->webinar->instructor?->name ?? 'N/A',
                ];
            });
    }

    private function getCompletedCourses(Collection $courseProgresses)
    {
        return $courseProgresses
            ->where('learning_status', 'completed')
            ->map(static function (array $item): array {
                $course = $item['course'];
                $progress = round($item['progress_percentage'], 2);
                $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
                $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
                $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? null) : $course->thumbnail;

                return [
                    'id' => $courseId,
                    'title' => $courseTitle,
                    'thumbnail' => $courseThumbnail,
                    'image' => $courseThumbnail,
                    'progress' => $progress,
                    'progress_percentage' => $progress,
                    'progress_status' => 'completed',
                ];
            })
            ->values();
    }

    private function getCertificateOverview($user): array
    {
        $certificates = CourseCertificate::query()
            ->where('user_id', $user->id)
            ->active()
            ->with(['course.user'])
            ->latest('issued_date')
            ->latest('id')
            ->get();

        $certificate = $certificates->first();

        if ($certificate === null) {
            return ['preview' => null, 'course_ids' => []];
        }

        return ['course_ids' => $certificates->pluck('course_id')->map(fn ($id) => (int) $id)->values()->all(), 'preview' => [
            'id' => $certificate->id,
            'is_issued' => true,
            'status' => 'issued',
            'course_id' => $certificate->course_id,
            'slug' => $certificate->course?->slug ?? '',
            'title' => $certificate->course?->title ?? '',
            'thumbnail' => $certificate->course?->thumbnail ?? '',
            'author_name' => $certificate->instructor_name
                ?? $certificate->course?->user?->name
                ?? '',
            'certificate_number' => $certificate->certificate_number,
            'issued_at' => $certificate->issued_date?->toIso8601String(),
            'certificate_url' => $certificate->verification_url,
        ]];
    }


}
