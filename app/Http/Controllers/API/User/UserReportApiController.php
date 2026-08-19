<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\Course\CourseCertificate;
use App\Models\Subscription;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Rating;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\UserEnrollmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserReportApiController extends Controller
{
    /**
     * Get lightweight learning stats for the authenticated student
     */
    public function getLearningStats(Request $request)
    {
        try {
            /** @var User|null $user */
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            $stats = app(\App\Services\StudentDashboardStatisticsService::class)->getDashboardStats($user);
            
            $summary = [
                'total_enrolled_courses' => $stats['total_courses'],
                'completed_courses'      => $stats['completed_courses'],
                'in_progress_courses'    => $stats['in_progress_courses'],
                'not_started_courses'    => $stats['not_started_courses'],
                'average_progress'       => $stats['average_progress'],
                'total_certificates'     => $stats['certificates'],
                'learning_hours'         => $stats['learning_hours'],
                'open_courses'           => $stats['open_courses'],
            ];

            return ApiResponseService::successResponse('Learning stats retrieved successfully', $summary);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve learning stats');
        }
    }




    /**
     * Get detailed report of enrolled courses
     */
    private function getEnrolledCoursesReport(User $user)
    {
        // Use UserEnrollmentService to resolve all sources (orders, tracks, subscription)
        $enrollmentService = app(UserEnrollmentService::class);
        $enrolled = $enrollmentService->resolveEnrolledCourses(
            (int) $user->id,
            static fn ($query) => $query->with(['category', 'user'])
        );

        return $enrolled->map(function ($item) use ($user) {
            $course = $item['course'];
            if (!$course) return null;

            $progress = (float) app(\App\Services\CourseProgressService::class)->getProgressWithCache($user->id, $course->id)->progress_percentage;

            $hasReviewed = Rating::where('user_id', $user->id)
                ->where('rateable_id', $course->id)
                ->where('rateable_type', Course::class)
                ->exists();

            return [
                'course_id'          => $course->id,
                'title'              => $course->title,
                'category'           => $course->category->name ?? 'N/A',
                'instructor'         => $course->user->name ?? 'N/A',
                'enrolled_at'        => $item['purchase_date']->toDateTimeString(),
                'enrollment_source'  => $item['source'],
                'progress_percentage'=> round($progress, 2),
                'status'             => $progress >= 100 ? 'completed' : ($progress > 0 ? 'in_progress' : 'not_started'),
                'is_reviewed'        => $hasReviewed,
                'last_activity'      => UserCurriculumTracking::where('user_id', $user->id)
                    ->whereHas('chapter', fn($q) => $q->where('course_id', $course->id))
                    ->latest('updated_at')
                    ->value('updated_at'),
            ];
        })->filter()->values();
    }

    /**
     * Get quiz performance report
     */
    private function getQuizPerformanceReport(User $user)
    {
        $attempts = UserQuizAttempt::where('user_id', $user->id)
            ->with(['quiz.chapter.course'])
            ->get();

        $totalAttempts = $attempts->count();
        $passedAttempts = $attempts->where('status', 'passed')->count();
        $avgScore = $attempts->avg('score');

        return [
            'total_attempts' => $totalAttempts,
            'passed_attempts' => $passedAttempts,
            'failed_attempts' => $totalAttempts - $passedAttempts,
            'average_score' => round($avgScore ?? 0, 2),
            'recent_attempts' => $attempts->sortByDesc('created_at')->take(5)->map(function ($attempt) {
                return [
                    'quiz_title' => $attempt->quiz->title ?? 'N/A',
                    'course_title' => $attempt->quiz->chapter->course->title ?? 'N/A',
                    'score' => $attempt->score,
                    'status' => $attempt->status,
                    'date' => $attempt->created_at->toDateTimeString(),
                ];
            })->values(),
        ];
    }

    /**
     * Get assignment summary
     */
    private function getAssignmentSummary(User $user)
    {
        $submissions = UserAssignmentSubmission::where('user_id', $user->id)->get();

        return [
            'total_submissions' => $submissions->count(),
            'pending_review' => $submissions->where('status', 'pending')->count(),
            'approved' => $submissions->where('status', 'accepted')->count(),
            'rejected' => $submissions->where('status', 'rejected')->count(),
        ];
    }

    /**
     * Get financial summary for the user
     */
    private function getFinancialSummary(User $user)
    {
        $totalSpent = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('final_price');

        $activeSubscription = Subscription::where('user_id', $user->id)
            ->active()
            ->with('plan')
            ->first();

        return [
            'total_spent' => round($totalSpent, 2),
            'wallet_balance' => round($user->wallet_balance ?? 0, 2),
            'has_active_subscription' => !empty($activeSubscription),
            'subscription_plan' => $activeSubscription->plan->name ?? null,
            'subscription_expires_at' => $activeSubscription->ends_at ?? null,
        ];
    }

    /**
     * Get webinar summary for the user
     */
    private function getWebinarSummary(User $user)
    {
        $registrations = WebinarRegistration::where('user_id', $user->id)
            ->with('webinar')
            ->get();

        $upcoming = $registrations->filter(function ($reg) {
            return $reg->webinar && $reg->webinar->start_at && $reg->webinar->start_at->isFuture();
        });

        $past = $registrations->filter(function ($reg) {
            return $reg->webinar && $reg->webinar->start_at && $reg->webinar->start_at->isPast();
        });

        $attended = $registrations->filter(function ($reg) {
            return (bool) $reg->attended || !is_null($reg->attended_at);
        });

        return [
            'total_registrations' => $registrations->count(),
            'upcoming_webinars' => $upcoming->count(),
            'past_webinars' => $past->count(),
            'attended_webinars' => $attended->count(),
            'recent_webinars' => $registrations->sortByDesc(function($reg) {
                return $reg->webinar->start_at ?? 0;
            })->take(5)->map(function ($reg) {
                return [
                    'title' => $reg->webinar->title ?? 'N/A',
                    'start_at' => $reg->webinar->start_at ?? null,
                    'instructor' => $reg->webinar->instructor->name ?? 'N/A',
                    'status' => $reg->webinar->status ?? 'N/A',
                ];
            })->values(),
        ];
    }

    /**
     * Get recent learning activities
     */
    private function getRecentActivities(User $user)
    {
        return UserCurriculumTracking::where('user_id', $user->id)
            ->with(['chapter.course'])
            ->latest('updated_at')
            ->limit(10)
            ->get()
            // Bug 6 fix: skip rows where the chapter or course has been deleted/orphaned
            // to prevent "N/A" appearing in the activity feed.
            ->filter(fn($track) => $track->chapter !== null && $track->chapter->course !== null)
            ->map(function ($track) {
                $type = 'Activity';
                if (str_contains($track->model_type, 'Lecture')) $type = 'Lecture';
                elseif (str_contains($track->model_type, 'Quiz')) $type = 'Quiz';
                elseif (str_contains($track->model_type, 'Assignment')) $type = 'Assignment';

                return [
                    'activity'     => 'Completed ' . $type,
                    'course_title' => $track->chapter->course->title,
                    'date'         => $track->updated_at->toDateTimeString(),
                ];
            })
            ->values();
    }

    /**
     * Get all earned certificates for the authenticated user.
     *
     * Returns only active certificates. Each item includes course_id
     * so the frontend can send it to POST /certificate/course/download.
     */
    public function getUserCertificates(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            // Only active records are valid issued certificates.
            $generatedCertificates = CourseCertificate::where('user_id', $user->id)
                ->active()
                ->with(['course.category', 'course.user'])
                ->latest('issued_date')
                ->get()
                ->keyBy('course_id');

            $result = [];

            $progressService = app(\App\Services\CourseProgressService::class);

            foreach ($generatedCertificates as $cert) {
                $progressPercentage = round(
                    (float) $progressService
                        ->getProgressWithCache((int) $user->id, (int) $cert->course_id)
                        ->progress_percentage,
                    2,
                );
                $eligibleByProgress = $progressPercentage >= 100;

                $result[] = [
                    'id'                 => $cert->id,
                    'is_issued'          => true,
                    'issuance_type'      => $eligibleByProgress ? 'automatic' : 'manual',
                    'eligible_by_progress' => $eligibleByProgress,
                    'course_id'          => $cert->course_id,
                    'slug'               => $cert->course->slug ?? '',
                    'title'              => $cert->course->title ?? 'N/A',
                    'thumbnail'          => $cert->course->thumbnail ?? '',
                    'author_name'        => $cert->instructor_name ?? ($cert->course->user->name ?? 'N/A'),
                    'course_title'       => $cert->course->title    ?? 'N/A',
                    'issued_at'          => optional($cert->created_at)->toIso8601String(),
                    'certificate_url'    => $cert->verification_url ?? url("/verify-certificate?code={$cert->certificate_number}"),
                    'verification_token' => $cert->verification_token,
                    'download_url'       => url("/api/certificate/public/{$cert->certificate_number}/download"),
                    'studentName'        => $cert->student_name ?? ($user->name ?? 'N/A'),
                    'arabicCourseTitle'  => $cert->arabic_title ?? ($cert->course->title ?? 'N/A'),
                    'englishCourseTitle' => $cert->english_title ?? ($cert->course->title ?? 'N/A'),
                    'date'               => optional($cert->issued_date)->format('Y-m-d'),
                    'instructorName'     => $cert->instructor_name ?? ($cert->course->user->name ?? 'N/A'),
                    'certificate_number' => $cert->certificate_number,
                    'progress_percentage'=> $progressPercentage,
                ];
            }

            // Completed enrolled courses without an active issued certificate are pending.
            $enrollmentService = app(\App\Services\UserEnrollmentService::class);
            $enrolled = $enrollmentService->resolveEnrolledCourses(
                (int) $user->id,
                static fn ($query) => $query->with(['category', 'user'])
            );

            foreach ($enrolled as $item) {
                $course = $item['course'];
                if (!$course) continue;

                if ($generatedCertificates->has($course->id)) {
                    continue;
                }

                $progressPercentage = round(
                    (float) $progressService
                        ->getProgressWithCache((int) $user->id, (int) $course->id)
                        ->progress_percentage,
                    2,
                );

                if ($progressPercentage >= 100) {
                    $result[] = [
                        'id'                 => null,
                        'is_issued'          => false,
                        'issuance_type'      => 'pending',
                        'eligible_by_progress' => true,
                        'course_id'          => $course->id,
                        'slug'               => $course->slug ?? '',
                        'title'              => $course->title ?? 'N/A',
                        'thumbnail'          => $course->thumbnail ?? '',
                        'author_name'        => $course->user->name ?? 'N/A',
                        'course_title'       => $course->title ?? 'N/A',
                        'issued_at'          => null,
                        'certificate_url'    => null,
                        'studentName'        => $user->name ?? 'N/A',
                        'arabicCourseTitle'  => $course->title ?? 'N/A',
                        'englishCourseTitle' => $course->title ?? 'N/A',
                        'date'               => null,
                        'instructorName'     => $course->user->name ?? 'N/A',
                        'certificate_number' => null,
                        'progress_percentage'=> $progressPercentage,
                    ];
                }
            }

            return response()->json([
                'ok'   => true,
                'data' => array_values($result)
            ], 200);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to fetch certificates');
        }
    }
}
