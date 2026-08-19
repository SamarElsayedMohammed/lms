<?php

namespace App\Http\Controllers\API\Concerns;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseChapterLectureResource;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\QuizOption;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Models\Course\CourseDiscussion;
use App\Models\Course\CourseLanguage;
use App\Models\Course\UserCourseTrack;
use App\Models\CourseView;
use App\Models\FeatureSection;
use App\Models\HelpdeskQuestion;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Rating;
use App\Models\RefundRequest;
use App\Models\SearchHistory;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\CertificateService;
use App\Services\CourseProgressService;
use App\Services\EarningsService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\PricingCalculationService;
use App\Services\UserEnrollmentService;
use Carbon\Carbon;
use Exception;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

trait ServesInstructorCourseOps
{
    public function getAddedCourses(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "level" => "nullable|in:beginner,intermediate,advanced",
                "search" => "nullable|string|max:255",
                "sort_by" =>
                    "nullable|in:id,title,name,price,course_type,latest,created_at",
                "sort_order" => "nullable|in:asc,desc",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "course_type" => "nullable|in:free,paid",
                "status" => "nullable|in:draft,pending,publish,rejected",
                "is_active" => "nullable|in:0,1",
                "approval_status" => "nullable|in:approved,rejected,pending",
                "team_user_id" => "nullable|exists:users,id",
                "team_user_slug" => "nullable|exists:users,slug",
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Determine which user's courses to fetch
            $targetUserId = Auth::user()?->id; // Default to authenticated user
            $teamUser = null;
            $isTeamUserRequest = false;
            $isSelfAssignedCoursesRequest = false;

            // Check if team user is specified
            if (
                $request->filled("team_user_id") ||
                $request->filled("team_user_slug")
            ) {
                $isTeamUserRequest = true;

                if ($request->filled("team_user_id")) {
                    $teamUser = User::find($request->team_user_id);
                } elseif ($request->filled("team_user_slug")) {
                    $teamUser = User::where(
                        "slug",
                        $request->team_user_slug,
                    )->first();
                }

                if (!$teamUser) {
                    return ApiResponseService::errorResponse(
                        "Team user not found",
                    );
                }

                $authUser = Auth::user();

                // Check if team_user_slug is the authenticated user's own slug
                if ($teamUser->id === $authUser?->id) {
                    // User is requesting their own assigned courses (where they are instructor)
                    $isSelfAssignedCoursesRequest = true;
                } else {
                    // Check if there's a team relationship between auth user and team user
                    // Case 1: Team user is in auth user's team (auth user is the main instructor)
                    $authInstructorDetails =
                        $authUser->instructor_details ?? null;
                    $isTeamMember = false;

                    if ($authInstructorDetails) {
                        $isTeamMember = TeamMember::where(
                            "instructor_id",
                            $authInstructorDetails->id,
                        )
                            ->where("user_id", $teamUser->id)
                            ->where("status", "approved")
                            ->exists();
                    }

                    // Case 2: Auth user is in team user's team (team user is the main instructor, auth is invitor)
                    $isInvitor = false;
                    $teamUserInstructorDetails =
                        $teamUser->instructor_details ?? null;

                    if ($teamUserInstructorDetails) {
                        $isInvitor = TeamMember::where(
                            "instructor_id",
                            $teamUserInstructorDetails->id,
                        )
                            ->where("user_id", $authUser->id)
                            ->where("status", "approved")
                            ->exists();
                    }

                    // If neither relationship exists, return error
                    if (!$isTeamMember && !$isInvitor) {
                        return ApiResponseService::errorResponse(
                            "This user is not in your team",
                        );
                    }
                }

                // Debug: Log team user info and check courses
                Log::info("Team user courses query", [
                    "auth_user_id" => $authUser->id,
                    "team_user_id" => $teamUser->id,
                    "team_user_slug" => $teamUser->slug,
                    "courses_owned_by_auth" => Course::where(
                        "user_id",
                        $authUser->id,
                    )->count(),
                    "course_instructors_for_team_user" => DB::table(
                        "course_instructors",
                    )
                        ->where("user_id", $teamUser->id)
                        ->where("is_active", 1)
                        ->whereNull("deleted_at")
                        ->count(),
                    "matching_courses" => DB::table("courses")
                        ->join(
                            "course_instructors",
                            "course_instructors.course_id",
                            "=",
                            "courses.id",
                        )
                        ->where("courses.user_id", $authUser->id)
                        ->where("course_instructors.user_id", $teamUser->id)
                        ->where("course_instructors.is_active", 1)
                        ->whereNull("course_instructors.deleted_at")
                        ->count(),
                ]);
            }

            // Get course statistics
            $courseStats = $this->getCourseStatistics(
                $targetUserId,
                $isTeamUserRequest ? $teamUser : null,
            );

            // Build query based on whether it's a team user request
            if ($isSelfAssignedCoursesRequest && $teamUser) {
                // User is requesting their own assigned courses
                // Get course IDs where auth user is assigned as instructor (from any course owner)
                $courseIds = DB::table("course_instructors")
                    ->where("user_id", $teamUser->id)
                    ->whereNull("deleted_at")
                    ->pluck("course_id")
                    ->toArray();

                // Get ALL courses where auth user is assigned as instructor
                $query = Course::whereIn("id", $courseIds)->with([
                    "category",
                    "chapters.lectures",
                    "chapters.quizzes",
                    "chapters.assignments",
                ]);
            } elseif ($isTeamUserRequest && $teamUser) {
                $authUser = Auth::user();

                // Check if auth user is invitor (team_user is main instructor, auth is in their team)
                $teamUserInstructorDetails =
                    $teamUser->instructor_details ?? null;
                $isInvitor = false;

                if ($teamUserInstructorDetails) {
                    $isInvitor = TeamMember::where(
                        "instructor_id",
                        $teamUserInstructorDetails->id,
                    )
                        ->where("user_id", $authUser?->id)
                        ->where("status", "approved")
                        ->exists();
                }

                if ($isInvitor) {
                    // Auth user is invitor: fetch courses owned by team_user and assigned to auth
                    // course_instructors.user_id = auth user
                    // courses.user_id = team_user
                    $courseIds = DB::table("course_instructors")
                        ->where("user_id", $authUser?->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    // Get courses owned by team_user AND assigned to auth user
                    $query = Course::where("user_id", $teamUser->id)
                        ->whereIn("id", $courseIds)
                        ->with([
                            "category",
                            "chapters.lectures",
                            "chapters.quizzes",
                            "chapters.assignments",
                        ]);
                } else {
                    // Auth user is main instructor: fetch courses owned by auth and assigned to team_user
                    // course_instructors.user_id = team_user
                    // courses.user_id = auth user
                    $courseIds = DB::table("course_instructors")
                        ->where("user_id", $teamUser->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    // Get courses owned by auth user AND assigned to team user
                    $query = Course::where("user_id", $authUser?->id)
                        ->whereIn("id", $courseIds)
                        ->with([
                            "category",
                            "chapters.lectures",
                            "chapters.quizzes",
                            "chapters.assignments",
                        ]);
                }
            } else {
                // Default: get courses owned by authenticated user
                $query = Course::where("user_id", $targetUserId)->with([
                    "category",
                    "chapters.lectures",
                    "chapters.quizzes",
                    "chapters.assignments",
                ]);
            }

            if ($request->id) {
                $query->where("id", $request->id);
            }

            if ($request->filled("level")) {
                $query->where("level", $request->level);
            }

            if ($request->filled("course_type")) {
                $query->where("course_type", $request->course_type);
            }

            if ($request->filled("status")) {
                if ($request->status === "rejected") {
                    // Filter for courses that are either status=rejected OR approval_status=rejected
                    $query->where(static function ($q): void {
                        $q->where("status", "rejected")->orWhere(
                            "approval_status",
                            "rejected",
                        );
                    });
                } else {
                    $query->where("status", $request->status);
                }
            }

            if ($request->filled("is_active")) {
                $query->where("is_active", (bool) $request->is_active);
            }

            if ($request->filled("approval_status")) {
                if ($request->approval_status === "pending") {
                    $query->whereNull("approval_status");
                } else {
                    $query->where("approval_status", $request->approval_status);
                }
            }

            if ($request->filled("search")) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q->where("title", "LIKE", "%{$search}%")
                        ->orWhere("short_description", "LIKE", "%{$search}%")
                        ->orWhere("level", "LIKE", "%{$search}%")
                        ->orWhereHas("language", static function (
                            $langQuery,
                        ) use ($search): void {
                            $langQuery->where("name", "LIKE", "%{$search}%");
                        })
                        ->orWhereHas("category", static function (
                            $categoryQuery,
                        ) use ($search): void {
                            $categoryQuery->where(
                                "name",
                                "LIKE",
                                "%{$search}%",
                            );
                        })
                        ->orWhereHas("tags", static function ($tagQuery) use (
                            $search,
                        ): void {
                            $tagQuery->where("tag", "LIKE", "%{$search}%");
                        });
                });
            }

            $sortField = $request->sort_by ?? "id";
            $sortOrder = $request->sort_order ?? "desc";

            // Map aliases to actual database columns
            if ($sortField === "latest") {
                $sortField = "created_at";
                $sortOrder = "desc";
            } elseif ($sortField === "name") {
                $sortField = "title";
            }

            $query->orderBy($sortField, $sortOrder);

            $perPage = $request->per_page ?? 15;
            $courses = $query->paginate($perPage);

            if ($courses->isEmpty()) {
                ApiResponseService::validationError("No Courses Found");
            }

            // Transform courses to include only required fields
            $transformedCourses = $courses
                ->getCollection()
                ->map(static function ($course) {
                    // Calculate total chapter count
                    $totalChapterCount = $course->chapters->count();

                    // Calculate total lesson count (lectures + quizzes + assignments)
                    $totalLessons = $course->chapters->sum(
                        static fn($chapter) => $chapter->lectures->count() +
                            $chapter->quizzes->count() +
                            $chapter->assignments->count(),
                    );

                    // Get total enrolled students
                    $totalEnrolledStudents = OrderCourse::whereHas(
                        "order",
                        static function ($q): void {
                            $q->where("status", "completed");
                        },
                    )
                        ->where("course_id", $course->id)
                        ->count();

                    // Get rating information
                    $ratings = \App\Models\Rating::where(
                        "rateable_type",
                        Course::class,
                    )
                        ->where("rateable_id", $course->id)
                        ->get();

                    $averageRating = $ratings->avg("rating") ?? 0;
                    $ratingCount = $ratings->count();

                    // Set status and approval_status based on each other
                    $displayStatus = $course->status;
                    $displayApprovalStatus = $course->approval_status;

                    // If approval_status is rejected, status should be rejected
                    if ($course->approval_status === "rejected") {
                        $displayStatus = "rejected";
                    }

                    // If status is rejected, approval_status should be rejected
                    if ($course->status === "rejected") {
                        $displayApprovalStatus = "rejected";
                    }

                    return [
                        "id" => $course->id,
                        "title" => $course->title,
                        "slug" => $course->slug,
                        "thumbnail" => $course->thumbnail, // Accessor already returns full URL via FileService::getFileUrl
                        "category" => [
                            "id" => $course->category->id ?? null,
                            "name" => $course->category->name ?? null,
                        ],
                        "total_chapter_count" => $totalChapterCount,
                        "total_lesson_count" => $totalLessons,
                        "price" => $course->price,
                        "discount_price" => $course->discount_price,
                        "total_enrolled_students" => $totalEnrolledStudents,
                        "average_rating" => round($averageRating, 1),
                        "rating_count" => $ratingCount,
                        "status" => $displayStatus,
                        "is_active" => $course->is_active,
                        "approval_status" => $displayApprovalStatus,
                        "created_at" => $course->created_at,
                        "updated_at" => $course->updated_at,
                    ];
                });

            // Update the pagination collection
            $courses->setCollection($transformedCourses);

            // Get target user information
            $targetUser = User::find($targetUserId);
            $isOwnCourses = !$isTeamUserRequest; // If it's not a team user request, it's own courses

            // Prepare response with statistics and transformed courses
            $responseData = [
                "statistics" => $courseStats,
                "courses" => $courses,
                "target_user" => [
                    "id" => $targetUser->id,
                    "name" => $targetUser->name,
                    "email" => $targetUser->email,
                    "slug" => $targetUser->slug,
                    "is_own_courses" => $isOwnCourses,
                ],
            ];

            // Add team user info if it's a team user request
            if ($isTeamUserRequest && $teamUser) {
                $responseData["team_user"] = [
                    "id" => $teamUser->id,
                    "name" => $teamUser->name,
                    "email" => $teamUser->email,
                    "slug" => $teamUser->slug,
                ];
            }

            $message = $isOwnCourses
                ? "Your courses retrieved successfully"
                : "Courses where team member is assigned as instructor retrieved successfully";
            ApiResponseService::successResponse($message, $responseData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getAddedCourses Method",
            );
            ApiResponseService::errorResponse();
        }
    }

    /**
     * Get enrolled students for a course with pagination
     */
    public function getCourseEnrolledStudents(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|exists:courses,slug",
                "course_slug" => "nullable|string|exists:courses,slug",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            if ($request->filled("course_id") && !$request->filled("id")) {
                $request->merge(["id" => $request->course_id]);
            }

            if ($request->filled("course_slug") && !$request->filled("slug")) {
                $request->merge(["slug" => $request->course_slug]);
            }

            // Get course by ID or slug
            $courseQuery = Course::query();
            if ($request->filled("id")) {
                $course = $courseQuery->where("id", $request->id)->first();
            } elseif ($request->filled("slug")) {
                $course = $courseQuery->where("slug", $request->slug)->first();
            } else {
                return ApiResponseService::validationError(
                    "Course id or slug is required",
                );
            }

            if (!$course) {
                return ApiResponseService::validationError("Course not found");
            }

            // Check if user is the instructor of this course or assigned as instructor
            $user = Auth::user();
            $isOwner = $course->user_id == $user?->id;

            // Check if user is assigned as instructor in course_instructors table
            $isAssignedInstructor = false;
            if (!$isOwner) {
                $isAssignedInstructor = DB::table("course_instructors")
                    ->where("course_id", $course->id)
                    ->where("user_id", $user->id)
                    ->whereNull("deleted_at")
                    ->exists();
            }

            if (!$isOwner && !$isAssignedInstructor) {
                return ApiResponseService::unauthorizedResponse(
                    "You are not authorized to view this course data",
                );
            }

            // Get pagination parameters
            $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
            $page = max(1, $request->get("page", 1)); // Ensure page is at least 1

            // Get enrolled students with progress data
            $enrolledStudents = $this->getEnrolledStudentsWithProgress(
                $course->id,
                $perPage,
                $page,
            );

            return ApiResponseService::successResponse(
                "Enrolled students retrieved successfully",
                $enrolledStudents,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Something went wrong: " . $e->getMessage(),
            );
        }
    }

    /**
     * Get enrolled students with progress data and pagination
     */
    private function getEnrolledStudentsWithProgress(
        $courseId,
        $perPage = 10,
        $page = 1,
    ) {
        // Get enrolled students with their enrollment date
        $enrolledStudents = User::whereHas(
            "orders.orderCourses",
            static function ($query) use ($courseId): void {
                $query
                    ->where("course_id", $courseId)
                    ->whereHas("order", static function ($orderQuery): void {
                        $orderQuery->where("status", "completed");
                    });
            },
        )
            ->with([
                "orders" => static function ($query) use ($courseId): void {
                    $query
                        ->whereHas("orderCourses", static function (
                            $orderCourseQuery,
                        ) use ($courseId): void {
                            $orderCourseQuery->where("course_id", $courseId);
                        })
                        ->where("status", "completed");
                },
            ])
            ->get();

        // Calculate progress for each student
        $studentsWithProgress = $enrolledStudents->map(function ($student) use (
            $courseId,
        ) {
            $enrollmentDate = $student->orders->first()->created_at;
            $progressPercentage = $this->calculateStudentProgress(
                $student->id,
                $courseId,
            );

            return [
                "id" => $student->id,
                "name" => $student->name,
                "email" => $student->email,
                "profile" => $student->profile ?? null,
                "enrolled_at" => $enrollmentDate
                    ? $enrollmentDate->format("d F, Y")
                    : null,
                "progress_percentage" => $progressPercentage,
            ];
        });

        // Apply pagination manually
        $total = $studentsWithProgress->count();
        $offset = ($page - 1) * $perPage;
        $paginatedStudents = $studentsWithProgress
            ->slice($offset, $perPage)
            ->values();

        return $this->replacePaginationFormat(
            $paginatedStudents,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get assignment details for a course with pagination
     */
    public function getCourseAssignmentDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|exists:courses,slug",
                "course_slug" => "nullable|string|exists:courses,slug",
                "team_user_slug" => "nullable|string|exists:users,slug",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "search" => "nullable|string|max:255",
                "filter" => "nullable|in:all,this_week,this_month",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $user = Auth::user();
            $instructorId = $user?->id;

            // Check if team_user_slug is provided
            if ($request->filled("team_user_slug")) {
                // Get team user by slug
                $teamUser = User::where(
                    "slug",
                    $request->team_user_slug,
                )->first();

                if (!$teamUser) {
                    return ApiResponseService::validationError(
                        "Team user not found",
                    );
                }

                // Check team relationship in both directions
                $authInstructorDetails = $user->instructor_details ?? null;
                $isTeamMember = false;
                $isInvitor = false;

                if ($authInstructorDetails) {
                    $isTeamMember = TeamMember::where(
                        "instructor_id",
                        $authInstructorDetails->id,
                    )
                        ->where("user_id", $teamUser->id)
                        ->where("status", "approved")
                        ->exists();
                }

                $teamUserInstructorDetails =
                    $teamUser->instructor_details ?? null;
                if ($teamUserInstructorDetails) {
                    $isInvitor = TeamMember::where(
                        "instructor_id",
                        $teamUserInstructorDetails->id,
                    )
                        ->where("user_id", $user->id)
                        ->where("status", "approved")
                        ->exists();
                }

                if (!$isTeamMember && !$isInvitor) {
                    return ApiResponseService::unauthorizedResponse(
                        "You are not authorized to view this team data",
                    );
                }

                // Get courses based on relationship
                if ($isInvitor) {
                    // Auth is invitor: Get courses owned by team_user and assigned to auth
                    $assignedCourseIds = DB::table("course_instructors")
                        ->where("user_id", $user->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    $courses = Course::where("user_id", $teamUser->id)
                        ->whereIn("id", $assignedCourseIds)
                        ->pluck("id")
                        ->toArray();
                } else {
                    // Auth is main instructor: Get courses owned by auth and assigned to team_user
                    $assignedCourseIds = DB::table("course_instructors")
                        ->where("user_id", $teamUser->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    $courses = Course::where("user_id", $user->id)
                        ->whereIn("id", $assignedCourseIds)
                        ->pluck("id")
                        ->toArray();
                }

                if (empty($courses)) {
                    return ApiResponseService::successResponse(
                        "No courses found for this team",
                        $this->replacePaginationFormat([], 1, 10, 0),
                    );
                }

                // Get pagination parameters
                $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                $search = $request->get("search", "");
                $filter = $request->get("filter", "all");

                // Get assignment details for all team courses
                $assignmentDetails = $this->getTeamAssignmentDetailsWithPagination(
                    $courses,
                    $perPage,
                    $page,
                    $search,
                    $filter,
                );

                return ApiResponseService::successResponse(
                    "Team assignment details retrieved successfully",
                    $assignmentDetails,
                );
            } else {
                // Check if no specific course or team is provided
                if (
                    !$request->filled("id") &&
                    !$request->filled("slug") &&
                    !$request->filled("team_user_slug")
                ) {
                    // Get all courses belonging to the instructor
                    $instructorCourses = Course::where("user_id", $instructorId)
                        ->pluck("id")
                        ->toArray();

                    if (empty($instructorCourses)) {
                        return ApiResponseService::successResponse(
                            "No courses found for this instructor",
                            $this->replacePaginationFormat([], 1, 10, 0),
                        );
                    }

                    // Get pagination parameters
                    $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                    $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                    $search = $request->get("search", "");
                    $filter = $request->get("filter", "all");

                    // Get assignment details for all instructor's courses
                    $assignmentDetails = $this->getTeamAssignmentDetailsWithPagination(
                        $instructorCourses,
                        $perPage,
                        $page,
                        $search,
                        $filter,
                    );

                    return ApiResponseService::successResponse(
                        "Instructor assignment details retrieved successfully",
                        $assignmentDetails,
                    );
                }

                // Original logic for single course access
                // Get course by ID or slug
                $courseQuery = Course::query();
                if ($request->filled("id")) {
                    $course = $courseQuery->where("id", $request->id)->first();
                } elseif ($request->filled("slug")) {
                    $course = $courseQuery
                        ->where("slug", $request->slug)
                        ->first();
                }

                if (!$course) {
                    return ApiResponseService::validationError(
                        "Course not found",
                    );
                }

                // Check if user is the instructor of this course or assigned as instructor
                $isOwner = $course->user_id == $instructorId;
                $isAssignedInstructor = false;

                if (!$isOwner) {
                    $isAssignedInstructor = DB::table("course_instructors")
                        ->where("course_id", $course->id)
                        ->where("user_id", $instructorId)
                        ->whereNull("deleted_at")
                        ->exists();
                }

                if (!$isOwner && !$isAssignedInstructor) {
                    return ApiResponseService::unauthorizedResponse(
                        "You are not authorized to view this course data",
                    );
                }

                // Get pagination parameters
                $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                $search = $request->get("search", "");
                $filter = $request->get("filter", "all");

                // Get assignment details with pagination
                $assignmentDetails = $this->getAssignmentDetailsWithPagination(
                    $course->id,
                    $perPage,
                    $page,
                    $search,
                    $filter,
                );

                return ApiResponseService::successResponse(
                    "Assignment details retrieved successfully",
                    $assignmentDetails,
                );
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Something went wrong: " . $e->getMessage(),
            );
        }
    }

    /**
     * Get team assignment details with pagination and search
     */
    private function getTeamAssignmentDetailsWithPagination(
        $courseIds,
        $perPage = 10,
        $page = 1,
        $search = "",
        $filter = "all",
    ) {
        // Get all assignments for the team courses with their relationships
        $assignmentsQuery = CourseChapterAssignment::whereHas(
            "chapter",
            static function ($query) use ($courseIds): void {
                $query->whereIn("course_id", $courseIds);
            },
        )
            ->with([
                "chapter" => static function ($query): void {
                    $query->select("id", "title", "course_id");
                },
                "chapter.course" => static function ($query): void {
                    $query->select("id", "title", "slug");
                },
                "chapter.lectures" => static function ($query): void {
                    $query
                        ->select("id", "title", "course_chapter_id")
                        ->orderBy("chapter_order");
                },
            ])
            ->orderBy("chapter_order");

        // Apply search filter
        if (!empty($search)) {
            $assignmentsQuery->where(static function ($query) use (
                $search,
            ): void {
                $query
                    ->where("title", "like", "%" . $search . "%")
                    ->orWhere("description", "like", "%" . $search . "%")
                    ->orWhereHas("chapter", static function (
                        $chapterQuery,
                    ) use ($search): void {
                        $chapterQuery->where(
                            "title",
                            "like",
                            "%" . $search . "%",
                        );
                    })
                    ->orWhereHas("chapter.course", static function (
                        $courseQuery,
                    ) use ($search): void {
                        $courseQuery->where(
                            "title",
                            "like",
                            "%" . $search . "%",
                        );
                    })
                    ->orWhereHas("chapter.lectures", static function (
                        $lectureQuery,
                    ) use ($search): void {
                        $lectureQuery->where(
                            "title",
                            "like",
                            "%" . $search . "%",
                        );
                    });
            });
        }

        // Apply time-based filter
        if ($filter === "this_week") {
            $assignmentsQuery->where("created_at", ">=", now()->startOfWeek());
        } elseif ($filter === "this_month") {
            $assignmentsQuery->where("created_at", ">=", now()->startOfMonth());
        }
        // 'all' filter doesn't need any additional conditions

        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $assignmentsQuery)->count();
        $allAssignments = $assignmentsQuery->forPage($page, $perPage)->get();

        $paginatedAssignments = $allAssignments->map(static function (
            $assignment,
            $index,
        ) {
            $firstLecture = $assignment->chapter->lectures->first();

            return [
                "id" => $assignment->id,
                "assignment_name" => $assignment->title,
                "assignment_slug" => $assignment->slug,
                "chapter_name" => $assignment->chapter->title,
                "course_name" => $assignment->chapter->course->title,
                "course_slug" => $assignment->chapter->course->slug,
                "lecture_name" => $firstLecture
                    ? $firstLecture->title
                    : "No Lecture",
                "total_points" => (int) $assignment->points,
                "description" => $assignment->description,
                "instructions" => $assignment->instructions,
                "can_skip" => $assignment->can_skip,
                "is_active" => $assignment->is_active,
                "created_at" => $assignment->created_at,
                "updated_at" => $assignment->updated_at,
            ];
        })->values();

        return $this->replacePaginationFormat(
            $paginatedAssignments,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get assignment details with pagination and search
     */
    private function getAssignmentDetailsWithPagination(
        $courseId,
        $perPage = 10,
        $page = 1,
        $search = "",
        $filter = "all",
    ) {
        // Get all assignments for the course with their relationships
        $assignmentsQuery = CourseChapterAssignment::whereHas(
            "chapter",
            static function ($query) use ($courseId): void {
                $query->where("course_id", $courseId);
            },
        )
            ->with([
                "chapter" => static function ($query): void {
                    $query->select("id", "title", "course_id");
                },
                "chapter.course" => static function ($query): void {
                    $query->select("id", "title", "slug");
                },
                "chapter.lectures" => static function ($query): void {
                    $query
                        ->select("id", "title", "course_chapter_id")
                        ->orderBy("chapter_order");
                },
            ])
            ->orderBy("chapter_order");

        // Apply search filter
        if (!empty($search)) {
            $assignmentsQuery->where(static function ($query) use (
                $search,
            ): void {
                $query
                    ->where("title", "like", "%" . $search . "%")
                    ->orWhere("description", "like", "%" . $search . "%")
                    ->orWhereHas("chapter", static function (
                        $chapterQuery,
                    ) use ($search): void {
                        $chapterQuery->where(
                            "title",
                            "like",
                            "%" . $search . "%",
                        );
                    })
                    ->orWhereHas("chapter.lectures", static function (
                        $lectureQuery,
                    ) use ($search): void {
                        $lectureQuery->where(
                            "title",
                            "like",
                            "%" . $search . "%",
                        );
                    });
            });
        }

        // Apply time-based filter
        if ($filter === "this_week") {
            $assignmentsQuery->where("created_at", ">=", now()->startOfWeek());
        } elseif ($filter === "this_month") {
            $assignmentsQuery->where("created_at", ">=", now()->startOfMonth());
        }
        // 'all' filter doesn't need any additional conditions

        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $assignmentsQuery)->count();
        $allAssignments = $assignmentsQuery->forPage($page, $perPage)->get();

        $paginatedAssignments = $allAssignments->map(static function (
            $assignment,
            $index,
        ) {
            $firstLecture = $assignment->chapter->lectures->first();

            return [
                "id" => $assignment->id,
                "assignment_name" => $assignment->title,
                "assignment_slug" => $assignment->slug,
                "chapter_name" => $assignment->chapter->title,
                "course_name" => $assignment->chapter->course->title,
                "course_slug" => $assignment->chapter->course->slug,
                "total_points" => (int) $assignment->points,
                "description" => $assignment->description,
                "instructions" => $assignment->instructions,
                "can_skip" => $assignment->can_skip,
                "is_active" => $assignment->is_active,
                "created_at" => $assignment->created_at,
                "updated_at" => $assignment->updated_at,
            ];
        })->values();

        return $this->replacePaginationFormat(
            $paginatedAssignments,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Update assignment submission status
     */
    public function updateAssignmentStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "submission_id" =>
                    "required|exists:user_assignment_submissions,id",
                "status" => "required|in:accepted,rejected",
                "points" => "nullable|numeric|min:0",
                "feedback" => "nullable|string|max:1000",
                "comment" => "nullable|string|max:1000",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole("Instructor")) {
                return ApiResponseService::unauthorizedResponse(
                    "Only instructors can update assignment submissions.",
                );
            }

            // Get submission and verify it belongs to instructor's course
            $submission = UserAssignmentSubmission::with([
                "assignment.chapter.course",
            ])
                ->where("id", $request->submission_id)
                ->whereHas("assignment.chapter.course", static function (
                    $courseQuery,
                ) use ($instructorId): void {
                    $courseQuery->where("user_id", $instructorId);
                })
                ->first();

            if (!$submission) {
                return ApiResponseService::validationError(
                    "Assignment submission not found or you do not have permission to update it",
                );
            }

            // Prepare update data
            $updateData = [
                "status" => $request->status,
            ];

            // Add points if provided and status is accepted
            if ($request->status === "accepted" && $request->has("points")) {
                $updateData["points"] = $request->points;
            }

            // Add rejection reason if status is rejected
            if ($request->status === "rejected" && $request->has("feedback")) {
                $updateData["feedback"] = $request->feedback;
            }

            // Add comment if provided
            if ($request->has("comment")) {
                $updateData["comment"] = $request->comment;
            }

            $submission->update($updateData);

            // Load updated submission with relationships
            $submission->load([
                "user:id,name,email",
                "assignment.chapter.course:id,title",
                "files",
            ]);

            $response = [
                "id" => $submission->id,
                "user" => [
                    "id" => $submission->user->id,
                    "name" => $submission->user->name,
                    "email" => $submission->user->email,
                ],
                "assignment" => [
                    "id" => $submission->assignment->id,
                    "title" => $submission->assignment->title,
                    "max_points" => $submission->assignment->points,
                ],
                "course" => [
                    "id" => $submission->assignment->chapter->course->id,
                    "title" => $submission->assignment->chapter->course->title,
                ],
                "status" => $submission->status,
                "points" => $submission->points,
                "feedback" => $submission->feedback,
                "comment" => $submission->comment,
                "updated_at" => $submission->updated_at,
            ];

            return ApiResponseService::successResponse(
                "Assignment submission updated successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Failed to update assignment submission: " . $e->getMessage(),
            );
        }
    }

    /**
     * Get assignment submissions for a course
     */
    public function getCourseAssignmentSubmissions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|exists:courses,slug",
                "course_slug" => "nullable|string|exists:courses,slug",
                "team_user_slug" => "nullable|string|exists:users,slug",
                "assignment_id" =>
                    "nullable|exists:course_chapter_assignments,id",
                "assignment_slug" =>
                    "nullable|string|exists:course_chapter_assignments,slug",
                "status" =>
                    "nullable|in:pending,submitted,accepted,rejected,suspended",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "search" => "nullable|string|max:255",
            ]);

            // Custom validation: at least one of id, slug, or team_user_slug should be provided, or none (for instructor's all courses)
            if (
                !$request->filled("id") &&
                !$request->filled("slug") &&
                !$request->filled("team_user_slug")
            ) {
                // This is allowed - will fetch all instructor's courses
            } elseif ($request->filled("id") && $request->filled("slug")) {
                return ApiResponseService::validationError(
                    "Please provide either course id or slug, not both",
                );
            } elseif (
                $request->filled("id") &&
                $request->filled("team_user_slug")
            ) {
                return ApiResponseService::validationError(
                    "Please provide either course id or team_user_slug, not both",
                );
            } elseif (
                $request->filled("slug") &&
                $request->filled("team_user_slug")
            ) {
                return ApiResponseService::validationError(
                    "Please provide either course slug or team_user_slug, not both",
                );
            }

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $user = Auth::user();
            $instructorId = $user?->id;

            // Check if team_user_slug is provided
            if ($request->filled("team_user_slug")) {
                // Get team user by slug
                $teamUser = User::where(
                    "slug",
                    $request->team_user_slug,
                )->first();

                if (!$teamUser) {
                    return ApiResponseService::validationError(
                        "Team user not found",
                    );
                }

                // Check team relationship in both directions
                $authInstructorDetails = $user->instructor_details ?? null;
                $isTeamMember = false;
                $isInvitor = false;

                if ($authInstructorDetails) {
                    $isTeamMember = TeamMember::where(
                        "instructor_id",
                        $authInstructorDetails->id,
                    )
                        ->where("user_id", $teamUser->id)
                        ->where("status", "approved")
                        ->exists();
                }

                $teamUserInstructorDetails =
                    $teamUser->instructor_details ?? null;
                if ($teamUserInstructorDetails) {
                    $isInvitor = TeamMember::where(
                        "instructor_id",
                        $teamUserInstructorDetails->id,
                    )
                        ->where("user_id", $user->id)
                        ->where("status", "approved")
                        ->exists();
                }

                if (!$isTeamMember && !$isInvitor) {
                    return ApiResponseService::unauthorizedResponse(
                        "You are not authorized to view this team data",
                    );
                }

                // Get courses based on relationship
                if ($isInvitor) {
                    // Auth is invitor: Get courses owned by team_user and assigned to auth
                    $assignedCourseIds = DB::table("course_instructors")
                        ->where("user_id", $user->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    $courses = Course::where("user_id", $teamUser->id)
                        ->whereIn("id", $assignedCourseIds)
                        ->pluck("id")
                        ->toArray();
                } else {
                    // Auth is main instructor: Get courses owned by auth and assigned to team_user
                    $assignedCourseIds = DB::table("course_instructors")
                        ->where("user_id", $teamUser->id)
                        ->whereNull("deleted_at")
                        ->pluck("course_id")
                        ->toArray();

                    $courses = Course::where("user_id", $user->id)
                        ->whereIn("id", $assignedCourseIds)
                        ->pluck("id")
                        ->toArray();
                }

                if (empty($courses)) {
                    return ApiResponseService::successResponse(
                        "No courses found for this team",
                        $this->replacePaginationFormat([], 1, 10, 0),
                    );
                }

                // Get pagination parameters
                $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                $search = $request->get("search", "");

                // Handle assignment_id or assignment_slug
                $assignmentId = null;
                if ($request->filled("assignment_id")) {
                    $assignmentId = $request->get("assignment_id");
                } elseif ($request->filled("assignment_slug")) {
                    $assignment = CourseChapterAssignment::where(
                        "slug",
                        $request->get("assignment_slug"),
                    )->first();
                    if ($assignment) {
                        $assignmentId = $assignment->id;
                    } else {
                        return ApiResponseService::validationError(
                            "Assignment not found",
                        );
                    }
                }

                $status = $request->get("status");

                // Get assignment submissions for all team courses
                $submissions = $this->getTeamAssignmentSubmissionsWithPagination(
                    $courses,
                    $perPage,
                    $page,
                    $search,
                    $assignmentId,
                    $status,
                );

                return ApiResponseService::successResponse(
                    "Team assignment submissions retrieved successfully",
                    $submissions,
                );
            } else {
                // Check if no specific course or team is provided
                if (
                    !$request->filled("id") &&
                    !$request->filled("slug") &&
                    !$request->filled("team_user_slug")
                ) {
                    // Get all courses belonging to the instructor
                    $instructorCourses = Course::where("user_id", $instructorId)
                        ->pluck("id")
                        ->toArray();

                    if (empty($instructorCourses)) {
                        return ApiResponseService::successResponse(
                            "No courses found for this instructor",
                            $this->replacePaginationFormat([], 1, 10, 0),
                        );
                    }

                    // Get pagination parameters
                    $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                    $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                    $search = $request->get("search", "");

                    // Handle assignment_id or assignment_slug
                    $assignmentId = null;
                    if ($request->filled("assignment_id")) {
                        $assignmentId = $request->get("assignment_id");
                    } elseif ($request->filled("assignment_slug")) {
                        $assignment = CourseChapterAssignment::where(
                            "slug",
                            $request->get("assignment_slug"),
                        )->first();
                        if ($assignment) {
                            $assignmentId = $assignment->id;
                        } else {
                            return ApiResponseService::validationError(
                                "Assignment not found",
                            );
                        }
                    }

                    $status = $request->get("status");

                    // Get assignment submissions for all instructor's courses
                    $submissions = $this->getTeamAssignmentSubmissionsWithPagination(
                        $instructorCourses,
                        $perPage,
                        $page,
                        $search,
                        $assignmentId,
                        $status,
                    );

                    return ApiResponseService::successResponse(
                        "Instructor assignment submissions retrieved successfully",
                        $submissions,
                    );
                }

                // Original logic for single course access
                // Get course by ID or slug
                $courseQuery = Course::query();
                if ($request->filled("id")) {
                    $course = $courseQuery->where("id", $request->id)->first();
                } elseif ($request->filled("slug")) {
                    $course = $courseQuery
                        ->where("slug", $request->slug)
                        ->first();
                }

                if (!$course) {
                    return ApiResponseService::validationError(
                        "Course not found",
                    );
                }

                // Check if user is the instructor of this course or assigned as instructor
                $isOwner = $course->user_id == $instructorId;
                $isAssignedInstructor = false;

                if (!$isOwner) {
                    $isAssignedInstructor = DB::table("course_instructors")
                        ->where("course_id", $course->id)
                        ->where("user_id", $instructorId)
                        ->whereNull("deleted_at")
                        ->exists();
                }

                if (!$isOwner && !$isAssignedInstructor) {
                    return ApiResponseService::unauthorizedResponse(
                        "You are not authorized to view this course data",
                    );
                }

                // Get pagination parameters
                $perPage = max(1, $request->get("per_page", 10)); // Ensure perPage is at least 1
                $page = max(1, $request->get("page", 1)); // Ensure page is at least 1
                $search = $request->get("search", "");

                // Handle assignment_id or assignment_slug
                $assignmentId = null;
                if ($request->filled("assignment_id")) {
                    $assignmentId = $request->get("assignment_id");
                } elseif ($request->filled("assignment_slug")) {
                    $assignment = CourseChapterAssignment::where(
                        "slug",
                        $request->get("assignment_slug"),
                    )->first();
                    if ($assignment) {
                        $assignmentId = $assignment->id;
                    } else {
                        return ApiResponseService::validationError(
                            "Assignment not found",
                        );
                    }
                }

                $status = $request->get("status");

                // Get assignment submissions with pagination
                $submissions = $this->getAssignmentSubmissionsWithPagination(
                    $course->id,
                    $perPage,
                    $page,
                    $search,
                    $assignmentId,
                    $status,
                );

                return ApiResponseService::successResponse(
                    "Assignment submissions retrieved successfully",
                    $submissions,
                );
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Something went wrong: " . $e->getMessage(),
            );
        }
    }

    /**
     * Get team assignment submissions with pagination and filters
     */
    private function getTeamAssignmentSubmissionsWithPagination(
        $courseIds,
        $perPage = 10,
        $page = 1,
        $search = "",
        $assignmentId = null,
        $status = null,
    ) {
        // Build query for assignment submissions from team courses
        $query = UserAssignmentSubmission::with([
            "user:id,name,email,profile",
            "assignment.chapter.course:id,title,slug",
            "files",
        ])->whereHas("assignment.chapter.course", static function (
            $courseQuery,
        ) use ($courseIds): void {
            $courseQuery->whereIn("id", $courseIds);
        });

        // Filter by assignment
        if ($assignmentId) {
            $query->where("course_chapter_assignment_id", $assignmentId);
        }

        // Filter by status
        if ($status) {
            $query->where("status", $status);
        }

        // Apply search filter
        if (!empty($search)) {
            $query->where(static function ($q) use ($search): void {
                $q->whereHas("user", static function ($userQuery) use (
                    $search,
                ): void {
                    $userQuery
                        ->where("name", "LIKE", "%{$search}%")
                        ->orWhere("email", "LIKE", "%{$search}%");
                })
                    ->orWhereHas("assignment", static function (
                        $assignmentQuery,
                    ) use ($search): void {
                        $assignmentQuery->where("title", "LIKE", "%{$search}%");
                    })
                    ->orWhereHas("assignment.chapter.course", static function (
                        $courseQuery,
                    ) use ($search): void {
                        $courseQuery->where("title", "LIKE", "%{$search}%");
                    });
            });
        }

        // Get all submissions
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $query)->count();
        $allSubmissions = $query->orderBy("created_at", "desc")->forPage($page, $perPage)->get();

        $paginatedSubmissions = $allSubmissions->map(
            static fn($submission) => [
                "id" => $submission->id,
                "user" => [
                    "id" => $submission->user->id,
                    "name" => $submission->user->name,
                    "email" => $submission->user->email,
                    "profile" => $submission->user->profile ?? null,
                ],
                "assignment" => [
                    "id" => $submission->assignment->id,
                    "title" => $submission->assignment->title,
                    "max_points" => $submission->assignment->points,
                ],
                "course" => [
                    "id" => $submission->assignment->chapter->course->id,
                    "title" => $submission->assignment->chapter->course->title,
                    "slug" => $submission->assignment->chapter->course->slug,
                ],
                "status" => $submission->status,
                "points" => $submission->points,
                "comment" => $submission->comment,
                "feedback" => $submission->feedback,
                "submitted_at" => $submission->created_at,
                "updated_at" => $submission->updated_at,
                "files" => $submission->files->map(
                    static fn($file) => [
                        "id" => $file->id,
                        "type" => $file->type,
                        "file" => !empty($file->file)
                            ? FileService::getFileUrl($file->file)
                            : null,
                        "url" => $file->url,
                        "file_extension" => $file->file_extension,
                    ],
                ),
            ],
        )->values();

        return $this->replacePaginationFormat(
            $paginatedSubmissions,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get assignment submissions with pagination and filters
     */
    private function getAssignmentSubmissionsWithPagination(
        $courseId,
        $perPage = 10,
        $page = 1,
        $search = "",
        $assignmentId = null,
        $status = null,
    ) {
        // Build query for assignment submissions
        $query = UserAssignmentSubmission::with([
            "user:id,name,email,profile",
            "assignment.chapter.course:id,title,slug",
            "files",
        ])->whereHas("assignment.chapter.course", static function (
            $courseQuery,
        ) use ($courseId): void {
            $courseQuery->where("id", $courseId);
        });

        // Filter by assignment
        if ($assignmentId) {
            $query->where("course_chapter_assignment_id", $assignmentId);
        }

        // Filter by status
        if ($status) {
            $query->where("status", $status);
        }

        // Apply search filter
        if (!empty($search)) {
            $query->where(static function ($q) use ($search): void {
                $q->whereHas("user", static function ($userQuery) use (
                    $search,
                ): void {
                    $userQuery
                        ->where("name", "LIKE", "%{$search}%")
                        ->orWhere("email", "LIKE", "%{$search}%");
                })->orWhereHas("assignment", static function (
                    $assignmentQuery,
                ) use ($search): void {
                    $assignmentQuery->where("title", "LIKE", "%{$search}%");
                });
            });
        }

        // Get all submissions
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $query)->count();
        $allSubmissions = $query->orderBy("created_at", "desc")->forPage($page, $perPage)->get();

        $paginatedSubmissions = $allSubmissions->map(
            static fn($submission) => [
                "id" => $submission->id,
                "user" => [
                    "id" => $submission->user->id,
                    "name" => $submission->user->name,
                    "email" => $submission->user->email,
                    "profile" => $submission->user->profile ?? null,
                ],
                "assignment" => [
                    "id" => $submission->assignment->id,
                    "title" => $submission->assignment->title,
                    "max_points" => $submission->assignment->points,
                ],
                "course" => [
                    "id" => $submission->assignment->chapter->course->id,
                    "title" => $submission->assignment->chapter->course->title,
                    "slug" => $submission->assignment->chapter->course->slug,
                ],
                "status" => $submission->status,
                "points" => $submission->points,
                "comment" => $submission->comment,
                "feedback" => $submission->feedback,
                "submitted_at" => $submission->created_at,
                "updated_at" => $submission->updated_at,
                "files" => $submission->files->map(
                    static fn($file) => [
                        "id" => $file->id,
                        "type" => $file->type,
                        "file" => !empty($file->file)
                            ? FileService::getFileUrl($file->file)
                            : null,
                        "url" => $file->url,
                        "file_extension" => $file->file_extension,
                    ],
                ),
            ],
        )->values();

        return $this->replacePaginationFormat(
            $paginatedSubmissions,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get instructor dashboard data
     */
}
