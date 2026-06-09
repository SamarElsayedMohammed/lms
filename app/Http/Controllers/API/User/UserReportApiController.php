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
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate report: ' . $e->getMessage());
        }
    }

    /**
     * Get summary of learning progress
     */
    private function getLearningSummary(User $user)
    {
        // Enrolled courses (completed orders)
        $enrolledCourseIds = OrderCourse::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'completed');
        })->pluck('course_id')->unique()->toArray();

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
            'completed_courses' => $completedCoursesCount,
            'in_progress_courses' => $inProgressCoursesCount,
            'average_progress' => round($avgProgress, 2),
            'total_certificates' => $certificatesCount,
            'learning_hours' => $this->calculateLearningHours($user->id),
        ];
    }

    /**
     * Calculate total hours spent on lectures
     */
    private function calculateLearningHours($userId)
    {
        $completedLectureIds = UserCurriculumTracking::where('user_id', $userId)
            ->where('model_type', CourseChapterLecture::class)
            ->where('status', 'completed')
            ->pluck('model_id');

        $totalSeconds = DB::table('course_chapter_lectures')
            ->whereIn('id', $completedLectureIds)
            ->selectRaw('SUM((hours * 3600) + (minutes * 60) + seconds) as total_seconds')
            ->value('total_seconds');

        return round(($totalSeconds ?? 0) / 3600, 2);
    }

    /**
     * Calculate progress for a specific course
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

    /**
     * Get detailed report of enrolled courses
     */
    private function getEnrolledCoursesReport(User $user)
    {
        $orderCourses = OrderCourse::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'completed');
        })->with(['course.category', 'course.user'])->get();

        return $orderCourses->map(function ($oc) use ($user) {
            $course = $oc->course;
            if (!$course) return null;

            $progress = $this->calculateCourseProgress($user->id, $course->id);
            
            $hasReviewed = Rating::where('user_id', $user->id)
                ->where('rateable_id', $course->id)
                ->where('rateable_type', Course::class)
                ->exists();

            return [
                'course_id' => $course->id,
                'title' => $course->title,
                'category' => $course->category->name ?? 'N/A',
                'instructor' => $course->user->name ?? 'N/A',
                'enrolled_at' => $oc->created_at->toDateTimeString(),
                'progress_percentage' => round($progress, 2),
                'status' => $progress >= 100 ? 'completed' : ($progress > 0 ? 'in_progress' : 'not_started'),
                'is_reviewed' => $hasReviewed,
                'last_activity' => UserCurriculumTracking::where('user_id', $user->id)
                    ->where('course_id', $course->id)
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
            ->with(['quiz.course'])
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
                    'course_title' => $attempt->quiz->course->title ?? 'N/A',
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
            ->with(['course'])
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(function ($track) {
                $type = 'Activity';
                if (str_contains($track->model_type, 'Lecture')) $type = 'Lecture';
                elseif (str_contains($track->model_type, 'Quiz')) $type = 'Quiz';
                elseif (str_contains($track->model_type, 'Assignment')) $type = 'Assignment';

                return [
                    'activity' => 'Completed ' . $type,
                    'course_title' => $track->course->title ?? 'N/A',
                    'date' => $track->updated_at->toDateTimeString(),
                ];
            });
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

            $certificates = CourseCertificate::where('user_id', $user->id)
                ->with('course.category')
                ->latest('issued_date')
                ->get()
                ->map(function ($cert) {
                    return [
                        'id'                 => $cert->id,
                        'certificate_number' => $cert->certificate_number,
                        'status'             => $cert->status,  // 'active' | 'revoked'
                        'issued_date'        => $cert->issued_date?->format('Y-m-d'),
                        'course_id'          => $cert->course_id,
                        'course_title'       => $cert->course->title    ?? 'N/A',
                        'course_image'       => $cert->course->thumbnail ?? null,
                        'category'           => $cert->course->category->name ?? 'N/A',
                        // Frontend sends course_id to: POST /api/certificate/course/download
                        'can_download'       => $cert->isActive(),
                        'verify_url'         => url("/api/certificate/verify?code={$cert->certificate_number}"),
                    ];
                });

            return ApiResponseService::successResponse('User certificates retrieved successfully', $certificates);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to fetch certificates: ' . $e->getMessage());
        }
    }
}
