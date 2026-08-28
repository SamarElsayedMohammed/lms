<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseCertificate;
use App\Models\Setting;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\StudentDashboardStatisticsService;
use App\Services\SubscriptionService;
use App\Services\UserEnrollmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MobileLearningSpaceApiController extends Controller
{
    public function __construct(
        private readonly StudentDashboardStatisticsService $statisticsService,
        private readonly SubscriptionService $subscriptionService,
        private readonly UserEnrollmentService $enrollmentService,
    ) {}

    /**
     * GET /api/mobile/learning-space
     * Aggregated canonical endpoint for the Student Learning Command Center (مساحتي).
     */
    public function getLearningSpaceData(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'ok' => false,
                    'message' => 'User not authenticated',
                    'data' => null,
                ], 401);
            }

            // 1. Resolve canonical course progresses & stats snapshot
            $courseProgresses = $this->statisticsService->getEnrolledCourseProgresses($user);
            $stats = $this->statisticsService->getDashboardStatsForCourseProgresses($user, $courseProgresses);

            // 2. Summary metrics
            $wishlistCount = Wishlist::where('user_id', $user->id)->count();
            $summary = [
                'active_courses_count'    => (int) ($stats['in_progress_courses'] ?? 0),
                'completed_courses_count' => (int) ($stats['completed_courses'] ?? 0),
                'total_enrolled_courses'  => (int) ($stats['total_courses'] ?? 0),
                'certificates_count'      => (int) ($stats['certificates'] ?? 0),
                'learning_hours'          => round((float) ($stats['learning_hours'] ?? 0), 1),
                'saved_courses_count'     => $wishlistCount,
            ];

            // 3. Continue Learning (Hero course with next lesson resolution)
            $continueLearning = $this->resolveContinueLearningCourse($user, $courseProgresses);

            // 4. Active Courses (In-progress: >0% and <100%)
            $activeCourses = $this->resolveActiveCourses($user, $courseProgresses);

            // 5. Completed Courses
            $completedCourses = $this->resolveCompletedCourses($user, $courseProgresses);

            // 6. Saved Courses (Canonical Wishlist)
            $savedCourses = $this->resolveSavedCourses($user);

            // 7. Certificates (Issued credentials)
            $certificates = $this->resolveCertificates($user);

            // 8. Recent Learning Activity
            $learningActivity = $this->resolveLearningActivity($user, $courseProgresses);

            return response()->json([
                'ok' => true,
                'message' => 'Learning space retrieved successfully',
                'data' => [
                    'user'               => [
                        'id'        => $user->id,
                        'name'      => $user->name,
                        'email'     => $user->email,
                        'avatar'    => $user->avatar_url ?? $user->image,
                    ],
                    'summary'            => $summary,
                    'continue_learning'  => $continueLearning,
                    'active_courses'     => $activeCourses,
                    'saved_courses'      => $savedCourses,
                    'completed_courses'  => $completedCourses,
                    'certificates'       => $certificates,
                    'learning_activity'  => $learningActivity,
                    'generated_at'       => now('UTC')->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to get mobile learning space', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to load learning space data: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * POST /api/mobile/downloads/authorize
     * Authorizes video download for offline playback based on enrollment or active subscription.
     */
    public function authorizeDownload(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'ok' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $validated = $request->validate([
                'course_id'  => 'required|integer',
                'lecture_id' => 'nullable|integer',
                'lesson_id'  => 'nullable|integer',
            ]);

            $courseId = (int) $validated['course_id'];
            $lectureId = (int) ($validated['lecture_id'] ?? $validated['lesson_id'] ?? 0);

            $course = Course::find($courseId);
            if (!$course) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Course not found',
                ], 404);
            }

            // Verify entitlement (Direct Enrollment OR Active Subscription OR Free Course)
            $enrolledCourseIds = $this->enrollmentService->resolveEnrolledCourseIds((int) $user->id)->pluck('course_id')->all();
            $isEnrolled = in_array($courseId, $enrolledCourseIds, true);
            $hasActiveSub = $this->subscriptionService->getActiveSubscription($user) !== null;
            $isFree = (bool) ($course->is_free ?? false);

            if (!$isEnrolled && !$hasActiveSub && !$isFree) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized. You must be enrolled or have an active subscription to download this course.',
                    'entitlement_status' => 'not_entitled',
                ], 403);
            }

            // Check if downloads are globally or course-specifically allowed
            $downloadsEnabled = $this->getSettingValue('mobile_downloads_enabled', '1') !== '0';
            if (!$downloadsEnabled) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Offline downloads are currently disabled by administrator.',
                    'entitlement_status' => 'downloads_disabled',
                ], 403);
            }

            // Resolve lesson details if lecture_id provided
            $lectureData = null;
            if ($lectureId > 0) {
                $lecture = CourseChapterLecture::where('id', $lectureId)
                    ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
                    ->first();

                if ($lecture) {
                    $mediaUrl = $lecture->video_url ?? $lecture->url ?? $lecture->file ?? '';
                    $lectureData = [
                        'id'         => $lecture->id,
                        'title'      => $lecture->title,
                        'duration'   => (int) (($lecture->hours ?? 0) * 3600 + ($lecture->minutes ?? 0) * 60 + ($lecture->seconds ?? 0)),
                        'media_url'  => $mediaUrl,
                        'video_type' => $lecture->type ?? 'file',
                    ];
                }
            }

            // Offline token validity duration (Default 30 days or subscription end date)
            $validDays = (int) $this->getSettingValue('mobile_download_expiry_days', '30');
            $offlineValidUntil = now()->addDays($validDays);

            if ($hasActiveSub && !$isEnrolled && !$isFree) {
                $activeSub = $this->subscriptionService->getActiveSubscription($user);
                if ($activeSub && $activeSub->ends_at && $activeSub->ends_at->isFuture()) {
                    $offlineValidUntil = min($offlineValidUntil, $activeSub->ends_at);
                }
            }

            return response()->json([
                'ok' => true,
                'message' => 'Download authorized successfully',
                'data' => [
                    'is_authorized'       => true,
                    'course_id'           => $courseId,
                    'course_title'        => $course->title,
                    'lecture'             => $lectureData,
                    'offline_valid_until' => $offlineValidUntil->toIso8601String(),
                    'quality_options'     => [
                        ['label' => 'عالية (720p)', 'quality' => '720p'],
                        ['label' => 'متوسطة (480p)', 'quality' => '480p'],
                        ['label' => 'اقتصادية (360p)', 'quality' => '360p'],
                    ],
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Download authorization failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to authorize download',
            ], 500);
        }
    }

    /**
     * POST /api/mobile/learning/sync-progress
     * Reconciles offline watch sessions safely without rolling progress backwards.
     */
    public function syncProgress(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'updates'                         => 'required|array',
                'updates.*.course_id'             => 'required|integer',
                'updates.*.lecture_id'            => 'required|integer',
                'updates.*.completed'             => 'nullable|boolean',
                'updates.*.time_spent_seconds'    => 'nullable|integer',
                'updates.*.last_position_seconds' => 'nullable|numeric',
                'updates.*.synced_at'             => 'nullable|string',
            ]);

            $syncedCount = 0;
            $touchedCourseIds = [];

            foreach ($validated['updates'] as $update) {
                $courseId = (int) $update['course_id'];
                $lectureId = (int) $update['lecture_id'];
                $completed = (bool) ($update['completed'] ?? false);
                $timeSpent = (int) ($update['time_spent_seconds'] ?? 0);
                $lastPosition = (float) ($update['last_position_seconds'] ?? 0);

                // Verify lecture belongs to course
                $lecture = CourseChapterLecture::where('id', $lectureId)
                    ->whereHas('chapter', fn($q) => $q->where('course_id', $courseId))
                    ->first();

                if (!$lecture) continue;

                $tracking = UserCurriculumTracking::firstOrNew([
                    'user_id'           => $user->id,
                    'course_chapter_id' => $lecture->course_chapter_id,
                    'model_id'          => $lectureId,
                    'model_type'        => CourseChapterLecture::class,
                ]);

                // Never regress a completed lesson to in-progress
                if ($completed || $tracking->status === 'completed') {
                    $tracking->status = 'completed';
                    if (!$tracking->completed_at) {
                        $tracking->completed_at = now();
                    }
                } else {
                    $tracking->status = 'in_progress';
                }

                if (!$tracking->started_at) {
                    $tracking->started_at = now();
                }

                $tracking->time_spent = max((int) ($tracking->time_spent ?? 0), $timeSpent);
                $currentMeta = is_array($tracking->metadata) ? $tracking->metadata : [];
                $currentMeta['last_position_seconds'] = $lastPosition;
                $currentMeta['offline_synced_at'] = now()->toIso8601String();
                $tracking->metadata = $currentMeta;

                $tracking->save();
                $syncedCount++;
                $touchedCourseIds[$courseId] = true;
            }

            // Recalculate Course Progress for all touched courses
            foreach (array_keys($touchedCourseIds) as $cId) {
                $totalLectures = CourseChapterLecture::whereHas('chapter', fn($q) => $q->where('course_id', $cId))->count();
                if ($totalLectures > 0) {
                    $completedLectures = UserCurriculumTracking::where('user_id', $user->id)
                        ->where('model_type', CourseChapterLecture::class)
                        ->where('status', 'completed')
                        ->whereHas('chapter', fn($q) => $q->where('course_id', $cId))
                        ->count();

                    $progressPct = min(100.0, round(($completedLectures / $totalLectures) * 100, 2));

                    UserCourseProgress::updateOrCreate(
                        ['user_id' => $user->id, 'course_id' => $cId],
                        [
                            'completed_items'     => $completedLectures,
                            'total_items'         => $totalLectures,
                            'progress_percentage' => $progressPct,
                            'last_accessed_at'    => now(),
                            'status'              => $progressPct >= 100 ? 'completed' : 'in_progress',
                        ]
                    );
                }
            }

            return response()->json([
                'ok' => true,
                'message' => "Successfully synced {$syncedCount} learning items",
                'data' => [
                    'synced_items_count' => $syncedCount,
                    'synced_at'          => now('UTC')->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to sync offline learning progress', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Failed to sync learning progress'], 500);
        }
    }

    /**
     * GET /api/admin/mobile-learning/settings
     */
    public function getAdminSettings(): JsonResponse
    {
        $settings = [
            'downloads_enabled'       => $this->getSettingValue('mobile_downloads_enabled', '1') === '1',
            'download_expiry_days'    => (int) $this->getSettingValue('mobile_download_expiry_days', '30'),
            'max_devices_per_user'    => (int) $this->getSettingValue('mobile_max_devices_per_user', '3'),
            'offline_sync_enabled'    => $this->getSettingValue('mobile_offline_sync_enabled', '1') === '1',
            'certificates_tab_enabled'=> $this->getSettingValue('mobile_certificates_tab_enabled', '1') === '1',
        ];

        return response()->json(['ok' => true, 'data' => $settings], 200);
    }

    /**
     * PUT /api/admin/mobile-learning/settings
     */
    public function updateAdminSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'downloads_enabled'        => 'nullable|boolean',
            'download_expiry_days'     => 'nullable|integer|min:1|max:365',
            'max_devices_per_user'     => 'nullable|integer|min:1|max:10',
            'offline_sync_enabled'     => 'nullable|boolean',
            'certificates_tab_enabled' => 'nullable|boolean',
        ]);

        if (isset($validated['downloads_enabled'])) {
            $this->setSettingValue('mobile_downloads_enabled', $validated['downloads_enabled'] ? '1' : '0');
        }
        if (isset($validated['download_expiry_days'])) {
            $this->setSettingValue('mobile_download_expiry_days', (string) $validated['download_expiry_days']);
        }
        if (isset($validated['max_devices_per_user'])) {
            $this->setSettingValue('mobile_max_devices_per_user', (string) $validated['max_devices_per_user']);
        }
        if (isset($validated['offline_sync_enabled'])) {
            $this->setSettingValue('mobile_offline_sync_enabled', $validated['offline_sync_enabled'] ? '1' : '0');
        }
        if (isset($validated['certificates_tab_enabled'])) {
            $this->setSettingValue('mobile_certificates_tab_enabled', $validated['certificates_tab_enabled'] ? '1' : '0');
        }

        return $this->getAdminSettings();
    }

    // --- Private Resolvers ---

    private function getSettingValue(string $key, string $default = ''): string
    {
        try {
            $setting = Setting::where('name', $key)->first();
            return $setting ? (string) $setting->value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function setSettingValue(string $key, string $value): void
    {
        try {
            Setting::updateOrCreate(
                ['name' => $key],
                ['value' => $value, 'type' => 'text']
            );
        } catch (\Throwable $e) {
            Log::warning("Could not set setting {$key}: {$e->getMessage()}");
        }
    }

    private function resolveContinueLearningCourse($user, Collection $courseProgresses): ?array
    {
        $courseIds = $courseProgresses->pluck('course_id')->all();
        if (empty($courseIds)) return null;

        $coursesById = $courseProgresses->pluck('course', 'course_id');
        $progressByCourseId = $courseProgresses->pluck('progress_percentage', 'course_id');

        // Look for the most recently touched active course (progress < 100%)
        $latestActiveProgress = UserCourseProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->where('progress_percentage', '<', 100)
            ->latest('last_accessed_at')
            ->first();

        if (!$latestActiveProgress) {
            // Fallback: any course with progress < 100
            $latestActiveProgress = UserCourseProgress::where('user_id', $user->id)
                ->whereIn('course_id', $courseIds)
                ->where('progress_percentage', '<', 100)
                ->first();
        }

        if (!$latestActiveProgress) return null;

        $course = $coursesById->get($latestActiveProgress->course_id) ?? Course::find($latestActiveProgress->course_id);
        if (!$course) return null;

        $progressPct = round((float) ($progressByCourseId->get($latestActiveProgress->course_id) ?? $latestActiveProgress->progress_percentage), 1);

        // Find Next Lesson
        $completedLectureIds = UserCurriculumTracking::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('model_type', CourseChapterLecture::class)
            ->whereHas('chapter', fn($q) => $q->where('course_id', $course->id))
            ->pluck('model_id')
            ->all();

        $nextLecture = CourseChapterLecture::whereHas('chapter', fn($q) => $q->where('course_id', $course->id)->orderBy('order', 'asc'))
            ->whereNotIn('id', $completedLectureIds)
            ->orderBy('chapter_order', 'asc')
            ->first();

        return [
            'course_id'           => $course->id,
            'title'               => $course->title,
            'thumbnail'           => $course->thumbnail ?? '',
            'instructor_name'     => $course->user->name ?? 'مدرب Skillso',
            'progress_percentage' => $progressPct,
            'next_lecture_id'     => $nextLecture?->id,
            'next_lecture_title'  => $nextLecture?->title ?? 'متابعة الدرس القادم',
            'last_accessed_at'    => $latestActiveProgress->last_accessed_at?->toIso8601String(),
        ];
    }

    private function resolveActiveCourses($user, Collection $courseProgresses): array
    {
        $active = [];
        foreach ($courseProgresses as $prog) {
            $courseId = is_array($prog) ? ($prog['course_id'] ?? null) : $prog->course_id;
            $pct = round((float) (is_array($prog) ? ($prog['progress_percentage'] ?? 0) : ($prog->progress_percentage ?? 0)), 1);
            if ($pct >= 100) continue; // Skip completed

            $course = is_array($prog) ? ($prog['course'] ?? null) : ($prog->course ?? null);
            if (!$course && $courseId) {
                $course = Course::find($courseId);
            }
            if (!$course) continue;

            $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
            $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
            $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? '') : ($course->thumbnail ?? '');
            $instructorName = is_array($course)
                ? ($course['user']['name'] ?? 'مدرب Skillso')
                : ($course->user->name ?? 'مدرب Skillso');

            $active[] = [
                'id'                  => $courseId,
                'title'               => $courseTitle,
                'thumbnail'           => $courseThumbnail,
                'instructor'          => $instructorName,
                'progress_percentage' => $pct,
                'completed_items'     => (int) (is_array($prog) ? ($prog['completed_items'] ?? 0) : ($prog->completed_items ?? 0)),
                'total_items'         => (int) (is_array($prog) ? ($prog['total_items'] ?? 0) : ($prog->total_items ?? 0)),
                'last_accessed_at'    => is_array($prog) ? ($prog['last_accessed_at'] ?? null) : ($prog->last_accessed_at?->toIso8601String()),
            ];
        }

        return array_slice($active, 0, 10);
    }

    private function resolveCompletedCourses($user, Collection $courseProgresses): array
    {
        $completed = [];
        $issuedCertificates = CourseCertificate::where('user_id', $user->id)
            ->where('status', 'issued')
            ->get()
            ->keyBy('course_id');

        foreach ($courseProgresses as $prog) {
            $courseId = is_array($prog) ? ($prog['course_id'] ?? null) : $prog->course_id;
            $pct = round((float) (is_array($prog) ? ($prog['progress_percentage'] ?? 0) : ($prog->progress_percentage ?? 0)), 1);
            $hasCert = $courseId ? $issuedCertificates->has($courseId) : false;

            if ($pct < 100 && !$hasCert) continue;

            $course = is_array($prog) ? ($prog['course'] ?? null) : ($prog->course ?? null);
            if (!$course && $courseId) {
                $course = Course::find($courseId);
            }
            if (!$course) continue;

            $courseId = is_array($course) ? ($course['id'] ?? null) : $course->id;
            $courseTitle = is_array($course) ? ($course['title'] ?? '') : $course->title;
            $courseThumbnail = is_array($course) ? ($course['thumbnail'] ?? '') : ($course->thumbnail ?? '');
            $instructorName = is_array($course)
                ? ($course['user']['name'] ?? 'مدرب Skillso')
                : ($course->user->name ?? 'مدرب Skillso');

            $cert = $courseId ? $issuedCertificates->get($courseId) : null;

            $completed[] = [
                'id'                  => $courseId,
                'title'               => $courseTitle,
                'thumbnail'           => $courseThumbnail,
                'instructor'          => $instructorName,
                'progress_percentage' => 100.0,
                'completed_at'        => $cert?->issued_at?->toIso8601String() ?? now('UTC')->toIso8601String(),
                'has_certificate'     => !empty($cert),
                'certificate_number'  => $cert?->certificate_number,
                'certificate_url'     => $cert ? url("/api/certificate/public/{$cert->certificate_number}/download") : null,
            ];
        }

        return array_slice($completed, 0, 10);
    }

    private function resolveSavedCourses($user): array
    {
        $wishlists = Wishlist::where('user_id', $user->id)
            ->with(['course' => fn($q) => $q->with('user')])
            ->latest()
            ->limit(10)
            ->get();

        $saved = [];
        foreach ($wishlists as $w) {
            $course = $w->course;
            if (!$course) continue;

            $saved[] = [
                'id'            => $course->id,
                'wishlist_id'   => $w->id,
                'title'         => $course->title,
                'thumbnail'     => $course->thumbnail ?? '',
                'instructor'    => $course->user->name ?? 'مدرب Skillso',
                'is_free'       => (bool) ($course->is_free ?? false),
                'price'         => (float) ($course->price ?? 0),
                'is_wishlisted' => true,
                'saved_at'      => $w->created_at?->toIso8601String(),
            ];
        }

        return $saved;
    }

    private function resolveCertificates($user): array
    {
        $certs = CourseCertificate::where('user_id', $user->id)
            ->where('status', 'issued')
            ->with('course')
            ->latest('issued_at')
            ->limit(10)
            ->get();

        $output = [];
        foreach ($certs as $cert) {
            $course = $cert->course;
            $output[] = [
                'id'                 => $cert->id,
                'certificate_number' => $cert->certificate_number,
                'course_id'          => $cert->course_id,
                'course_title'       => $course->title ?? 'شهادة إتمام دورة',
                'thumbnail'          => $course->thumbnail ?? '',
                'issued_at'          => $cert->issued_at?->toIso8601String(),
                'download_url'       => url("/api/certificate/public/{$cert->certificate_number}/download"),
                'verification_url'   => url("/verify/{$cert->certificate_number}"),
            ];
        }

        return $output;
    }

    private function resolveLearningActivity($user, Collection $courseProgresses): array
    {
        $trackings = UserCurriculumTracking::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('model_type', CourseChapterLecture::class)
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $activity = [];
        foreach ($trackings as $t) {
            $lecture = CourseChapterLecture::with('chapter.course')->find($t->model_id);
            if (!$lecture) continue;

            $courseTitle = $lecture->chapter->course->title ?? 'دورة تدريبية';
            $activity[] = [
                'id'           => $t->id,
                'title'        => "أكملت: {$lecture->title}",
                'course_title' => $courseTitle,
                'time_spent'   => (int) ($t->time_spent ?? 0),
                'completed_at' => $t->completed_at?->toIso8601String() ?? $t->updated_at?->toIso8601String(),
            ];
        }

        return $activity;
    }
}
