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

trait ServesInstructorAnalytics
{
    public function getInstructorDashboard(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if user is instructor
            if (!$user->hasRole("Instructor")) {
                return ApiResponseService::unauthorizedResponse(
                    "Only instructors can view dashboard data.",
                );
            }

            $instructorId = $user?->id;

            // Get dashboard statistics
            $dashboardData = [
                "profile_completion" => $this->calculateInstructorProfileCompletion(
                    $instructorId,
                ),
                "overview_stats" => $this->getInstructorOverviewStats(
                    $instructorId,
                ),
                "sales_statistics" => $this->getInstructorSalesStatistics(
                    $instructorId,
                ),
            ];

            return ApiResponseService::successResponse(
                "Dashboard data retrieved successfully",
                $dashboardData,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Failed to retrieve dashboard data: " . $e->getMessage(),
            );
        }
    }

    /**
     * Get instructor overview statistics
     */
    private function getInstructorOverviewStats($instructorId)
    {
        try {
            // Total courses
            $totalCourses = Course::where("user_id", $instructorId)->count();

            // Enrolled students (unique students across all courses)
            $enrolledStudents = OrderCourse::whereHas(
                "course",
                static function ($query) use ($instructorId): void {
                    $query->where("user_id", $instructorId);
                },
            )
                ->whereHas("order", static function ($query): void {
                    $query->where("status", "completed");
                })
                ->join("orders", "order_courses.order_id", "=", "orders.id")
                ->distinct("orders.user_id")
                ->count("orders.user_id");

            // Courses sold (total enrollments)
            $coursesSold = OrderCourse::whereHas("course", static function (
                $query,
            ) use ($instructorId): void {
                $query->where("user_id", $instructorId);
            })
                ->whereHas("order", static function ($query): void {
                    $query->where("status", "completed");
                })
                ->count();

            // Total revenue from orders (includes tax, promo, etc.)
            $totalRevenue = Order::whereHas("orderCourses", static function (
                $query,
            ) use ($instructorId): void {
                $query->whereHas("course", static function ($courseQuery) use (
                    $instructorId,
                ): void {
                    $courseQuery->where("user_id", $instructorId);
                });
            })
                ->where("status", "completed")
                ->sum("final_price");

            // Get instructor earnings from EarningsService (current year)
            $currentYear = now()->year;
            $startDate = Carbon::createFromDate(
                $currentYear,
                1,
                1,
            )->startOfDay();
            $endDate = Carbon::createFromDate($currentYear, 12, 31)->endOfDay();

            $stats = $this->earningsService->getStats(
                $instructorId,
                null,
                $startDate,
                $endDate,
            );
            $instructorEarnings = $stats["earnings"];

            // Average rating/feedback
            $averageRating = Rating::whereHas("rateable", static function (
                $query,
            ) use ($instructorId): void {
                $query->where("user_id", $instructorId);
            })
                ->where("rateable_type", Course::class)
                ->avg("rating");

            return [
                "total_courses" => [
                    "value" => $totalCourses,
                    "label" => "Total Courses",
                    "icon" => "fas fa-graduation-cap",
                ],
                "enrolled_students" => [
                    "value" => $enrolledStudents,
                    "label" => "Enrolled Students",
                    "icon" => "fas fa-users",
                ],
                "courses_sold" => [
                    "value" => $coursesSold,
                    "label" => "Courses Sold",
                    "icon" => "fas fa-shopping-cart",
                ],
                "total_earnings" => [
                    "value" => number_format($instructorEarnings, 2),
                    "label" => "Total Earnings",
                    "icon" => "fas fa-dollar-sign",
                ],
                "positive_feedback" => [
                    "value" => round($averageRating ?? 0, 1) . "/5.0",
                    "label" => "Positive Feedback",
                    "icon" => "fas fa-star",
                ],
            ];
        } catch (Exception) {
            return [
                "total_courses" => [
                    "value" => 0,
                    "label" => "Total Courses",
                    "icon" => "fas fa-graduation-cap",
                ],
                "enrolled_students" => [
                    "value" => 0,
                    "label" => "Enrolled Students",
                    "icon" => "fas fa-users",
                ],
                "courses_sold" => [
                    "value" => 0,
                    "label" => "Courses Sold",
                    "icon" => "fas fa-shopping-cart",
                ],
                "total_earnings" => [
                    "value" => '$0.00',
                    "label" => "Total Earnings",
                    "icon" => "fas fa-dollar-sign",
                ],
                "positive_feedback" => [
                    "value" => "0.0/5.0",
                    "label" => "Positive Feedback",
                    "icon" => "fas fa-star",
                ],
            ];
        }
    }

    /**
     * Get instructor sales statistics chart data
     */
    private function getInstructorSalesStatistics($instructorId)
    {
        try {
            $currentYear = now()->year;
            $currentMonth = now()->month;
            $currentWeek = now()->weekOfYear;

            // Get yearly data (current year monthly breakdown)
            $yearlyData = $this->getInstructorYearlySalesData(
                $instructorId,
                $currentYear,
            );

            // Get monthly data (current month daily breakdown)
            $monthlyData = $this->getInstructorMonthlySalesData(
                $instructorId,
                $currentYear,
                $currentMonth,
            );

            // Get weekly data (current week daily breakdown)
            $weeklyData = $this->getInstructorWeeklySalesData(
                $instructorId,
                $currentYear,
                $currentWeek,
            );

            return [
                "yearly" => $yearlyData,
                "monthly" => $monthlyData,
                "weekly" => $weeklyData,
            ];
        } catch (Exception) {
            return [
                "yearly" => [],
                "monthly" => [],
                "weekly" => [],
            ];
        }
    }

    /**
     * Get yearly sales data for instructor (current year monthly breakdown)
     */
    private function getInstructorYearlySalesData($instructorId, $year)
    {
        // Get monthly earnings data from EarningsService
        $monthlyEarnings = $this->earningsService->getMonthlyData(
            $year,
            $instructorId,
        );

        $yearlyData = [];

        foreach ($monthlyEarnings as $earnings) {
            $yearlyData[] = [
                "name" => $earnings["month"],
                "sales" => $earnings["sales_count"],
                "revenue" => (float) $earnings["revenue"],
                "profit" => (float) $earnings["earnings"],
            ];
        }

        return $yearlyData;
    }

    /**
     * Get monthly sales data for instructor (current month daily breakdown)
     */
    private function getInstructorMonthlySalesData($instructorId, $year, $month)
    {
        // Get daily earnings data from EarningsService
        $dailyEarnings = $this->earningsService->getDailyDataForMonth(
            $year,
            $month,
            $instructorId,
        );

        $monthlyData = [];

        foreach ($dailyEarnings as $earnings) {
            $monthlyData[] = [
                "name" => $earnings["day"] . " " . now()->format("M"),
                "sales" => $earnings["sales_count"],
                "revenue" => (float) $earnings["revenue"],
                "profit" => (float) $earnings["earnings"],
            ];
        }

        return $monthlyData;
    }

    /**
     * Get weekly sales data for instructor (current week daily breakdown)
     */
    private function getInstructorWeeklySalesData($instructorId, $year, $week)
    {
        // Get weekly earnings data from EarningsService
        $weeklyEarnings = $this->earningsService->getDailyDataForWeek(
            $year,
            $week,
            $instructorId,
        );

        $weeklyData = [];

        foreach ($weeklyEarnings as $earnings) {
            $weeklyData[] = [
                "name" => $earnings["day_name"],
                "sales" => $earnings["sales_count"],
                "revenue" => (float) $earnings["revenue"],
                "profit" => (float) $earnings["earnings"],
            ];
        }

        return $weeklyData;
    }

    /**
     * Get instructor most selling courses
     */
    private function getInstructorMostSellingCourses($instructorId)
    {
        try {
            $courses = Course::where("user_id", $instructorId)
                ->withCount([
                    "orderCourses as sales_count" => static function (
                        $query,
                    ): void {
                        $query->whereHas("order", static function (
                            $orderQuery,
                        ): void {
                            $orderQuery->where("status", "completed");
                        });
                    },
                ])
                ->with([
                    "orderCourses" => static function ($query): void {
                        $query->whereHas("order", static function (
                            $orderQuery,
                        ): void {
                            $orderQuery->where("status", "completed");
                        });
                    },
                ])
                ->orderBy("sales_count", "desc")
                ->limit(5)
                ->get();

            return $courses->map(
                static fn($course) => [
                    "id" => $course->id,
                    "title" => $course->title,
                    "price" => '$' . number_format($course->price, 0),
                    "sales_count" => $course->sales_count,
                    "status" => "Sold",
                    "thumbnail" => $course->thumbnail
                        ? asset("storage/" . $course->thumbnail)
                        : asset("img/default-course.jpg"),
                    "slug" => $course->slug,
                ],
            );
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Get quiz reports for courses
     */
    public function getQuizReports(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|exists:courses,slug",
                "course_slug" => "nullable|string|exists:courses,slug",
                "team_user_slug" => "nullable|string|exists:users,slug",
                "category_id" => "nullable|exists:categories,id",
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

                // Get courses based on relationship (only assigned courses)
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
                $perPage = max(1, $request->get("per_page", 10));
                $page = max(1, $request->get("page", 1));
                $search = $request->get("search", "");
                $categoryId = $request->get("category_id");

                // Get quiz reports for assigned team courses only
                $quizReports = $this->getTeamQuizReportsWithPagination(
                    $courses,
                    $perPage,
                    $page,
                    $search,
                    $categoryId,
                );

                return ApiResponseService::successResponse(
                    "Team quiz reports retrieved successfully",
                    $quizReports,
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
                    $perPage = max(1, $request->get("per_page", 10));
                    $page = max(1, $request->get("page", 1));
                    $search = $request->get("search", "");
                    $categoryId = $request->get("category_id");

                    // Get quiz reports for all instructor's courses
                    $quizReports = $this->getTeamQuizReportsWithPagination(
                        $instructorCourses,
                        $perPage,
                        $page,
                        $search,
                        $categoryId,
                    );

                    return ApiResponseService::successResponse(
                        "Instructor quiz reports retrieved successfully",
                        $quizReports,
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
                $perPage = max(1, $request->get("per_page", 10));
                $page = max(1, $request->get("page", 1));
                $search = $request->get("search", "");
                $categoryId = $request->get("category_id");

                // Get quiz reports with pagination
                $quizReports = $this->getQuizReportsWithPagination(
                    $course->id,
                    $perPage,
                    $page,
                    $search,
                    $categoryId,
                );

                return ApiResponseService::successResponse(
                    "Quiz reports retrieved successfully",
                    $quizReports,
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
     * Get team quiz reports with pagination and filters
     */
    private function getTeamQuizReportsWithPagination(
        $courseIds,
        $perPage = 10,
        $page = 1,
        $search = "",
        $categoryId = null,
    ) {
        // Get all quizzes for the team courses with their relationships
        $quizzesQuery = CourseChapterQuiz::whereHas("chapter", static function (
            $query,
        ) use ($courseIds): void {
            $query->whereIn("course_id", $courseIds);
        })
            ->with([
                "chapter" => static function ($query): void {
                    $query->select("id", "title", "course_id");
                },
                "chapter.course" => static function ($query): void {
                    $query->select("id", "title", "slug", "category_id");
                },
                "chapter.course.category" => static function ($query): void {
                    $query->select("id", "name");
                },
                "questions" => static function ($query): void {
                    $query->select("id", "course_chapter_quiz_id");
                },
            ])
            ->orderBy("chapter_order");

        // Filter by category
        if ($categoryId) {
            $quizzesQuery->whereHas("chapter.course", static function (
                $courseQuery,
            ) use ($categoryId): void {
                $courseQuery->where("category_id", $categoryId);
            });
        }

        // Apply search filter
        if (!empty($search)) {
            $quizzesQuery->where(static function ($query) use ($search): void {
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
                    ->orWhereHas("chapter.course.category", static function (
                        $categoryQuery,
                    ) use ($search): void {
                        $categoryQuery->where(
                            "name",
                            "like",
                            "%" . $search . "%",
                        );
                    });
            });
        }

        // Get all quizzes
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $quizzesQuery)->count();
        $allQuizzes = $quizzesQuery->forPage($page, $perPage)->get();

        $paginatedQuizzes = $allQuizzes->map(
            static fn($quiz, $index) => [
                "id" => $quiz->id,
                "quiz_name" => $quiz->title,
                "quiz_slug" => $quiz->slug,
                "total_questions" => $quiz->questions->count(),
                "course_name" => $quiz->chapter->course->title,
                "course_slug" => $quiz->chapter->course->slug,
                "chapter_name" => $quiz->chapter->title,
                "category_name" =>
                    $quiz->chapter->course->category->name ?? "Uncategorized",
                "description" => $quiz->description,
                "time_limit" => $quiz->time_limit,
                "passing_score" => $quiz->passing_score,
                "is_active" => $quiz->is_active,
                "created_at" => $quiz->created_at,
                "updated_at" => $quiz->updated_at,
            ],
        )->values();

        return $this->replacePaginationFormat(
            $paginatedQuizzes,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get quiz reports with pagination and filters
     */
    private function getQuizReportsWithPagination(
        $courseId,
        $perPage = 10,
        $page = 1,
        $search = "",
        $categoryId = null,
    ) {
        // Get all quizzes for the course with their relationships
        $quizzesQuery = CourseChapterQuiz::whereHas("chapter", static function (
            $query,
        ) use ($courseId): void {
            $query->where("course_id", $courseId);
        })
            ->with([
                "chapter" => static function ($query): void {
                    $query->select("id", "title", "course_id");
                },
                "chapter.course" => static function ($query): void {
                    $query->select("id", "title", "slug", "category_id");
                },
                "chapter.course.category" => static function ($query): void {
                    $query->select("id", "name");
                },
                "questions" => static function ($query): void {
                    $query->select("id", "course_chapter_quiz_id");
                },
            ])
            ->orderBy("chapter_order");

        // Filter by category
        if ($categoryId) {
            $quizzesQuery->whereHas("chapter.course", static function (
                $courseQuery,
            ) use ($categoryId): void {
                $courseQuery->where("category_id", $categoryId);
            });
        }

        // Apply search filter
        if (!empty($search)) {
            $quizzesQuery->where(static function ($query) use ($search): void {
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
                    ->orWhereHas("chapter.course.category", static function (
                        $categoryQuery,
                    ) use ($search): void {
                        $categoryQuery->where(
                            "name",
                            "like",
                            "%" . $search . "%",
                        );
                    });
            });
        }

        // Get all quizzes
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total = (clone $quizzesQuery)->count();
        $allQuizzes = $quizzesQuery->forPage($page, $perPage)->get();

        $paginatedQuizzes = $allQuizzes->map(
            static fn($quiz, $index) => [
                "id" => $quiz->id,
                "quiz_name" => $quiz->title,
                "quiz_slug" => $quiz->slug,
                "total_questions" => $quiz->questions->count(),
                "course_name" => $quiz->chapter->course->title,
                "course_slug" => $quiz->chapter->course->slug,
                "chapter_name" => $quiz->chapter->title,
                "category_name" =>
                    $quiz->chapter->course->category->name ?? "Uncategorized",
                "description" => $quiz->description,
                "time_limit" => $quiz->time_limit,
                "passing_score" => $quiz->passing_score,
                "is_active" => $quiz->is_active,
                "created_at" => $quiz->created_at,
                "updated_at" => $quiz->updated_at,
            ],
        )->values();

        return $this->replacePaginationFormat(
            $paginatedQuizzes,
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * Get course resources for customer app
     */
    public function getResources(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|exists:courses,slug",
                "course_slug" => "nullable|string|exists:courses,slug",
                "lecture_id" => "nullable|exists:course_chapter_lectures,id",
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

            $user = Auth::guard('sanctum')->user() ?: Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse("Authentication required to access resources.", [], 401);
            }

            if (!app(\App\Services\ContentAccessService::class)->canAccessCourse($user, $course)) {
                return ApiResponseService::errorResponse("You must be enrolled in or subscribed to this course to access resources.", [], 403);
            }

            // Get all resources for the course
            $allResources = $this->getAllResources($course->id);

            // Get current lecture resources if lecture_id is provided
            $currentLectureResources = [];
            if ($request->filled("lecture_id")) {
                $currentLectureResources = $this->getCurrentLectureResources(
                    $request->lecture_id,
                );
            }

            $responseData = [
                "all_resources" => $allResources,
                "current_lecture_resources" => $currentLectureResources,
            ];

            return ApiResponseService::successResponse(
                "Resources retrieved successfully",
                $responseData,
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
     * Get all resources - chapters grouped, lectures organized by lecture
     */
    private function getAllResources($courseId)
    {
        // Get course chapters with their resources
        $chapters = \App\Models\Course\CourseChapter\CourseChapter::where(
            "course_id",
            $courseId,
        )
            ->with([
                "resources" => static function ($query): void {
                    $query->where("is_active", true)->orderBy("chapter_order");
                },
                "lectures.resources" => static function ($query): void {
                    $query->where("is_active", true)->orderBy("order");
                },
            ])
            ->orderBy("chapter_order")
            ->get();

        $chapterResources = [];
        $lectureResources = [];

        foreach ($chapters as $chapter) {
            // Group all chapter resources into one object per chapter
            $chapterResourceList = [];
            foreach ($chapter->resources as $resource) {
                $chapterResourceList[] = [
                    "type" =>
                        $resource->type === "file"
                            ? "download"
                            : "external_link",
                    "file_url" =>
                        $resource->type === "file" ? $resource->file : null,
                    "external_url" =>
                        $resource->type === "url" ? $resource->url : null,
                    "file_name" => $resource->file_extension
                        ? $resource->title . "." . $resource->file_extension
                        : $resource->title,
                    "file_extension" => $resource->file_extension,
                    "description" => $resource->description,
                    "resource_type" => "chapter",
                ];
            }

            // Only add chapter if it has resources
            if (!empty($chapterResourceList)) {
                $chapterResources[] = [
                    "chapter_id" => $chapter->id,
                    "chapter_title" => $chapter->title,
                    "resources" => $chapterResourceList,
                ];
            }

            // Add lecture resources organized by lecture
            foreach ($chapter->lectures as $lecture) {
                $lectureResourceList = [];
                foreach ($lecture->resources as $resource) {
                    $lectureResourceList[] = [
                        "id" => $resource->id,
                        "title" => $resource->file_extension
                            ? $resource->title . "." . $resource->file_extension
                            : $resource->title,
                        "type" =>
                            $resource->type === "file"
                                ? "download"
                                : "external_link",
                        "file_url" =>
                            $resource->type === "file" ? $resource->file : null,
                        "external_url" =>
                            $resource->type === "url" ? $resource->url : null,
                        "file_extension" => $resource->file_extension,
                        "created_at" => $resource->created_at,
                    ];
                }

                if (!empty($lectureResourceList)) {
                    $lectureResources[] = [
                        "id" => $lecture->id,
                        "title" => $lecture->title,
                        "chapter_id" => $chapter->id,
                        "chapter_title" => $chapter->title,
                        "lecture_order" => $lecture->lecture_order,
                        "resources" => $lectureResourceList,
                    ];
                }
            }
        }

        return [
            "chapters" => $chapterResources, // Chapter resources grouped by chapter
            "lectures" => $lectureResources, // All lectures with their resources
        ];
    }

    /**
     * Get current lecture resources
     */
    private function getCurrentLectureResources($lectureId)
    {
        $lecture = CourseChapterLecture::with([
            "resources" => static function ($query): void {
                $query->where("is_active", true)->orderBy("order");
            },
            "chapter" => static function ($query): void {
                $query->select("id", "title", "course_id");
            },
        ])->find($lectureId);

        if (!$lecture) {
            return [];
        }

        $lectureResources = [];

        foreach ($lecture->resources as $resource) {
            $detailedType = "external_link";
            if ($resource->type === "file") {
                $ext = strtolower((string) $resource->file_extension);
                if (in_array($ext, ["mp4", "mkv", "avi", "mov", "webm"])) {
                    $detailedType = "video";
                } elseif (in_array($ext, ["mp3", "wav", "ogg"])) {
                    $detailedType = "audio";
                } elseif (
                    in_array($ext, ["jpg", "jpeg", "png", "gif", "svg", "webp"])
                ) {
                    $detailedType = "image";
                } elseif (
                    in_array($ext, [
                        "pdf",
                        "doc",
                        "docx",
                        "txt",
                        "xls",
                        "xlsx",
                        "ppt",
                        "pptx",
                        "zip",
                        "rar",
                    ])
                ) {
                    $detailedType = "doc";
                } else {
                    $detailedType = "download";
                }
            }

            $lectureResources[] = [
                "id" => $resource->id,
                "title" => $resource->file_extension
                    ? $resource->title . "." . $resource->file_extension
                    : $resource->title,
                "type" => $detailedType,
                "file_url" =>
                    $resource->type === "file" ? $resource->file : null,
                "external_url" =>
                    $resource->type === "url" ? $resource->url : null,
                "file_extension" => $resource->file_extension,
                "created_at" => $resource->created_at,
            ];
        }

        // Return lecture object with resources (matching your example format)
        return [
            [
                "id" => $lecture->id,
                "title" => $lecture->title,
                "chapter_id" => $lecture->chapter->id,
                "chapter_title" => $lecture->chapter->title,
                "lecture_order" => $lecture->lecture_order,
                "resources" => $lectureResources,
            ],
        ];
    }

    /**
     * Get most selling courses for instructor panel
     */
    public function getMostSellingCourses(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "filter" =>
                    "nullable|in:yearly,monthly,weekly,price_high_to_low,price_low_to_high",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "search" => "nullable|string|max:255",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $user = Auth::user();
            $instructorId = $user?->id;

            // Get pagination parameters
            $perPage = max(1, $request->get("per_page", 10));
            $page = max(1, $request->get("page", 1));
            $search = $request->get("search", "");
            $filter = $request->get("filter", "yearly");

            // Get instructor's courses with sales data
            $coursesQuery = Course::where("user_id", $instructorId)
                ->with(["category", "ratings"])
                ->withCount([
                    "orderCourses as total_sales" => static function (
                        $query,
                    ): void {
                        $query->whereHas("order", static function (
                            $orderQuery,
                        ): void {
                            $orderQuery->where("status", "completed");
                        });
                    },
                ])
                ->withSum(
                    [
                        "orderCourses as total_revenue" => static function (
                            $query,
                        ): void {
                            $query->whereHas("order", static function (
                                $orderQuery,
                            ): void {
                                $orderQuery->where("status", "completed");
                            });
                        },
                    ],
                    "price",
                );

            // Apply search filter
            if (!empty($search)) {
                $coursesQuery->where(static function ($query) use (
                    $search,
                ): void {
                    $query
                        ->where("title", "like", "%" . $search . "%")
                        ->orWhere(
                            "short_description",
                            "like",
                            "%" . $search . "%",
                        )
                        ->orWhereHas("category", static function (
                            $categoryQuery,
                        ) use ($search): void {
                            $categoryQuery->where(
                                "name",
                                "like",
                                "%" . $search . "%",
                            );
                        });
                });
            }

            // Apply filters - always exclude courses with no sales except for price filters
            if (in_array($filter, ["yearly", "monthly", "weekly"])) {
                // Apply time-based filters
                $coursesQuery->whereHas("orderCourses", static function (
                    $query,
                ) use ($filter): void {
                    $query->whereHas("order", static function (
                        $orderQuery,
                    ) use ($filter): void {
                        $orderQuery->where("status", "completed");

                        switch ($filter) {
                            case "yearly":
                                $orderQuery->whereYear("created_at", date("Y"));
                                break;
                            case "monthly":
                                $orderQuery
                                    ->whereYear("created_at", date("Y"))
                                    ->whereMonth("created_at", date("m"));
                                break;
                            case "weekly":
                                $orderQuery->whereBetween("created_at", [
                                    now()->startOfWeek(),
                                    now()->endOfWeek(),
                                ]);
                                break;
                        }
                    });
                });
            } elseif (
                !in_array($filter, ["price_high_to_low", "price_low_to_high"])
            ) {
                // For default case (no filter or yearly), only show courses with sales
                $coursesQuery->whereHas("orderCourses", static function (
                    $query,
                ): void {
                    $query->whereHas("order", static function (
                        $orderQuery,
                    ): void {
                        $orderQuery->where("status", "completed");
                    });
                });
            }

            // Apply sorting
            match ($filter) {
                "price_high_to_low" => $coursesQuery->orderBy("price", "desc"),
                "price_low_to_high" => $coursesQuery->orderBy("price", "asc"),
                // For most selling courses, sort by total sales descending
                default => $coursesQuery->orderBy("total_sales", "desc"),
            };

            // Get all courses
            $allCourses = $coursesQuery->get();

            // Transform courses data
            $transformedCourses = $allCourses->map(function ($course) use (
                $filter,
            ) {
                // Calculate average rating
                $averageRating = $course->ratings->avg("rating") ?? 0;
                $ratingCount = $course->ratings->count();

                // Calculate profit (70% of revenue)
                $totalRevenue = $course->total_revenue ?? 0;
                $profit = $totalRevenue * 0.7;

                // Get time-based sales data
                $timeBasedSales = $this->getTimeBasedSalesData(
                    $course->id,
                    $filter,
                );

                return [
                    "id" => $course->id,
                    "title" => $course->title,
                    "slug" => $course->slug,
                    "thumbnail" => $course->thumbnail
                        ? asset("storage/" . $course->thumbnail)
                        : null,
                    "category" => [
                        "id" => $course->category->id ?? null,
                        "name" => $course->category->name ?? null,
                    ],
                    "price" => $course->price,
                    "discount_price" => $course->discount_price,
                    "total_sales" => $course->total_sales ?? 0,
                    "total_revenue" => round($totalRevenue, 2),
                    "profit" => round($profit, 2),
                    "average_rating" => round($averageRating, 1),
                    "rating_count" => $ratingCount,
                    "status" => $course->status,
                    "is_active" => $course->is_active,
                    "created_at" => $course->created_at,
                    "time_based_sales" => $timeBasedSales,
                ];
            });

            // Apply pagination manually
            $total = $transformedCourses->count();
            $offset = ($page - 1) * $perPage;
            $paginatedCourses = $transformedCourses
                ->slice($offset, $perPage)
                ->values();

            $pagination = $this->replacePaginationFormat(
                $paginatedCourses,
                $page,
                $perPage,
                $total,
            );

            $responseData = array_merge($pagination, [
                "filter_applied" => $filter,
                "summary" => [
                    "total_courses" => $total,
                    "total_sales" => $transformedCourses->sum("total_sales"),
                    "total_revenue" => round(
                        $transformedCourses->sum("total_revenue"),
                        2,
                    ),
                    "total_profit" => round(
                        $transformedCourses->sum("profit"),
                        2,
                    ),
                ],
            ]);

            return ApiResponseService::successResponse(
                "Most selling courses retrieved successfully",
                $responseData,
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
     * Get time-based sales data for a course
     */
    private function getTimeBasedSalesData($courseId, $filter)
    {
        $query = OrderCourse::where("course_id", $courseId)->whereHas(
            "order",
            static function ($orderQuery): void {
                $orderQuery->where("status", "completed");
            },
        );

        switch ($filter) {
            case "yearly":
                $query->whereYear("created_at", date("Y"));
                break;
            case "monthly":
                $query
                    ->whereYear("created_at", date("Y"))
                    ->whereMonth("created_at", date("m"));
                break;
            case "weekly":
                $query->whereBetween("created_at", [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ]);
                break;
        }

        $sales = $query->get();
        $totalSales = $sales->count();
        $totalRevenue = $sales->sum("price");
        $profit = $totalRevenue * 0.7;

        return [
            "sales_count" => $totalSales,
            "revenue" => round($totalRevenue, 2),
            "profit" => round($profit, 2),
        ];
    }

    /**
     * Generate pagination links in Laravel format
     */
    private function generatePaginationLinks($currentPage, $lastPage, $baseUrl)
    {
        $links = [];

        // Previous link
        $links[] = [
            "url" =>
                $currentPage > 1
                    ? $baseUrl . "?page=" . ($currentPage - 1)
                    : null,
            "label" => "&laquo; Previous",
            "active" => false,
        ];

        // Page number links
        for ($i = 1; $i <= $lastPage; $i++) {
            $links[] = [
                "url" => $baseUrl . "?page=" . $i,
                "label" => (string) $i,
                "active" => $i == $currentPage,
            ];
        }

        // Next link
        $links[] = [
            "url" =>
                $currentPage < $lastPage
                    ? $baseUrl . "?page=" . ($currentPage + 1)
                    : null,
            "label" => "Next &raquo;",
            "active" => false,
        ];

        return $links;
    }

    /**
     * Generate Laravel-style pagination data
     */
    private function generateLaravelPagination(
        $data,
        $currentPage,
        $perPage,
        $total,
        $baseUrl = null,
    ) {
        if (!$baseUrl) {
            $baseUrl = request()->url();
        }

        $lastPage = $perPage > 0 ? ceil($total / $perPage) : 0;
        $offset = ($currentPage - 1) * $perPage;

        return [
            "current_page" => $currentPage,
            "data" => $data,
            "first_page_url" => $baseUrl . "?page=1",
            "from" => $total > 0 ? $offset + 1 : null,
            "last_page" => $lastPage,
            "last_page_url" => $baseUrl . "?page=" . $lastPage,
            "links" => $this->generatePaginationLinks(
                $currentPage,
                $lastPage,
                $baseUrl,
            ),
            "next_page_url" =>
                $currentPage < $lastPage
                    ? $baseUrl . "?page=" . ($currentPage + 1)
                    : null,
            "path" => $baseUrl,
            "per_page" => $perPage,
            "prev_page_url" =>
                $currentPage > 1
                    ? $baseUrl . "?page=" . ($currentPage - 1)
                    : null,
            "to" => $total > 0 ? min($offset + $perPage, $total) : null,
            "total" => $total,
        ];
    }

    /**
     * Replace old pagination format with Laravel format
     */
    private function replacePaginationFormat(
        $data,
        $currentPage,
        $perPage,
        $total,
    ) {
        $lastPage = $perPage > 0 ? ceil($total / $perPage) : 0;
        $offset = ($currentPage - 1) * $perPage;
        $baseUrl = request()->url();

        return [
            "current_page" => $currentPage,
            "data" => $data,
            "first_page_url" => $baseUrl . "?page=1",
            "from" => $total > 0 ? $offset + 1 : null,
            "last_page" => $lastPage,
            "last_page_url" => $baseUrl . "?page=" . $lastPage,
            "links" => $this->generatePaginationLinks(
                $currentPage,
                $lastPage,
                $baseUrl,
            ),
            "next_page_url" =>
                $currentPage < $lastPage
                    ? $baseUrl . "?page=" . ($currentPage + 1)
                    : null,
            "path" => $baseUrl,
            "per_page" => $perPage,
            "prev_page_url" =>
                $currentPage > 1
                    ? $baseUrl . "?page=" . ($currentPage - 1)
                    : null,
            "to" => $total > 0 ? min($offset + $perPage, $total) : null,
            "total" => $total,
        ];
    }

    /**
     * Calculate student progress percentage for a course
     */
    private function calculateStudentProgress($userId, $courseId)
    {
        // Get course chapters with active curriculum items only
        $course = Course::with([
            "chapters" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.lectures" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.quizzes" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.assignments" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.resources" => static function ($query): void {
                $query->where("is_active", 1);
            },
        ])->find($courseId);

        if (!$course) {
            return 0;
        }

        $totalItems = 0;
        $completedItems = 0;

        foreach ($course->chapters as $chapter) {
            // Count lectures
            foreach ($chapter->lectures as $lecture) {
                $totalItems++;
                $isCompleted = UserCurriculumTracking::where("user_id", $userId)
                    ->where("course_chapter_id", $chapter->id)
                    ->where("model_id", $lecture->id)
                    ->where("model_type", CourseChapterLecture::class)
                    ->where("status", "completed")
                    ->exists();
                if ($isCompleted) {
                    $completedItems++;
                }
            }

            // Count quizzes
            foreach ($chapter->quizzes as $quiz) {
                $totalItems++;
                $isCompleted = UserCurriculumTracking::where("user_id", $userId)
                    ->where("course_chapter_id", $chapter->id)
                    ->where("model_id", $quiz->id)
                    ->where("model_type", CourseChapterQuiz::class)
                    ->where("status", "completed")
                    ->exists();
                if ($isCompleted) {
                    $completedItems++;
                }
            }

            // Count assignments
            foreach ($chapter->assignments as $assignment) {
                $totalItems++;
                $isCompleted = UserCurriculumTracking::where("user_id", $userId)
                    ->where("course_chapter_id", $chapter->id)
                    ->where("model_id", $assignment->id)
                    ->where("model_type", CourseChapterAssignment::class)
                    ->where("status", "completed")
                    ->exists();
                if ($isCompleted) {
                    $completedItems++;
                }
            }

            // Count resources
            foreach ($chapter->resources as $resource) {
                $totalItems++;
                $isCompleted = UserCurriculumTracking::where("user_id", $userId)
                    ->where("course_chapter_id", $chapter->id)
                    ->where("model_id", $resource->id)
                    ->where("model_type", CourseChapterResource::class)
                    ->where("status", "completed")
                    ->exists();
                if ($isCompleted) {
                    $completedItems++;
                }
            }
        }

        if ($totalItems == 0) {
            return 0;
        }

        return round(($completedItems / $totalItems) * 100, 2);
    }

    /**
     * Get comprehensive statistics for a single course
     */
    private function getSingleCourseStatistics($courseId)
    {
        try {
            // Get course to find the owner
            $course = Course::find($courseId);
            if (!$course) {
                throw new \Exception("Course not found");
            }

            // Get earnings from EarningsService for this specific course
            $stats = $this->earningsService->getStats(
                $course->user_id,
                $courseId,
            );
            $totalEarnings = $stats["earnings"]; // Instructor earnings from Commission table
            $totalSales = $stats["sales_count"];
            $totalEnrolledUsers = $totalSales;

            // Get total reviews count
            $totalReviews = Rating::where("rateable_type", Course::class)
                ->where("rateable_id", $courseId)
                ->count();

            // Get sales chart data with yearly, monthly, and weekly breakdown
            $salesChartData = $this->getCourseSalesChartData($courseId);

            // Get course content statistics
            $courseContentStats = $this->getCourseContentStatistics($courseId);

            return [
                "analytics" => [
                    "total_earnings" => [
                        "amount" => round($totalEarnings, 2),
                        "formatted" => '$' . number_format($totalEarnings, 2),
                        "label" => "Earnings from this Course",
                    ],
                    "total_enrolled_users" => [
                        "count" => $totalEnrolledUsers,
                        "label" => "Total Enrolled Users",
                    ],
                    "total_reviews" => [
                        "count" => $totalReviews,
                        "label" => "Total Reviews Received",
                    ],
                    "total_sales" => [
                        "count" => $totalSales,
                        "label" => "Course Sales",
                    ],
                ],
                "content_statistics" => $courseContentStats,
                "sales_chart_data" => $salesChartData,
            ];
        } catch (Exception) {
            return [
                "analytics" => [
                    "total_earnings" => [
                        "amount" => 0,
                        "formatted" => '$0.00',
                        "label" => "Earnings from this Course",
                    ],
                    "total_enrolled_users" => [
                        "count" => 0,
                        "label" => "Total Enrolled Users",
                    ],
                    "total_reviews" => [
                        "count" => 0,
                        "label" => "Total Reviews Received",
                    ],
                    "total_sales" => ["count" => 0, "label" => "Course Sales"],
                ],
                "content_statistics" => [],
                "sales_chart_data" => [
                    "yearly" => [],
                    "monthly" => [],
                    "weekly" => [],
                ],
                "error" => "Unable to fetch statistics",
            ];
        }
    }

    /**
     * Get monthly sales data for chart
     */
    private function getMonthlySalesData($courseId)
    {
        $salesChartData = [];

        // Get last 12 months data
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthName = $date->format("M");

            // Get sales count for this month
            $monthlySales = OrderCourse::whereHas("order", static function (
                $q,
            ) use ($date): void {
                $q->where("status", "completed")
                    ->whereYear("created_at", $date->year)
                    ->whereMonth("created_at", $date->month);
            })
                ->where("course_id", $courseId)
                ->count();

            // Get revenue for this month
            $monthlyRevenue = OrderCourse::whereHas("order", static function (
                $q,
            ) use ($date): void {
                $q->where("status", "completed")
                    ->whereYear("created_at", $date->year)
                    ->whereMonth("created_at", $date->month);
            })
                ->where("course_id", $courseId)
                ->sum("price");

            // Calculate profit (assuming 70% profit margin)
            $monthlyProfit = $monthlyRevenue * 0.7;

            $salesChartData[] = [
                "name" => $monthName,
                "sales" => $monthlySales,
                "revenue" => round($monthlyRevenue, 2),
                "profit" => round($monthlyProfit, 2),
            ];
        }

        return $salesChartData;
    }

    /**
     * Get course sales chart data with yearly, monthly, and weekly breakdown
     */
    private function getCourseSalesChartData($courseId)
    {
        $currentYear = now()->year;
        $currentMonth = now()->month;
        $currentWeek = now()->weekOfYear;

        // Get yearly data (current year monthly breakdown)
        $yearlyData = $this->getCourseYearlySalesData($courseId, $currentYear);

        // Get monthly data (current month daily breakdown)
        $monthlyData = $this->getCourseMonthlySalesData(
            $courseId,
            $currentYear,
            $currentMonth,
        );

        // Get weekly data (current week daily breakdown)
        $weeklyData = $this->getCourseWeeklySalesData(
            $courseId,
            $currentYear,
            $currentWeek,
        );

        return [
            "yearly" => $yearlyData,
            "monthly" => $monthlyData,
            "weekly" => $weeklyData,
        ];
    }

    /**
     * Get yearly sales data for a specific course (current year monthly breakdown)
     */
    private function getCourseYearlySalesData($courseId, $year)
    {
        $monthNames = [
            1 => "Jan",
            2 => "Feb",
            3 => "Mar",
            4 => "Apr",
            5 => "May",
            6 => "Jun",
            7 => "Jul",
            8 => "Aug",
            9 => "Sep",
            10 => "Oct",
            11 => "Nov",
            12 => "Dec",
        ];

        $yearlyData = [];

        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth();

            // Get earnings from Commission table for this course
            $stats = Commission::where("course_id", $courseId)
                ->whereBetween("created_at", [$startDate, $endDate])
                ->selectRaw(
                    '
                    COALESCE(SUM(admin_commission_amount), 0) as admin,
                    COALESCE(SUM(instructor_commission_amount), 0) as instructor,
                    COUNT(*) as sales_count
                ',
                )
                ->first();

            $revenue =
                (float) ($stats->admin ?? 0) +
                (float) ($stats->instructor ?? 0);
            $profit = (float) ($stats->instructor ?? 0);

            $yearlyData[] = [
                "name" => $monthNames[$month],
                "sales" => (int) ($stats->sales_count ?? 0),
                "revenue" => $revenue,
                "profit" => $profit,
            ];
        }

        return $yearlyData;
    }

    /**
     * Get monthly sales data for a specific course (current month daily breakdown)
     */
    private function getCourseMonthlySalesData($courseId, $year, $month)
    {
        $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $monthlyData = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $startDate = Carbon::createFromDate(
                $year,
                $month,
                $day,
            )->startOfDay();
            $endDate = $startDate->copy()->endOfDay();

            // Get earnings from Commission table for this course
            $stats = Commission::where("course_id", $courseId)
                ->whereBetween("created_at", [$startDate, $endDate])
                ->selectRaw(
                    '
                    COALESCE(SUM(admin_commission_amount), 0) as admin,
                    COALESCE(SUM(instructor_commission_amount), 0) as instructor,
                    COUNT(*) as sales_count
                ',
                )
                ->first();

            $revenue =
                (float) ($stats->admin ?? 0) +
                (float) ($stats->instructor ?? 0);
            $profit = (float) ($stats->instructor ?? 0);

            $monthlyData[] = [
                "name" =>
                    $day .
                    " " .
                    Carbon::createFromDate($year, $month, 1)->format("M"),
                "sales" => (int) ($stats->sales_count ?? 0),
                "revenue" => $revenue,
                "profit" => $profit,
            ];
        }

        return $monthlyData;
    }

    /**
     * Get weekly sales data for a specific course (current week daily breakdown)
     */
    private function getCourseWeeklySalesData($courseId, $year, $week)
    {
        $weekDays = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
        $weeklyData = [];

        $startOfWeek = Carbon::now()->startOfWeek();

        for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
            $currentDate = $startOfWeek->copy()->addDays($dayIndex);
            $startDate = $currentDate->copy()->startOfDay();
            $endDate = $currentDate->copy()->endOfDay();

            // Get earnings from Commission table for this course
            $stats = Commission::where("course_id", $courseId)
                ->whereBetween("created_at", [$startDate, $endDate])
                ->selectRaw(
                    '
                    COALESCE(SUM(admin_commission_amount), 0) as admin,
                    COALESCE(SUM(instructor_commission_amount), 0) as instructor,
                    COUNT(*) as sales_count
                ',
                )
                ->first();

            $revenue =
                (float) ($stats->admin ?? 0) +
                (float) ($stats->instructor ?? 0);
            $profit = (float) ($stats->instructor ?? 0);

            $weeklyData[] = [
                "name" => $weekDays[$dayIndex],
                "sales" => (int) ($stats->sales_count ?? 0),
                "revenue" => $revenue,
                "profit" => $profit,
            ];
        }

        return $weeklyData;
    }

    /**
     * Get course content statistics
     */
    private function getCourseContentStatistics($courseId)
    {
        try {
            $course = Course::with([
                "chapters.lectures",
                "chapters.quizzes",
                "chapters.assignments",
            ])->find($courseId);

            if (!$course) {
                return [];
            }

            $totalChapters = $course->chapters->count();
            $totalLectures = $course->chapters->sum(
                static fn($chapter) => $chapter->lectures->count(),
            );
            $totalQuizzes = $course->chapters->sum(
                static fn($chapter) => $chapter->quizzes->count(),
            );
            $totalAssignments = $course->chapters->sum(
                static fn($chapter) => $chapter->assignments->count(),
            );

            // Calculate total duration
            $totalDuration = $course->chapters->sum(
                static fn($chapter) => $chapter->lectures->sum("duration"),
            );

            $hours = floor($totalDuration / 3600);
            $minutes = floor(($totalDuration % 3600) / 60);

            return [
                "chapters" => $totalChapters,
                "lectures" => $totalLectures,
                "quizzes" => $totalQuizzes,
                "assignments" => $totalAssignments,
                "total_duration" => [
                    "seconds" => $totalDuration,
                    "formatted" => $hours . "h " . $minutes . "m",
                ],
                "content_breakdown" => [
                    "lectures_percentage" =>
                        $totalLectures > 0
                            ? round(
                                ($totalLectures /
                                    ($totalLectures +
                                        $totalQuizzes +
                                        $totalAssignments)) *
                                    100,
                                1,
                            )
                            : 0,
                    "quizzes_percentage" =>
                        $totalQuizzes > 0
                            ? round(
                                ($totalQuizzes /
                                    ($totalLectures +
                                        $totalQuizzes +
                                        $totalAssignments)) *
                                    100,
                                1,
                            )
                            : 0,
                    "assignments_percentage" =>
                        $totalAssignments > 0
                            ? round(
                                ($totalAssignments /
                                    ($totalLectures +
                                        $totalQuizzes +
                                        $totalAssignments)) *
                                    100,
                                1,
                            )
                            : 0,
                ],
            ];
        } catch (Exception) {
            return [
                "chapters" => 0,
                "lectures" => 0,
                "quizzes" => 0,
                "assignments" => 0,
                "total_duration" => ["seconds" => 0, "formatted" => "0h 0m"],
                "content_breakdown" => [
                    "lectures_percentage" => 0,
                    "quizzes_percentage" => 0,
                    "assignments_percentage" => 0,
                ],
                "error" => "Unable to fetch content statistics",
            ];
        }
    }

    /**
     * Get course statistics for the authenticated user
     */
    private function getCourseStatistics($userId, $teamUser = null)
    {
        // Build base query
        $baseQuery = Course::where("user_id", $userId);

        // If team user is provided, filter courses where team user is assigned as instructor
        if ($teamUser) {
            $baseQuery->whereHas("instructors", static function ($q) use (
                $teamUser,
            ): void {
                $q->where("users.id", $teamUser->id);
            });
        }

        // Get total courses count
        $totalCourses = (clone $baseQuery)->count();

        // Get courses by status
        $draftCourses = (clone $baseQuery)->where("status", "draft")->count();

        $pendingCourses = (clone $baseQuery)
            ->where("status", "pending")
            ->count();

        $publishCourses = (clone $baseQuery)
            ->where("status", "publish")
            ->count();

        // Get courses by approval status
        $approvedCourses = (clone $baseQuery)
            ->where("approval_status", "approved")
            ->count();

        $rejectedCourses = (clone $baseQuery)
            ->where(static function ($query): void {
                $query
                    ->where("approval_status", "rejected")
                    ->orWhere("status", "rejected");
            })
            ->count();

        // Get active courses (is_active = 1)
        $activeCourses = (clone $baseQuery)->where("is_active", 1)->count();

        return [
            "total_courses" => $totalCourses,
            "publish" => $publishCourses,
            "pending" => $pendingCourses,
            "rejected" => $rejectedCourses,
            "draft" => $draftCourses,
            "approved" => $approvedCourses,
            "active" => $activeCourses,
        ];
    }

    public function deleteCoursePermanently(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "id" => "required|exists:courses,id",
        ]);
        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }
        try {
            $course = Course::onlyTrashed()->findOrFail($request->id);
            $user = Auth::user();
            if (
                !$user->hasAnyRole(["Super Admin", "Supervisor", "Staff"]) &&
                $course->user_id !== $user->id
            ) {
                return ApiResponseService::errorResponse(
                    "You do not have permission to delete this course.",
                    null,
                    403,
                );
            }
            $course->forceDelete();
            ApiResponseService::successResponse(
                "Course permanently deleted successfully",
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> deleteCoursePermanently Method",
            );
            ApiResponseService::errorResponse(
                "Failed to permanently delete the course.",
            );
        }
    }

}
