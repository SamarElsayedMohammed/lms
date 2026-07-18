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

            $summary = $this->getLearningSummary($user);
            $summary['not_started_courses'] = max(
                0,
                $summary['total_enrolled_courses'] - $summary['completed_courses'] - $summary['in_progress_courses'],
            );
            $summary['open_courses'] = max(0, $summary['total_enrolled_courses'] - $summary['completed_courses']);

            return ApiResponseService::successResponse('Learning stats retrieved successfully', $summary);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve learning stats: ' . $e->getMessage());
        }
    }

    /**
     * Get comprehensive report for a user
     */
    public function getComprehensiveReport(Request $request)
    {
        try {
            /** @var User $currentUser */
            $currentUser = Auth::user();

            if (!$currentUser) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            // Target user (self or specified by admin)
            $targetUser = $currentUser;
            if ($request->has('user_id') && ($currentUser->hasRole('Super Admin') || $currentUser->hasRole('Admin'))) {
                $targetUser = User::find($request->user_id);
                if (!$targetUser) {
                    return ApiResponseService::errorResponse('Target user not found');
                }
            }

            $data = [
                'user_info' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'learning_summary' => $this->getLearningSummary($targetUser),
                'enrolled_courses' => $this->getEnrolledCoursesReport($targetUser),
                'quiz_performance' => $this->getQuizPerformanceReport($targetUser),
                'assignment_summary' => $this->getAssignmentSummary($targetUser),
                'financial_summary' => $this->getFinancialSummary($targetUser),
                'webinar_summary' => $this->getWebinarSummary($targetUser),
                'recent_activities' => $this->getRecentActivities($targetUser),
                'generated_at' => Carbon::now()->toDateTimeString(),
            ];

            return ApiResponseService::successResponse('Comprehensive user report generated successfully', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate report: ' . $e->getMessage());
        }
    }

    /**
     * Get summary of learning progress
     */
    private function getLearningSummary(User $user)
    {
        // Use UserEnrollmentService to resolve all sources (orders, tracks, subscription)
        $enrollmentService = app(UserEnrollmentService::class);
        $enrolled = $enrollmentService->resolveEnrolledCourses((int) $user->id);
        $enrolledCourseIds = $enrolled->pluck('course_id')->toArray();

        $totalEnrolled = count($enrolledCourseIds);
        $completedCoursesCount = 0;
        $inProgressCoursesCount = 0;
        $totalProgress = 0;

        foreach ($enrolledCourseIds as $courseId) {
            $progress = $this->calculateCourseProgress($user->id, $courseId);
            $totalProgress += $progress;

            if ($progress >= 100) {
                $completedCoursesCount++;
            } elseif ($progress > 0) {
                $inProgressCoursesCount++;
            }
        }

        $avgProgress = $totalEnrolled > 0 ? $totalProgress / $totalEnrolled : 0;

        // Certificates
        $certificatesCount = CourseCertificate::where('user_id', $user->id)->count();

        return [
            'total_enrolled_courses' => $totalEnrolled,
            'completed_courses'      => $completedCoursesCount,
            'in_progress_courses'    => $inProgressCoursesCount,
            'average_progress'       => round($avgProgress, 2),
            'total_certificates'     => $certificatesCount,
            'learning_hours'         => $this->calculateLearningHours($user->id),
        ];
    }

    /**
     * Calculate total hours spent on lectures
     * Uses cumulative watched time (watched_seconds) from video_progress for accuracy.
     */
    private function calculateLearningHours($userId)
    {
        $totalSeconds = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->where('video_progress.user_id', $userId)
            ->where('course_chapter_lectures.is_active', 1)
            ->sum('video_progress.watched_seconds');

        return round(($totalSeconds ?? 0) / 3600, 2);
    }

    /**
     * Calculate progress for a specific course
     */
    private function calculateCourseProgress($userId, $courseId)
    {
        return (float) app(\App\Services\CourseProgressService::class)
            ->getProgressWithCache($userId, $courseId)
            ->progress_percentage;
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

            $progress = $this->calculateCourseProgress($user->id, $course->id);

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
            return $reg->webinar && $reg->webinar->start_at->isFuture();
        });

        $attended = $registrations->filter(function ($reg) {
            return $reg->webinar && $reg->webinar->start_at->isPast();
        });

        return [
            'total_registrations' => $registrations->count(),
            'upcoming_webinars' => $upcoming->count(),
            'past_webinars' => $attended->count(),
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

            // 1. Get already generated (issued) certificates
            $generatedCertificates = CourseCertificate::where('user_id', $user->id)
                ->with('course.category')
                ->latest('issued_date')
                ->get()
                ->keyBy('course_id');

            $result = [];

            // Add generated certificates first (these are fully issued)
            foreach ($generatedCertificates as $cert) {
                $progressPercentage = app(\App\Services\CourseProgressService::class)->getProgressWithCache($user->id, $cert->course_id)->progress_percentage ?? 100.0;

                $result[] = [
                    'id'                 => $cert->id,
                    'is_issued'          => true,   // Bug 1 fix: explicit flag so frontend never guesses
                    'course_id'          => $cert->course_id,
                    'course_title'       => $cert->course->title    ?? 'N/A',
                    'issued_at'          => optional($cert->created_at)->toIso8601String(),
                    'certificate_url'    => url("/verify-certificate?code={$cert->certificate_number}"),
                    'studentName'        => $cert->student_name ?? ($user->name ?? 'N/A'),
                    'arabicCourseTitle'  => $cert->arabic_title ?? ($cert->course->title ?? 'N/A'),
                    'englishCourseTitle' => $cert->english_title ?? ($cert->course->title ?? 'N/A'),
                    'date'               => optional($cert->issued_date)->format('Y-m-d'),
                    'instructorName'     => $cert->instructor_name ?? ($cert->course->user->name ?? 'N/A'),
                    // Bug 7 fix: use consistent snake_case key so the frontend adapter
                    // pickString(["certificate_number"]) finds the field correctly.
                    'certificate_number' => $cert->certificate_number,
                    'progress_percentage'=> $progressPercentage, // Fix Issue 4: Send actual progress
                ];
            }

            // 2. Find enrolled courses completed but not yet issued a certificate.
            //    These are shown as "Pending Issuance" — the student can trigger PDF
            //    generation from the UI which will create the CourseCertificate record.
            $enrollmentService = app(\App\Services\UserEnrollmentService::class);
            $enrolled = $enrollmentService->resolveEnrolledCourses(
                (int) $user->id,
                static fn ($query) => $query->with(['category', 'user'])
            );

            $certService  = app(\App\Services\CertificateService::class);
            $videoService = app(\App\Services\VideoProgressService::class);

            foreach ($enrolled as $item) {
                $course = $item['course'];
                if (!$course) continue;

                // Skip if already issued
                if ($generatedCertificates->has($course->id)) {
                    continue;
                }

                // Check completion (Bug 2 note: CertificateService is the correct authority
                // for certificate eligibility — it checks all required items including quizzes)
                $isCompleted  = $certService->checkCourseCompletionStatus($user->id, $course->id);
                $videoProgress = $videoService->getCourseProgress($user, $course);

                if ($isCompleted && $videoProgress >= \App\Services\VideoProgressService::COMPLETION_THRESHOLD) {
                    $progressPercentage = app(\App\Services\CourseProgressService::class)->getProgressWithCache($user->id, $course->id)->progress_percentage ?? 100.0;

                    $result[] = [
                        'id'                 => null,
                        'is_issued'          => false,  // Bug 1 fix: clearly NOT issued yet
                        'course_id'          => $course->id,
                        'course_title'       => $course->title ?? 'N/A',
                        'issued_at'          => null,
                        'certificate_url'    => null,
                        'studentName'        => $user->name ?? 'N/A',
                        'arabicCourseTitle'  => $course->title ?? 'N/A',
                        'englishCourseTitle' => $course->title ?? 'N/A',
                        'date'               => null,
                        'instructorName'     => $course->user->name ?? 'N/A',
                        'certificate_number' => null,   // Bug 7 fix: always snake_case
                        'progress_percentage'=> $progressPercentage, // Fix Issue 4: Send actual progress
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
            return ApiResponseService::errorResponse('Failed to fetch certificates: ' . $e->getMessage());
        }
    }
}
