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

trait ServesCourseReviews
{
    public function getSearchSuggestions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "query" => "nullable|string|max:255",
                "limit" => "nullable|integer|min:1|max:50",
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $query = $request->input("query", "");
            $limit = $request->input("limit", 10);
            $userId = Auth::id();
            $ipAddress = $request->ip();

            // Record search if query is provided
            if (!empty($query)) {
                SearchHistory::recordSearch($query, $userId, $ipAddress);
            }

            // Get recent searches
            $recentSearches = SearchHistory::getRecentSearches(
                $userId,
                $ipAddress,
                5,
            )->map(
                static fn($search) => [
                    "type" => "recent",
                    "text" => $search->query,
                    "query" => $search->query,
                    "icon" => "fas fa-history",
                    "search_count" => $search->search_count,
                    "last_searched" => $search->last_searched_at->diffForHumans(),
                ],
            );

            // Get category suggestions
            $categorySuggestions = Category::where("status", 1)
                ->when($query, static function ($q) use ($query): void {
                    $q->where("name", "LIKE", "%{$query}%");
                })
                ->select("name", "slug")
                ->limit($limit)
                ->get()
                ->map(
                    static fn($category) => [
                        "type" => "category",
                        "text" => $category->name,
                        "slug" => $category->slug,
                        "icon" => "fas fa-folder",
                    ],
                );

            // Get tag suggestions
            $tagSuggestions = Tag::where("is_active", 1)
                ->when($query, static function ($q) use ($query): void {
                    $q->where("tag", "LIKE", "%{$query}%");
                })
                ->select("tag", "slug")
                ->limit($limit)
                ->get()
                ->map(
                    static fn($tag) => [
                        "type" => "tag",
                        "text" => $tag->tag,
                        "slug" => $tag->slug,
                        "icon" => "fas fa-tag",
                    ],
                );

            // Get course suggestions with author and image
            $courseQuery = Course::where("is_active", true)
                ->where("status", "publish")
                ->where("approval_status", "approved")
                ->whereHas("chapters", static function ($chapterQuery): void {
                    $chapterQuery->where("is_active", true);
                })
                ->with(["user", "instructors"])
                ->when($query, static function ($q) use ($query): void {
                    $q->where("title", "LIKE", "%{$query}%");
                })
                ->select("id", "title", "slug", "thumbnail", "user_id");

            // Debug: Log the query and count
            Log::info("Course Query SQL: " . $courseQuery->toSql());
            Log::info("Course Query Count: " . $courseQuery->count());

            $courses = $courseQuery->limit($limit)->get();

            // If no published courses found, try to get any active courses
            if ($courses->isEmpty()) {
                $fallbackQuery = Course::where("is_active", true)
                    ->whereHas("chapters", static function (
                        $chapterQuery,
                    ): void {
                        $chapterQuery->where("is_active", true);
                    })
                    ->with(["user", "instructors"])
                    ->when($query, static function ($q) use ($query): void {
                        $q->where("title", "LIKE", "%{$query}%");
                    })
                    ->select("id", "title", "slug", "thumbnail", "user_id");

                Log::info(
                    "Fallback Course Query Count: " . $fallbackQuery->count(),
                );
                $courses = $fallbackQuery->limit($limit)->get();
            }

            $courseSuggestions = $courses->map(static function ($course) {
                // Get primary author (course creator or first instructor)
                $author = $course->user;
                if ($course->instructors->isNotEmpty()) {
                    $author = $course->instructors->first();
                }

                return [
                    "type" => "course",
                    "text" => $course->title,
                    "slug" => $course->slug,
                    "icon" => "fas fa-graduation-cap",
                    "author_name" => $author ? $author->name : "Unknown Author",
                    "course_image" => $course->thumbnail,
                    "course_id" => $course->id,
                ];
            });

            // If no query provided, return popular suggestions
            if (empty($query)) {
                $categorySuggestions = collect([
                    [
                        "type" => "category",
                        "text" => "UI / UX Design",
                        "slug" => "ui-ux-design",
                        "icon" => "fas fa-folder",
                    ],
                    [
                        "type" => "category",
                        "text" => "UX Research",
                        "slug" => "ux-research",
                        "icon" => "fas fa-folder",
                    ],
                ]);

                $tagSuggestions = collect([
                    [
                        "type" => "tag",
                        "text" => "Figma UI Design",
                        "slug" => "figma-ui-design",
                        "icon" => "fas fa-tag",
                    ],
                    [
                        "type" => "tag",
                        "text" => "Adobe XD Design",
                        "slug" => "adobe-xd-design",
                        "icon" => "fas fa-tag",
                    ],
                    [
                        "type" => "tag",
                        "text" => "UX Writing",
                        "slug" => "ux-writing",
                        "icon" => "fas fa-tag",
                    ],
                ]);

                // Get popular courses with author and image
                $popularCoursesQuery = Course::where("is_active", true)
                    ->where("status", "publish")
                    ->where("approval_status", "approved")
                    ->whereHas("chapters", static function (
                        $chapterQuery,
                    ): void {
                        $chapterQuery->where("is_active", true);
                    })
                    ->with(["user", "instructors"])
                    ->select("id", "title", "slug", "thumbnail", "user_id")
                    ->orderBy("id", "desc");

                $popularCourses = $popularCoursesQuery->limit($limit)->get();

                // If no published courses found, try to get any active courses
                if ($popularCourses->isEmpty()) {
                    $popularCourses = Course::where("is_active", true)
                        ->whereHas("chapters", static function (
                            $chapterQuery,
                        ): void {
                            $chapterQuery->where("is_active", true);
                        })
                        ->with(["user", "instructors"])
                        ->select("id", "title", "slug", "thumbnail", "user_id")
                        ->orderBy("id", "desc")
                        ->limit($limit)
                        ->get();
                }

                $courseSuggestions = $popularCourses->map(static function (
                    $course,
                ) {
                    // Get primary author (course creator or first instructor)
                    $author = $course->user;
                    if ($course->instructors->isNotEmpty()) {
                        $author = $course->instructors->first();
                    }

                    return [
                        "type" => "course",
                        "text" => $course->title,
                        "slug" => $course->slug,
                        "icon" => "fas fa-graduation-cap",
                        "author_name" => $author
                            ? $author->name
                            : "Unknown Author",
                        "course_image" => $course->thumbnail,
                        "course_id" => $course->id,
                    ];
                });
            }

            // Separate suggestions into arrays
            $topCourses = $courseSuggestions->take($limit);
            $otherSuggestions = $categorySuggestions
                ->concat($tagSuggestions)
                ->take($limit);

            $responseData = [
                "recent_searches" => $recentSearches,
                "top_courses" => $topCourses,
                "other_suggestions" => $otherSuggestions,
                "total_courses" => $topCourses->count(),
                "total_other" => $otherSuggestions->count(),
                "total_recent" => $recentSearches->count(),
                "query" => $query,
            ];

            ApiResponseService::successResponse(
                "Search suggestions retrieved successfully",
                $responseData,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getSearchSuggestions Method",
            );
            ApiResponseService::errorResponse();
        }
    }

    /**
     * Get course reviews with total, average, and user-specific review
     */
    public function getCourseReviews(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                "course_id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|max:255",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "sort_by" =>
                    "nullable|in:newest,oldest,highest_rating,lowest_rating",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Get authenticated user
            $user = Auth::user();

            // Check if course_id or slug is provided
            $course = null;
            $isSpecificCourse = false;

            if ($request->filled("course_id")) {
                $course = Course::where("id", $request->course_id)
                    ->where("is_active", true)
                    ->first();
                $isSpecificCourse = true;
            } elseif ($request->filled("slug")) {
                $course = Course::where("slug", $request->slug)
                    ->where("is_active", true)
                    ->first();
                $isSpecificCourse = true;
            }

            // If specific course requested but not found
            if ($isSpecificCourse && !$course) {
                return ApiResponseService::validationError(
                    "Course not found or not available",
                );
            }

            // Public list: approved reviews only. Do not hide 1–3 star reviews.
            $query = Rating::with(["user:id,name,profile", "rateable"])
                ->where("rateable_type", Course::class)
                ->where("status", "approved");

            if ($isSpecificCourse) {
                $query->where("rateable_id", $course->id);
            } else {
                $activeCourseIds = Course::where("is_active", true)->pluck("id");
                $query->whereIn("rateable_id", $activeCourseIds);
            }

            $sortBy = $request->sort_by ?? "newest";
            match ($sortBy) {
                "oldest" => $query->orderBy("created_at", "asc"),
                "highest_rating" => $query
                    ->orderBy("rating", "desc")
                    ->orderBy("created_at", "desc"),
                "lowest_rating" => $query
                    ->orderBy("rating", "asc")
                    ->orderBy("created_at", "desc"),
                default => $query->orderBy("created_at", "desc"),
            };

            if ($isSpecificCourse) {
                $stats = Rating::approvedStatistics(Course::class, $course->id);
                $totalReviews = $stats["total_reviews"];
                $averageRating = $stats["average_rating"];
                $ratingBreakdown = $stats["rating_breakdown"];
                $percentageBreakdown = $stats["percentage_breakdown"];
            } else {
                $totalReviews = 0;
                $averageRating = 0;
                $ratingBreakdown = [
                    "5_stars" => 0,
                    "4_stars" => 0,
                    "3_stars" => 0,
                    "2_stars" => 0,
                    "1_star" => 0,
                ];
                $percentageBreakdown = [
                    "5_stars" => 0,
                    "4_stars" => 0,
                    "3_stars" => 0,
                    "2_stars" => 0,
                    "1_star" => 0,
                ];
            }

            $perPage = $request->per_page ?? 15;
            $ratings = $query->paginate($perPage);

            $reviews = $ratings->map(
                fn($rating) => $rating->toPublicArray(!$isSpecificCourse),
            );

            // Get user's review if logged in (only for specific course)
            $myReview = null;
            if ($user && $isSpecificCourse) {
                $userRating = Rating::where("rateable_type", Course::class)
                    ->where("rateable_id", $course->id)
                    ->where("user_id", $user->id)
                    ->first();

                if ($userRating) {
                    $myReview = array_merge($userRating->toPublicArray(), [
                        "status" => $userRating->status ?? "pending",
                        "can_edit" => true,
                    ]);
                }
            }

            // Update pagination data with formatted reviews
            $ratings->setCollection($reviews);

            $response = [
                "course" => $isSpecificCourse
                    ? [
                        "id" => $course->id,
                        "title" => $course->title,
                        "slug" => $course->slug,
                    ]
                    : null,
                "statistics" => [
                    "total_reviews" => $totalReviews,
                    "average_rating" => $averageRating,
                    "rating_breakdown" => $ratingBreakdown,
                    "percentage_breakdown" => $percentageBreakdown,
                ],
                "my_review" => $myReview,
                "reviews" => $ratings,
            ];

            return ApiResponseService::successResponse(
                "Course reviews retrieved successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getCourseReviews Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve course reviews",
            );
        }
    }

    /**
     * Get instructor reviews with ID or slug parameter and pagination
     */
    public function getInstructorReviews(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                "instructor_id" => "nullable|exists:instructors,id",
                "slug" => "nullable|string|max:255",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "sort_by" =>
                    "nullable|in:newest,oldest,highest_rating,lowest_rating",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Get instructor by ID or slug
            $instructor = null;
            if ($request->filled("instructor_id")) {
                $instructor = Instructor::where(
                    "id",
                    $request->instructor_id,
                )->first();
            } elseif ($request->filled("slug")) {
                // Find user by slug first, then get their instructor record
                $instructorUser = User::where("slug", $request->slug)->first();
                if ($instructorUser) {
                    $instructor = Instructor::where(
                        "user_id",
                        $instructorUser->id,
                    )->first();
                }
            } else {
                return ApiResponseService::validationError(
                    "Either instructor_id or slug is required",
                );
            }

            if (!$instructor) {
                return ApiResponseService::validationError(
                    "Instructor not found or not available",
                );
            }

            // Get authenticated user
            $user = Auth::user();

            // Build query for ratings — only approved reviews are shown publicly
            $query = Rating::with(["user:id,name,profile"])
                ->where("rateable_type", Instructor::class)
                ->where("rateable_id", $instructor->id)
                ->where("status", "approved");

            $sortBy = $request->sort_by ?? "newest";
            $this->applySorting($query, $sortBy);

            $perPage = $request->per_page ?? 15;
            $page = $request->page ?? 1;

            $stats = Rating::approvedStatistics(Instructor::class, $instructor->id);
            $totalReviews = $stats["total_reviews"];
            $averageRating = $stats["average_rating"];
            $ratingBreakdown = $stats["rating_breakdown"];
            $percentageBreakdown = $stats["percentage_breakdown"];

            $ratings = $query->paginate($perPage, ["*"], "page", $page);
            $reviews = $ratings->map(fn($rating) => $rating->toPublicArray());

            $myReview = null;
            if ($user) {
                $userRating = Rating::where("rateable_type", Instructor::class)
                    ->where("rateable_id", $instructor->id)
                    ->where("user_id", $user->id)
                    ->first();

                if ($userRating) {
                    $myReview = array_merge($userRating->toPublicArray(), [
                        "status" => $userRating->status ?? "pending",
                        "can_edit" => true,
                    ]);
                }
            }

            $ratings->setCollection($reviews);

            $instructorUser = User::find($instructor->user_id);

            $response = [
                "instructor" => [
                    "id" => $instructor->id,
                    "name" => $instructorUser?->name ?? "مدرب",
                    "slug" => $instructorUser?->slug,
                    "profile" => $instructorUser?->profile,
                ],
                "statistics" => [
                    "total_reviews" => $totalReviews,
                    "average_rating" => $averageRating,
                    "rating_breakdown" => $ratingBreakdown,
                    "percentage_breakdown" => $percentageBreakdown,
                ],
                "my_review" => $myReview,
                "reviews" => $ratings,
            ];

            return ApiResponseService::successResponse(
                "Instructor reviews retrieved successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getInstructorReviews Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve instructor reviews",
            );
        }
    }

    /**
     * Get reviews with pagination based on different parameters
     * - If id or slug passed: get course reviews
     * - If team_user_slug passed: get team member instructor reviews
     * - If nothing passed: get authenticated user's instructor reviews
     */
    public function getReviews(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|max:255",
                "team_user_slug" => "nullable|string|max:255",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "sort_by" =>
                    "nullable|in:newest,oldest,highest_rating,lowest_rating",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $user = Auth::user();
            $perPage = $request->per_page ?? 15;
            $sortBy = $request->sort_by ?? "newest";

            // Scenario 1: Course reviews (id or slug provided)
            if ($request->filled("id") || $request->filled("slug")) {
                return $this->getCourseReviews($request);
            }

            // Scenario 2: Team member instructor reviews (team_user_slug provided)
            if ($request->filled("team_user_slug")) {
                return $this->getTeamMemberInstructorReviews($request, $user);
            }

            // Scenario 3: Authenticated user's instructor reviews (no parameters)
            return $this->getAuthenticatedUserInstructorReviews(
                $request,
                $user,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getReviews Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve reviews",
            );
        }
    }

    /**
     * Get team member instructor reviews
     */
    private function getTeamMemberInstructorReviews(Request $request, $user)
    {
        try {
            $teamUserSlug = $request->team_user_slug;

            // Find team user by slug
            $teamUser = User::where("slug", $teamUserSlug)->first();

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

            $teamUserInstructorDetails = $teamUser->instructor_details ?? null;
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

                $courseIds = Course::where("user_id", $teamUser->id)
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

                $courseIds = Course::where("user_id", $user->id)
                    ->whereIn("id", $assignedCourseIds)
                    ->pluck("id")
                    ->toArray();
            }

            if (empty($courseIds)) {
                return ApiResponseService::successResponse(
                    "No assigned courses found",
                    [
                        "reviews" => [],
                        "statistics" => [
                            "total_reviews" => 0,
                            "average_rating" => 0,
                            "rating_breakdown" => [
                                "5_stars" => 0,
                                "4_stars" => 0,
                                "3_stars" => 0,
                                "2_stars" => 0,
                                "1_star" => 0,
                            ],
                        ],
                        "pagination" => $this->replacePaginationFormat(
                            [],
                            1,
                            15,
                            0,
                        ),
                    ],
                );
            }

            // Build query for course ratings (only for assigned courses)
            $query = Rating::with(["user"])
                ->where("rateable_type", Course::class)
                ->whereIn("rateable_id", $courseIds);

            // Apply sorting
            $this->applySorting($query, $request->sort_by ?? "newest");

            // Get all ratings for statistics (from assigned courses)
            $allRatings = Rating::where("rateable_type", Course::class)
                ->whereIn("rateable_id", $courseIds)
                ->get();

            $totalReviews = $allRatings->count();
            $averageRating =
                $totalReviews > 0 ? round($allRatings->avg("rating"), 1) : 0;

            // Calculate rating breakdown
            $ratingBreakdown = [
                "5_stars" => $allRatings->where("rating", 5)->count(),
                "4_stars" => $allRatings->where("rating", 4)->count(),
                "3_stars" => $allRatings->where("rating", 3)->count(),
                "2_stars" => $allRatings->where("rating", 2)->count(),
                "1_star" => $allRatings->where("rating", 1)->count(),
            ];

            // Calculate percentage breakdown
            $percentageBreakdown = [
                "5_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["5_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "4_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["4_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "3_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["3_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "2_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["2_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "1_star" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["1_star"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
            ];

            // Paginate results
            $perPage = $request->per_page ?? 15;
            $ratings = $query->paginate($perPage);

            // Format reviews
            $reviews = $ratings->map(fn($rating) => $rating->toPublicArray(true));

            // Update pagination data with formatted reviews
            $ratings->setCollection($reviews);

            $response = [
                "team_user" => [
                    "id" => $teamUser->id,
                    "name" => $teamUser->name,
                    "slug" => $teamUser->slug,
                    "profile" => $teamUser->profile ?? null,
                ],
                "statistics" => [
                    "total_reviews" => $totalReviews,
                    "average_rating" => $averageRating,
                    "rating_breakdown" => $ratingBreakdown,
                    "percentage_breakdown" => $percentageBreakdown,
                ],
                "assigned_courses_count" => count($courseIds),
                "reviews" => $ratings,
            ];

            return ApiResponseService::successResponse(
                "Team member course reviews retrieved successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getTeamMemberInstructorReviews Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve team member instructor reviews",
            );
        }
    }

    /**
     * Get authenticated user's instructor reviews
     */
    private function getAuthenticatedUserInstructorReviews(
        Request $request,
        $user,
    ) {
        try {
            // Check if user is instructor
            if (!$user->hasRole(config("constants.SYSTEM_ROLES.INSTRUCTOR"))) {
                return ApiResponseService::validationError(
                    "You are not an instructor",
                );
            }

            // Get instructor record
            $instructor = Instructor::where("user_id", $user->id)->first();
            if (!$instructor) {
                return ApiResponseService::validationError(
                    "Instructor profile not found",
                );
            }

            // Build query for instructor ratings
            $query = Rating::with(["user"])
                ->where("rateable_type", Instructor::class)
                ->where("rateable_id", $instructor->id);

            // Apply sorting
            $this->applySorting($query, $request->sort_by ?? "newest");

            // Get all ratings for statistics
            $allRatings = Rating::where("rateable_type", Instructor::class)
                ->where("rateable_id", $instructor->id)
                ->get();

            $totalReviews = $allRatings->count();
            $averageRating =
                $totalReviews > 0 ? round($allRatings->avg("rating"), 1) : 0;

            // Calculate rating breakdown
            $ratingBreakdown = [
                "5_stars" => $allRatings->where("rating", 5)->count(),
                "4_stars" => $allRatings->where("rating", 4)->count(),
                "3_stars" => $allRatings->where("rating", 3)->count(),
                "2_stars" => $allRatings->where("rating", 2)->count(),
                "1_star" => $allRatings->where("rating", 1)->count(),
            ];

            // Calculate percentage breakdown
            $percentageBreakdown = [
                "5_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["5_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "4_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["4_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "3_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["3_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "2_stars" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["2_stars"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
                "1_star" =>
                    $totalReviews > 0
                        ? round(
                            ($ratingBreakdown["1_star"] / $totalReviews) * 100,
                            1,
                        )
                        : 0,
            ];

            // Paginate results
            $perPage = $request->per_page ?? 15;
            $ratings = $query->paginate($perPage);

            // Format reviews
            $reviews = $ratings->map(fn($rating) => $rating->toPublicArray());

            // Update pagination data with formatted reviews
            $ratings->setCollection($reviews);

            $response = [
                "instructor" => [
                    "id" => $instructor->id,
                    "user_id" => $instructor->user_id,
                    "type" => $instructor->type,
                    "status" => $instructor->status,
                ],
                "statistics" => [
                    "total_reviews" => $totalReviews,
                    "average_rating" => $averageRating,
                    "rating_breakdown" => $ratingBreakdown,
                    "percentage_breakdown" => $percentageBreakdown,
                ],
                "reviews" => $ratings,
            ];

            return ApiResponseService::successResponse(
                "Your instructor reviews retrieved successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getAuthenticatedUserInstructorReviews Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve your instructor reviews",
            );
        }
    }

    /**
     * Get course discussions for instructor panel
     * Supports course id or slug with pagination and search
     */
    public function getDiscussion(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                "id" => "nullable|exists:courses,id",
                "slug" => "nullable|string|max:255",
                "search" => "nullable|string|max:255",
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "sort_by" => "nullable|in:newest,oldest,most_replies",
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
            $course = null;
            if ($request->filled("id")) {
                $course = Course::where("id", $request->id)->first();
            } elseif ($request->filled("slug")) {
                $course = Course::where("slug", $request->slug)->first();
            } else {
                return ApiResponseService::validationError(
                    "Either course id or slug is required",
                );
            }

            if (!$course) {
                return ApiResponseService::validationError("Course not found");
            }

            // Check if user is instructor and has access to this course
            $user = Auth::user();
            if (!$user->hasRole(config("constants.SYSTEM_ROLES.INSTRUCTOR"))) {
                return ApiResponseService::validationError(
                    "You are not authorized to view discussions",
                );
            }

            // Check if instructor owns this course
            $instructor = Instructor::where("user_id", $user?->id)->first();
            if (!$instructor) {
                return ApiResponseService::validationError(
                    "Instructor profile not found",
                );
            }

            $hasAccess = Course::where("id", $course->id)
                ->where("user_id", $user->id)
                ->exists();

            if (!$hasAccess) {
                return ApiResponseService::validationError(
                    "You do not have access to this course discussions",
                );
            }

            // Build query for discussions
            $query = CourseDiscussion::with(["user", "replies.user"])
                ->where("course_id", $course->id)
                ->whereNull("parent_id"); // Only main discussions, not replies

            // Apply search filter
            if ($request->filled("search")) {
                $searchTerm = $request->search;
                $query->where(static function ($q) use ($searchTerm): void {
                    $q->where("message", "like", "%{$searchTerm}%")->orWhereHas(
                        "user",
                        static function ($userQuery) use ($searchTerm): void {
                            $userQuery->where(
                                "name",
                                "like",
                                "%{$searchTerm}%",
                            );
                        },
                    );
                });
            }

            // Apply sorting
            $sortBy = $request->sort_by ?? "newest";
            match ($sortBy) {
                "oldest" => $query->orderBy("created_at", "asc"),
                "most_replies" => $query
                    ->withCount("replies")
                    ->orderBy("replies_count", "desc"),
                default => $query->orderBy("created_at", "desc"),
            };

            // Get total count for statistics
            $totalDiscussions = CourseDiscussion::where(
                "course_id",
                $course->id,
            )
                ->whereNull("parent_id")
                ->count();

            // Paginate results
            $perPage = $request->per_page ?? 15;
            $discussions = $query->paginate($perPage);

            // Format discussions
            $formattedDiscussions = $discussions->map(function ($discussion) {
                $replyCount = $discussion->replies->count();

                return [
                    "id" => $discussion->id,
                    "message" => $discussion->message,
                    "author" => [
                        "id" => $discussion->user->id ?? null,
                        "name" => $discussion->user->name ?? "Anonymous User",
                        "avatar" =>
                            $discussion->user->profile ??
                            "https://via.placeholder.com/40x40/" .
                                substr(
                                    md5($discussion->user->name ?? "user"),
                                    0,
                                    6,
                                ) .
                                "/000000?text=" .
                                substr($discussion->user->name ?? "U", 0, 1),
                        "email" => $discussion->user->email ?? null,
                    ],
                    "created_at" => $discussion->created_at->format("M d, Y"),
                    "timestamp" => $discussion->created_at->toIso8601String(),
                    "time_ago" => $this->getTimeAgo($discussion->created_at),
                    "reply_count" => $replyCount,
                    "replies" => $discussion->replies->map(
                        fn($reply) => [
                            "id" => $reply->id,
                            "message" => $reply->message,
                            "author" => [
                                "id" => $reply->user->id ?? null,
                                "name" =>
                                    $reply->user->name ?? "Anonymous User",
                                "avatar" =>
                                    $reply->user->profile ??
                                    "https://via.placeholder.com/40x40/" .
                                        substr(
                                            md5($reply->user->name ?? "user"),
                                            0,
                                            6,
                                        ) .
                                        "/000000?text=" .
                                        substr($reply->user->name ?? "U", 0, 1),
                                "email" => $reply->user->email ?? null,
                            ],
                            "created_at" => $reply->created_at->format(
                                "M d, Y",
                            ),
                            "timestamp" => $reply->created_at->toIso8601String(),
                            "time_ago" => $this->getTimeAgo($reply->created_at),
                        ],
                    ),
                ];
            });

            // Update pagination data with formatted discussions
            $discussions->setCollection($formattedDiscussions);

            $response = [
                "course" => [
                    "id" => $course->id,
                    "title" => $course->title,
                    "slug" => $course->slug,
                ],
                "statistics" => [
                    "total_discussions" => $totalDiscussions,
                    "search_term" => $request->search ?? null,
                ],
                "discussions" => $discussions,
            ];

            return ApiResponseService::successResponse(
                "Course discussions retrieved successfully",
                $response,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getDiscussion Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to retrieve course discussions",
            );
        }
    }

    /**
     * Reply to course discussion (Instructor only)
     */
    public function replyDiscussion(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make(
                $request->all(),
                [
                    "discussion_id" => "required|exists:course_discussions,id",
                    "message" => "required|string|max:1000",
                ],
                [
                    "discussion_id.required" => "Discussion ID is required",
                    "discussion_id.exists" => "Discussion not found",
                    "message.required" => "Reply message is required",
                    "message.string" => "Reply message must be a string",
                    "message.max" =>
                        "Reply message cannot exceed 1000 characters",
                ],
            );

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Get the discussion
            $discussion = CourseDiscussion::with("course")->find(
                $request->discussion_id,
            );
            if (!$discussion) {
                return ApiResponseService::validationError(
                    "Discussion not found",
                );
            }

            // Check if user is instructor
            $user = Auth::user();
            if (!$user->hasRole(config("constants.SYSTEM_ROLES.INSTRUCTOR"))) {
                return ApiResponseService::validationError(
                    "Only instructors can reply to discussions",
                );
            }

            // Check if instructor owns this course
            $hasAccess = Course::where("id", $discussion->course_id)
                ->where("user_id", $user?->id)
                ->exists();

            if (!$hasAccess) {
                return ApiResponseService::validationError(
                    "You do not have access to reply to this discussion",
                );
            }

            // Create the reply (instructor replies may auto-approve per business logic)
            $reply = CourseDiscussion::create([
                "course_id" => $discussion->course_id,
                "user_id" => $user->id,
                "parent_id" => $discussion->id,
                "message" => $request->message,
                "is_instructor_reply" => true,
                "status" => "pending", // Forced pending per admin request
            ]);

            // Notify admins
            $admins = \App\Models\User::role(["Super Admin", "Admin"])->get();
            \Illuminate\Support\Facades\Notification::send(
                $admins,
                new \App\Notifications\AdminNewReviewNotification(
                    $reply,
                    "comment",
                ),
            );

            // Load the reply with user data
            $reply->load("user");

            // Format the response
            $formattedReply = [
                "id" => $reply->id,
                "message" => $reply->message,
                "author" => [
                    "id" => $reply->user->id,
                    "name" => $reply->user->name,
                    "avatar" =>
                        $reply->user->profile ??
                        "https://via.placeholder.com/40x40/" .
                            substr(md5((string) $reply->user->name), 0, 6) .
                            "/000000?text=" .
                            substr((string) $reply->user->name, 0, 1),
                    "email" => $reply->user->email,
                    "is_instructor" => true,
                ],
                "created_at" => $reply->created_at->format("M d, Y"),
                "timestamp" => $reply->created_at->toIso8601String(),
                "time_ago" => $this->getTimeAgo($reply->created_at),
                "is_instructor_reply" => true,
            ];

            return ApiResponseService::successResponse(
                "Reply posted successfully",
                $formattedReply,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> replyDiscussion Method",
            );

            return ApiResponseService::errorResponse("Failed to post reply");
        }
    }

    /**
     * Apply sorting to query
     */
    private function applySorting($query, $sortBy)
    {
        match ($sortBy) {
            "oldest" => $query->orderBy("created_at", "asc"),
            "highest_rating" => $query->orderBy("rating", "desc"),
            "lowest_rating" => $query->orderBy("rating", "asc"),
            default => $query->orderBy("created_at", "desc"),
        };
    }

    /**
     * Calculate instructor profile completion percentage
     */
    private function calculateInstructorProfileCompletion($instructorId)
    {
        try {
            $user = User::find($instructorId);
            $instructor = Instructor::where("user_id", $instructorId)->first();

            if (!$user || !$instructor) {
                return [
                    "percentage" => 0,
                    "completed_fields" => 0,
                    "total_fields" => 12,
                    "missing_fields" => [],
                ];
            }

            $completedFields = 0;
            $totalFields = 12;
            $missingFields = [];

            // User profile fields (4 fields)
            if (!empty($user->name)) {
                $completedFields++;
            } else {
                $missingFields[] = "Name";
            }

            if (!empty($user->email)) {
                $completedFields++;
            } else {
                $missingFields[] = "Email";
            }

            if (!empty($user->mobile)) {
                $completedFields++;
            } else {
                $missingFields[] = "Mobile";
            }

            if (!empty($user->profile)) {
                $completedFields++;
            } else {
                $missingFields[] = "Profile Photo";
            }

            // Instructor personal details (6 fields)
            $personalDetails = $instructor->personal_details;
            if ($personalDetails) {
                if (!empty($personalDetails->about_me)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "About Me";
                }

                if (!empty($personalDetails->qualification)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "Qualification";
                }

                if (!empty($personalDetails->years_of_experience)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "Years of Experience";
                }

                if (!empty($personalDetails->skills)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "Skills";
                }

                if (!empty($personalDetails->team_name)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "Team Name";
                }

                if (!empty($personalDetails->team_logo)) {
                    $completedFields++;
                } else {
                    $missingFields[] = "Team Logo";
                }
            } else {
                // If no personal details record exists, all 6 fields are missing
                $missingFields = array_merge($missingFields, [
                    "About Me",
                    "Qualification",
                    "Years of Experience",
                    "Skills",
                    "Team Name",
                    "Team Logo",
                ]);
            }

            // Social media (1 field - at least one social media link)
            $socialMediaCount = $instructor->social_medias()->count();
            if ($socialMediaCount > 0) {
                $completedFields++;
            } else {
                $missingFields[] = "Social Media Links";
            }

            // ID Proof (1 field)
            if ($personalDetails && !empty($personalDetails->id_proof)) {
                $completedFields++;
            } else {
                $missingFields[] = "ID Proof";
            }

            $percentage = round(($completedFields / $totalFields) * 100);

            return [
                "percentage" => $percentage,
                "completed_fields" => $completedFields,
                "total_fields" => $totalFields,
                "missing_fields" => $missingFields,
                "is_complete" => $percentage >= 100,
                "completion_status" =>
                    $percentage >= 100 ? "Complete" : "Incomplete",
            ];
        } catch (Exception) {
            return [
                "percentage" => 0,
                "completed_fields" => 0,
                "total_fields" => 12,
                "missing_fields" => ["Error calculating completion"],
                "is_complete" => false,
                "completion_status" => "Error",
            ];
        }
    }

    /**
     * Generate course completion certificate
     */
}
