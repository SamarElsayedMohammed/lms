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
use App\Services\ContentAccessService;
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

trait ServesCourseCatalogDetail
{
    public function getCourse(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable",
                "course_id" => "nullable",
                "slug" => "nullable|string",
                "course_slug" => "nullable|string",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Eager load all necessary relations, including nested ones
            $courseQuery = Course::with([
                "category",
                "user.instructor_details.social_medias.social_media",
                "user.instructor_details.personal_details",
                "user.instructor_details.ratings.user",
                "learnings",
                "requirements",
                "tags",
                "language",
                "ratings.user", // Include ratings with user information
                "chapters" => static function ($q): void {
                    $q->with([
                        "lectures.resources", // Lectures and their resources
                        "resources", // Chapter-level resources
                        "assignments.resources", // Assignments and their resources
                        "quizzes" => static function ($quizQuery): void {
                            $quizQuery->with([
                                "resources",
                                "questions.options", // Quiz questions and their options
                            ]);
                        },
                    ]);
                },
            ])
                ->withAvg(["ratings" => static function ($q): void {
                    $q->where("status", "approved");
                }], "rating")
                ->withCount([
                    "ratings" => static function ($q): void {
                        $q->where("status", "approved");
                    },
                    "views",
                    "orderCourses" => static function ($q): void {
                        $q->whereHas("order", static function (
                            $orderQuery,
                        ): void {
                            $orderQuery->where("status", "completed");
                        });
                    },
                ]);

            $id = $request->input("id") ?? $request->input("course_id");
            $slug = $request->input("slug") ?? $request->input("course_slug");

            $course = null;
            if (!empty($id)) {
                $course = (clone $courseQuery)->where("id", $id)->first();
            } elseif (!empty($slug)) {
                $rawSlug = trim((string) $slug);
                $decodedSlug = urldecode($rawSlug);
                $course = (clone $courseQuery)->where("slug", $rawSlug)
                    ->orWhere("slug", $decodedSlug)
                    ->first();

                // If slug is numeric, also allow fallback to ID
                if (!$course && is_numeric($rawSlug)) {
                    $course = (clone $courseQuery)->where("id", (int) $rawSlug)->first();
                }
            } else {
                return ApiResponseService::validationError(
                    "Course id or slug is required",
                );
            }

            if (!$course) {
                return ApiResponseService::validationError("Course not found");
            }

            // Check if course is available (active, published, approved)
            $user = Auth::user() ?? Auth::guard("sanctum")->user();

            $isAdmin =
                $user &&
                $user
                    ->roles()
                    ->whereIn("name", [
                        config("constants.SYSTEM_ROLES.SUPER_ADMIN"),
                        config("constants.SYSTEM_ROLES.SUPERVISOR"),
                        config("constants.SYSTEM_ROLES.TEAM"),
                        config("constants.SYSTEM_ROLES.TEAM_INSTRUCTOR"),
                        config("constants.SYSTEM_ROLES.STAFF"),
                        config("constants.SYSTEM_ROLES.MODERATOR"),
                    ])
                    ->exists();

            $isOwner = $user && $course->user_id == $user->id;

            if (!$isAdmin && !$isOwner) {
                if (
                    $course->is_active != 1 ||
                    $course->status !== "publish" ||
                    $course->approval_status !== "approved" ||
                    ($course->user && $course->user->is_active != 1)
                ) {
                    return ApiResponseService::validationError(
                        "Course is not available",
                    );
                }
            }

            $isPurchased = false;
            $isWishlist = false;
            $hasAccess = false;
            $isSubscribed = false;

            if ($user) {
                $isWishlist = Wishlist::where("user_id", $user->id)
                    ->where("course_id", $course->id)
                    ->exists();
                $isSubscribed = $user->activeSubscription()->exists();
                $hasAccess = app(ContentAccessService::class)->canAccessCourse(
                    $user,
                    $course,
                );
                $isPurchased = $hasAccess;
            } elseif ($course->isFreeNow()) {
                $hasAccess = true;
                $isPurchased = true;
            }

            // Get user's curriculum completion tracking data
            $userCurriculumTracking = [];
            if ($user) {
                $chapterIds = $course->chapters->pluck("id")->toArray();
                $userCurriculumTracking = UserCurriculumTracking::where(
                    "user_id",
                    $user->id,
                )
                    ->whereIn("course_chapter_id", $chapterIds)
                    ->get()
                    ->groupBy(
                        static fn($item) => $item->course_chapter_id .
                            "_" .
                            $item->model_type .
                            "_" .
                            $item->model_id,
                    );
            }

            // Calculate total course duration and prepare chapters data
            $totalCourseDuration = 0; // in seconds

            // Helper function to check if curriculum item is completed
            $isItemCompleted = static function (
                $chapterId,
                $modelType,
                $modelId,
            ) use ($userCurriculumTracking) {
                if (empty($userCurriculumTracking)) {
                    return false;
                }
                $key = $chapterId . "_" . $modelType . "_" . $modelId;

                return isset($userCurriculumTracking[$key]) &&
                    $userCurriculumTracking[$key]->first()->status ===
                        "completed";
            };

            $chapters = [];

            foreach ($course->chapters as $chapter) {
                // Skip inactive chapters for duration and count calculations
                if ($chapter->is_active != 1) {
                    // Still add chapter data but with zero duration and counts
                    $chapterData = [
                        "id" => $chapter->id,
                        "course_id" => $chapter->course_id,
                        "title" => $chapter->title,
                        "slug" => $chapter->slug,
                        "description" => $chapter->description,
                        "is_active" => $chapter->is_active,
                        "chapter_order" => $chapter->chapter_order,
                        "lecture_count" => 0,
                        "duration" => 0,
                        "duration_formatted" => $this->formatDuration(0),
                        "total_content" => 0,
                        "lectures_count" => 0,
                        "quizzes_count" => 0,
                        "assignments_count" => 0,
                        "documents_count" => 0,
                        "curriculum" => [],
                        "created_at" => $chapter->created_at,
                        "updated_at" => $chapter->updated_at,
                        "locked" => !$isPurchased,
                    ];
                    $chapters[] = $chapterData;

                    continue;
                }

                $chapterDuration = 0; // in seconds
                $chapterLectureCount = 0;

                // Calculate chapter duration from active lectures only
                foreach ($chapter->lectures as $lecture) {
                    // Only count active lectures
                    if ($lecture->is_active != 1) {
                        continue;
                    }

                    $lectureDuration =
                        ($lecture->hours ?? 0) * 3600 +
                        ($lecture->minutes ?? 0) * 60 +
                        ($lecture->seconds ?? 0);
                    $chapterDuration += $lectureDuration;
                    $chapterLectureCount++;
                }

                $totalCourseDuration += $chapterDuration;

                // Create all content array for this chapter
                $allContent = collect();

                // Add lectures
                $lectures = $chapter->lectures->map(function ($lecture) use (
                    $chapter,
                    $isItemCompleted,
                    $request,
                    $hasAccess,
                    $isPurchased,
                ) {
                    $resource = new CourseChapterLectureResource(
                        $lecture,
                        $hasAccess,
                    );
                    $lectureData = $resource->toArray($request);

                    // Add completion and resources info
                    $lectureData["is_completed"] = $isItemCompleted(
                        $chapter->id,
                        CourseChapterLecture::class,
                        $lecture->id,
                    );
                    $lectureData["has_resources"] =
                        $lecture->resources->count() > 0;
                    $lectureData["resources"] = $lecture->resources->map(
                        static fn($resource) => [
                            "id" => $resource->id,
                            "title" => $resource->title,
                            "type" => $resource->type,
                            "file" => $isPurchased ? $resource->file : null,
                            "file_extension" => $resource->file_extension,
                            "url" => $isPurchased ? $resource->url : null,
                            "file_url" => $isPurchased
                                ? $resource->file_url
                                : null,
                            "order" => $resource->order,
                            "is_active" => $resource->is_active,
                        ],
                    );
                    $lectureData["created_at"] = $lecture->created_at;
                    $lectureData["updated_at"] = $lecture->updated_at;

                    return $lectureData;
                });
                $allContent = $allContent->merge($lectures);

                // Add quizzes
                $quizzes = $chapter->quizzes->map(
                    static fn($quiz) => [
                        "id" => $quiz->id,
                        "type" => "quiz",
                        "title" => $quiz->title,
                        "slug" => $quiz->slug,
                        "description" => $quiz->description,
                        "time_limit" => $quiz->time_limit,
                        "total_points" => $quiz->total_points,
                        "passing_score" => $quiz->passing_score,
                        "can_skip" => $quiz->can_skip,
                        "is_active" => $quiz->is_active,
                        "chapter_order" => $quiz->chapter_order,
                        "is_completed" => $isItemCompleted(
                            $chapter->id,
                            CourseChapterQuiz::class,
                            $quiz->id,
                        ),
                        "has_questions" => $quiz->questions->count() > 0,
                        "questions" => $isPurchased
                            ? $quiz->questions->map(
                                static fn($question) => [
                                    "id" => $question->id,
                                    "question" => $question->question,
                                    "points" => $question->points,
                                    "order" => $question->order,
                                    "is_active" => $question->is_active,
                                    "options" => $question->options->map(
                                        static fn($option) => [
                                            "id" => $option->id,
                                            "option" => $option->option,
                                            "order" => $option->order,
                                            "is_active" => $option->is_active,
                                        ],
                                    ),
                                ],
                            )
                            : [],
                        "created_at" => $quiz->created_at,
                        "updated_at" => $quiz->updated_at,
                    ],
                );
                $allContent = $allContent->merge($quizzes);

                // Add assignments
                $assignments = $chapter->assignments->map(static function (
                    $assignment,
                ) use ($chapter, $isItemCompleted, $user) {
                    // Get assignment submission status for the user
                    $submissionStatus = null;
                    $submissionId = null;
                    $submittedAt = null;

                    if ($user) {
                        $submission = UserAssignmentSubmission::where(
                            "course_chapter_assignment_id",
                            $assignment->id,
                        )
                            ->where("user_id", $user->id)
                            ->latest()
                            ->first();

                        if ($submission) {
                            $submissionStatus = $submission->status;
                            $submissionId = $submission->id;
                        }
                    }

                    return [
                        "id" => $assignment->id,
                        "type" => "assignment",
                        "title" => $assignment->title,
                        "slug" => $assignment->slug,
                        "description" => $assignment->description,
                        "instructions" => $assignment->instructions,
                        "max_file_size" => $assignment->max_file_size,
                        "allowed_file_types" => $assignment->allowed_file_types,
                        "media" => $assignment->media,
                        "media_extension" => $assignment->media_extension,
                        "media_url" =>
                            $isPurchased && $assignment->media
                                ? asset("storage/" . $assignment->media)
                                : null,
                        "points" => $assignment->points,
                        "can_skip" => $assignment->can_skip,
                        "is_active" => $assignment->is_active,
                        "chapter_order" => $assignment->chapter_order,
                        "is_completed" => $isItemCompleted(
                            $chapter->id,
                            CourseChapterAssignment::class,
                            $assignment->id,
                        ),
                        "submission_status" => $submissionStatus,
                        "submission_id" => $submissionId,
                        "is_submitted" => !is_null($submissionStatus),
                        "created_at" => $assignment->created_at,
                        "updated_at" => $assignment->updated_at,
                    ];
                });
                $allContent = $allContent->merge($assignments);

                // Add resources (documents)
                $resources = $chapter->resources->map(
                    static fn($resource) => [
                        "id" => $resource->id,
                        "type" => "document",
                        "title" => $resource->title,
                        "slug" => $resource->slug,
                        "description" => $resource->description,
                        "file" => $isPurchased ? $resource->file : null,
                        "file_extension" => $resource->file_extension,
                        "url" => $isPurchased ? $resource->url : null,
                        "is_active" => $resource->is_active,
                        "chapter_order" => $resource->chapter_order,
                        "is_completed" => $isItemCompleted(
                            $chapter->id,
                            CourseChapterResource::class,
                            $resource->id,
                        ),
                        "created_at" => $resource->created_at,
                        "updated_at" => $resource->updated_at,
                    ],
                );
                $allContent = $allContent->merge($resources);

                // Sort all content by chapter_order and filter active items only
                $sortedContent = $allContent
                    ->filter(
                        static fn($item) => ($item["is_active"] ?? true) ===
                            true,
                    )
                    ->sortBy("chapter_order")
                    ->values();

                $chapterData = [
                    "id" => $chapter->id,
                    "course_id" => $chapter->course_id,
                    "title" => $chapter->title,
                    "slug" => $chapter->slug,
                    "description" => $chapter->description,
                    "is_active" => $chapter->is_active,
                    "chapter_order" => $chapter->chapter_order,
                    "lecture_count" => $chapterLectureCount,
                    "duration" => $chapterDuration, // in seconds
                    "duration_formatted" => $this->formatDuration(
                        $chapterDuration,
                    ),
                    "total_content" => $sortedContent->count(),
                    "lectures_count" => $chapter->lectures
                        ->where("is_active", 1)
                        ->count(),
                    "quizzes_count" => $chapter->quizzes
                        ->where("is_active", 1)
                        ->count(),
                    "assignments_count" => $chapter->assignments
                        ->where("is_active", 1)
                        ->count(),
                    "documents_count" => $chapter->resources
                        ->where("is_active", 1)
                        ->count(),
                    "curriculum" => $sortedContent->toArray(), // Convert collection to array
                    "created_at" => $chapter->created_at,
                    "updated_at" => $chapter->updated_at,
                ];

                // Add locked status based on purchase
                $chapterData["locked"] = !$isPurchased;

                $chapters[] = $chapterData;
            }

            // Collect all curriculum items from all chapters (active only, ordered) with full item data
            $allCurriculumItems = collect();
            foreach ($chapters as $chapterIndex => $chapterData) {
                foreach ($chapterData["curriculum"] as $itemIndex => $item) {
                    $allCurriculumItems->push([
                        "id" => $item["id"],
                        "type" => $item["type"],
                        "chapter_order" => $chapterData["chapter_order"],
                        "item_order" => $item["chapter_order"] ?? 0,
                        "chapter_id" => $chapterData["id"],
                        "chapter_index" => $chapterIndex,
                        "item_index" => $itemIndex,
                    ]);
                }
            }

            // Sort all curriculum items by chapter_order first, then item_order
            $sortedAllCurriculum = $allCurriculumItems
                ->sortBy([["chapter_order", "asc"], ["item_order", "asc"]])
                ->values();

            // Add next_curriculum_id to each curriculum item in chapters
            foreach ($sortedAllCurriculum as $index => $curriculumItem) {
                $chapterIndex = $curriculumItem["chapter_index"];
                $itemIndex = $curriculumItem["item_index"];

                // Get next item
                $nextItem = null;
                if (isset($sortedAllCurriculum[$index + 1])) {
                    $nextItem = $sortedAllCurriculum[$index + 1];
                }

                // Add next_curriculum_id to the item in chapters array
                if (isset($chapters[$chapterIndex]["curriculum"][$itemIndex])) {
                    if ($nextItem) {
                        $chapters[$chapterIndex]["curriculum"][$itemIndex][
                            "next_curriculum_id"
                        ] = $nextItem["id"];
                        $chapters[$chapterIndex]["curriculum"][$itemIndex][
                            "next_curriculum_type"
                        ] = $nextItem["type"];
                    } else {
                        $chapters[$chapterIndex]["curriculum"][$itemIndex][
                            "next_curriculum_id"
                        ] = null;
                        $chapters[$chapterIndex]["curriculum"][$itemIndex][
                            "next_curriculum_type"
                        ] = null;
                    }
                }
            }

            // Prepare reviews data
            $reviews = $course->ratings->map(
                static fn($rating) => [
                    "id" => $rating->id,
                    "rating" => $rating->rating,
                    "review" => $rating->review,
                    "user_name" => $rating->user->name ?? "Anonymous",
                    "user_profile" => $rating->user->profile ?? null,
                    "created_at" => $rating->created_at,
                ],
            );

            // Calculate total lecture count (only active chapters and active lectures)
            $totalLectureCount = $course->chapters
                ->where("is_active", 1)
                ->sum(
                    static fn($chapter) => $chapter->lectures
                        ->where("is_active", 1)
                        ->count(),
                );

            // Calculate total curriculum count (lectures + quizzes + assignments + resources) - only active chapters and active items
            $totalCurriculumCount = $course->chapters
                ->where("is_active", 1)
                ->sum(static function ($chapter) {
                    $lectureCount = $chapter->lectures
                        ->where("is_active", 1)
                        ->count();
                    $quizCount = $chapter->quizzes
                        ->where("is_active", 1)
                        ->count();
                    $assignmentCount = $chapter->assignments
                        ->where("is_active", 1)
                        ->count();
                    $resourceCount = $chapter->resources
                        ->where("is_active", 1)
                        ->count();

                    return $lectureCount +
                        $quizCount +
                        $assignmentCount +
                        $resourceCount;
                });

            // Calculate completed curriculum count for the logged-in user
            $completedCurriculumCount = 0;
            $progressPercentage = 0;
            if ($user) {
                $chapterIds = $course->chapters->pluck("id")->toArray();

                $completedCurriculumCount = UserCurriculumTracking::where(
                    "user_id",
                    $user->id,
                )
                    ->whereIn("course_chapter_id", $chapterIds)
                    ->where("status", "completed")
                    ->count();

                // Calculate progress percentage
                if ($totalCurriculumCount > 0) {
                    $progressPercentage = round(
                        ($completedCurriculumCount / $totalCurriculumCount) *
                            100,
                        2,
                    );
                }
            }

            // Get instructor details
            $instructorDetails = null;
            if ($course->user) {
                $instructorType = $course->user->hasRole("Super Admin")
                    ? "admin"
                    : "instructor";

                // Get instructor type (individual/team) from instructor_details
                $instructorTypeValue = null; // Default to null
                $instructorName = $course->user->name; // Always set from user table
                $teamName = null;
                $instructorId = null; // For storing instructor table ID

                // If user is admin, instructor_type should be null
                if ($instructorType === "admin") {
                    $instructorTypeValue = null;
                } else {
                    // For instructors, get type from instructor_details
                    $instructorTypeValue = "individual"; // Default to individual for instructors

                    // Load instructor_details if not already loaded
                    if (!$course->user->relationLoaded("instructor_details")) {
                        $course->user->load("instructor_details");
                    }

                    if ($course->user->instructor_details) {
                        // Get type from instructor_details, default to 'individual' if null
                        $instructorTypeValue =
                            $course->user->instructor_details->type ??
                            "individual";
                        $instructorId = $course->user->instructor_details->id; // Get instructor_id

                        if ($instructorTypeValue === "team") {
                            // Load personal_details if not already loaded
                            if (
                                !$course->user->instructor_details->relationLoaded(
                                    "personal_details",
                                )
                            ) {
                                $course->user->instructor_details->load(
                                    "personal_details",
                                );
                            }
                            $teamName =
                                $course->user->instructor_details
                                    ->personal_details->team_name ?? null;
                        }
                    } else {
                        // If no instructor_details, try to get from Instructor model directly
                        $instructor = Instructor::where(
                            "user_id",
                            $course->user->id,
                        )->first();
                        if ($instructor && $instructor->type) {
                            $instructorTypeValue = $instructor->type;
                            $instructorId = $instructor->id; // Get instructor_id
                            if ($instructorTypeValue === "team") {
                                $instructor->load("personal_details");
                                $teamName =
                                    $instructor->personal_details->team_name ??
                                    null;
                            }
                        }
                    }
                }

                $instructorDetails = [
                    "id" => $course->user->id,
                    "instructor_id" => $instructorId,
                    "name" => $course->user->name,
                    "slug" => $course->user->slug,
                    "email" => $course->user->email,
                    "avatar" => $course->user->profile ?? null,
                    "type" => $instructorType,
                    "instructor_type" => $instructorTypeValue, // 'individual' or 'team'
                    "instructor_name" => $instructorName, // Always from user table name
                    "team_name" => $teamName, // Only if type is 'team'
                    "about_me" =>
                        $course->user->instructor_details->personal_details
                            ->about_me ?? null,
                    "qualification" =>
                        $course->user->instructor_details->personal_details
                            ->qualification ?? null,
                    "skills" =>
                        $course->user->instructor_details->personal_details
                            ->skills ?? null,
                    "preview_video" =>
                        $course->user->instructor_details->personal_details
                            ->preview_video ?? null,
                    "social_media" => $course->user->instructor_details
                        ? $course->user->instructor_details->social_medias->mapWithKeys(
                            static fn($socialMedia) => [
                                $socialMedia->title => $socialMedia->url,
                            ],
                        )
                        : null,
                    "reviews" => $course->user->instructor_details
                        ? [
                            "total_reviews" => $course->user->instructor_details->ratings->count(),
                            "average_rating" => round(
                                $course->user->instructor_details->ratings->avg(
                                    "rating",
                                ) ?? 0,
                                2,
                            ),
                        ]
                        : null,
                ];
            }

            // Get country code and tax percentage using service
            $price = $course->display_discount_price ?? $course->display_price;
            $totalTaxPercentage = null;
            if ($price != null && $price > 0) {
                $countryCode = $this->pricingService->getCountryCodeFromRequest(
                    $request,
                );
                $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry(
                    $countryCode,
                );
            }

            $coursePricingData = $this->pricingService->calculateCoursePricing(
                $course,
                taxPercentage: $totalTaxPercentage,
                countryCode: $countryCode ?? null,
            );

            $discountPercentage = 0;

            $response = [
                "id" => $course->id,
                "slug" => $course->slug,
                "title" => $course->title,
                "short_description" => $course->short_description,
                "description" => $course->description ?? null,
                "image" => $course->thumbnail,
                "category_id" => $course->category->id ?? null,
                "category_name" => $course->category->name ?? null,
                "level" => $course->level,
                "course_type" => $course->course_type,
                "sequential_access" => $course->sequential_access ?? true,
                "certificate_enabled" => $course->certificate_enabled ?? false,
                "certificate_fee" => $course->certificate_fee
                    ? (float) $course->certificate_fee
                    : null,
                "ratings" => $course->ratings_count ?? 0,
                "view_count" =>
                    ($course->views_count ?? 0) + ($course->initial_views ?? 0),
                "average_rating" =>
                    ($course->ratings_avg_rating ?? 0) > 0
                        ? round($course->ratings_avg_rating, 2)
                        : $course->initial_rating ?? 0,
                "author_name" => $course->user->name ?? null,
                ...$coursePricingData,
                "discount_percentage" => $discountPercentage,
                "is_purchased" => $isPurchased,
                "is_subscribed" => $isSubscribed,
                "is_enrolled" => $hasAccess,
                "has_access" => $hasAccess,
                "is_wishlist" => $isWishlist,
                "has_ai_assistant" => !empty(
                    $course->getRawOriginal("ai_knowledge_content")
                ),
                "enroll_students" =>
                    ($course->order_courses_count ?? 0) +
                    ($course->initial_students ?? 0),
                "last_updated" => $course->updated_at
                    ? $course->updated_at->format("Y-m-d H:i:s")
                    : null,
                // Meta Information
                "meta_title" => $course->meta_title ?? $course->title,
                "meta_description" =>
                    $course->meta_description ?? $course->short_description,
                "meta_image" => $course->meta_image ?? $course->thumbnail,
                "is_featured" => (bool) $course->is_featured,
                // Instructor Details
                "instructor" => $instructorDetails,
                // Course Content
                "learnings" => $course->learnings ?? [],
                "requirements" => $course->requirements ?? [],
                "reviews" => $reviews,
                "tags" => $course->tags ?? [],
                "language" => $course->language->name ?? null,
                "chapters" => $chapters,
                "chapter_count" => $course->chapters
                    ->where("is_active", 1)
                    ->count(),
                "lecture_count" => $totalLectureCount,
                "total_curriculum_count" => $totalCurriculumCount,
                "completed_curriculum_count" => $completedCurriculumCount,
                "progress_percentage" => $progressPercentage,
                "total_duration" => $totalCourseDuration, // in seconds
                "total_duration_formatted" => $this->formatDuration(
                    $totalCourseDuration,
                ),
                "preview_videos" => $this->getPreviewVideos(
                    $course,
                    $request,
                    $isPurchased,
                ),
            ];

            // Add current curriculum (last completed) for authenticated users
            if ($user) {
                // Get chapter IDs for this course
                $chapterIds = $course->chapters->pluck("id")->toArray();

                if (!empty($chapterIds)) {
                    // Use join to ensure chapter belongs to this course
                    $currentCurriculum = UserCurriculumTracking::where(
                        "user_id",
                        $user->id,
                    )
                        ->where("status", "completed")
                        ->whereIn("course_chapter_id", $chapterIds)
                        ->whereHas("chapter", static function ($query) use (
                            $course,
                        ): void {
                            $query->where("course_id", $course->id);
                        })
                        ->orderBy("completed_at", "desc")
                        ->first();

                    if ($currentCurriculum) {
                        // Get curriculum item details based on model_type
                        $curriculumItem = null;
                        $modelTypeShort = null;

                        switch ($currentCurriculum->model_type) {
                            case CourseChapterLecture::class:
                                $curriculumItem = CourseChapterLecture::find(
                                    $currentCurriculum->model_id,
                                );
                                $modelTypeShort = "lecture";
                                break;
                            case CourseChapterQuiz::class:
                                $curriculumItem = CourseChapterQuiz::find(
                                    $currentCurriculum->model_id,
                                );
                                $modelTypeShort = "quiz";
                                break;
                            case CourseChapterAssignment::class:
                                $curriculumItem = CourseChapterAssignment::find(
                                    $currentCurriculum->model_id,
                                );
                                $modelTypeShort = "assignment";
                                break;
                            case CourseChapterResource::class:
                                $curriculumItem = CourseChapterResource::find(
                                    $currentCurriculum->model_id,
                                );
                                $modelTypeShort = "resource";
                                break;
                        }

                        $response["current_curriculum"] = [
                            "id" => $currentCurriculum->id,
                            "curriculum_name" => $curriculumItem
                                ? $curriculumItem->title
                                : "Unknown",
                            "model_id" => $currentCurriculum->model_id,
                            "model_type" => $modelTypeShort,
                            "chapter_id" =>
                                $currentCurriculum->course_chapter_id,
                            "completed_at" => $currentCurriculum->completed_at,
                            "completed_at_human" => $currentCurriculum->completed_at
                                ? $currentCurriculum->completed_at->diffForHumans()
                                : null,
                        ];
                    } else {
                        // If course is purchased but no curriculum completed, return first curriculum
                        if (
                            $isPurchased &&
                            $sortedAllCurriculum->isNotEmpty()
                        ) {
                            $firstCurriculum = $sortedAllCurriculum->first();
                            $firstChapter =
                                $chapters[$firstCurriculum["chapter_index"]] ??
                                null;
                            $firstItem =
                                $firstChapter["curriculum"][
                                    $firstCurriculum["item_index"]
                                ] ?? null;

                            if ($firstItem) {
                                $response["current_curriculum"] = [
                                    "id" => $firstItem["id"],
                                    "curriculum_name" =>
                                        $firstItem["title"] ?? "Unknown",
                                    "model_id" => $firstItem["id"],
                                    "model_type" =>
                                        $firstItem["type"] ?? "lecture",
                                    "chapter_id" =>
                                        $firstCurriculum["chapter_id"],
                                    "completed_at" => null,
                                    "completed_at_human" => null,
                                ];
                            } else {
                                $response["current_curriculum"] = null;
                            }
                        } else {
                            $response["current_curriculum"] = null;
                        }
                    }
                } else {
                    // If course is purchased but no curriculum completed, return first curriculum
                    if ($isPurchased && $sortedAllCurriculum->isNotEmpty()) {
                        $firstCurriculum = $sortedAllCurriculum->first();
                        $firstChapter =
                            $chapters[$firstCurriculum["chapter_index"]] ??
                            null;
                        $firstItem =
                            $firstChapter["curriculum"][
                                $firstCurriculum["item_index"]
                            ] ?? null;

                        if ($firstItem) {
                            $response["current_curriculum"] = [
                                "id" => $firstItem["id"],
                                "curriculum_name" =>
                                    $firstItem["title"] ?? "Unknown",
                                "model_id" => $firstItem["id"],
                                "model_type" => $firstItem["type"] ?? "lecture",
                                "chapter_id" => $firstCurriculum["chapter_id"],
                                "completed_at" => null,
                                "completed_at_human" => null,
                            ];
                        } else {
                            $response["current_curriculum"] = null;
                        }
                    } else {
                        $response["current_curriculum"] = null;
                    }
                }
            } else {
                $response["current_curriculum"] = null;
            }

            // Add billing details for web developers (only when called with slug, not id)
            if ($request->filled("slug") && !$request->filled("id") && $user) {
                $billingDetails = $user->billingDetails;
                $response["billing_details"] = $billingDetails
                    ? $billingDetails->formatForApi()
                    : null;
            }

            return ApiResponseService::successResponse(
                "Course details retrieved successfully",
                $response,
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
     * Get preview videos including intro video and curriculum videos
     *
     * Generates a list of preview videos for a course, encrypting video URLs
     * (both local files and YouTube URLs) using the authenticated user's bearer token for security.
     *
     * @param  Course  $course  The course model instance
     * @param  Request  $request  The HTTP request containing bearer token
     * @return array<int, array{
     *     title: string,
     *     thumbnail: string|null,
     *     video: string,
     *     video_type?: string,
     *     type: string,
     *     chapter_title?: string,
     *     free_preview?: bool
     * }> Array of preview video data
     */
    private function getPreviewVideos(
        Course $course,
        Request $request,
        bool $isPurchased = false,
    ): array {
        $previewVideos = [];

        // Add intro video if exists
        if ($course->intro_video) {
            $previewVideos[] = [
                "title" => "Course Introduction",
                "thumbnail" => $course->thumbnail,
                "video" => $course->intro_video,
                "type" => "intro",
            ];
        }

        // Add curriculum videos (lectures with video content)
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->lectures as $lecture) {
                $isFreePreview = $lecture->free_preview ?? false;
                $isPaidCourse = $course->course_type === "paid";

                // Skip if it's a paid course and not marked as free preview and not purchased
                if ($isPaidCourse && !$isFreePreview && !$isPurchased) {
                    continue;
                }

                // Use resource to get lecture data
                // For preview videos list, we pass true to hasAccess so URLs are returned
                $resource = new CourseChapterLectureResource($lecture, true);
                $lectureData = $resource->toArray(request());

                // Only include if file_type is set (valid lecture content)
                if ($lectureData["file_type"] !== null) {
                    $previewVideos[] = [
                        "id" => $lectureData["id"],
                        "title" => $lectureData["title"],
                        "thumbnail" => $course->thumbnail ?? null,
                        "file_type" => $lectureData["file_type"],
                        "file_url" => $lectureData["file_url"],
                        "type" => "lecture",
                        "chapter_title" => $chapter->title,
                        "free_preview" => $isFreePreview,
                        "duration" => $lectureData["duration"],
                    ];
                }
            }
        }

        return $previewVideos;
    }

    /**
     * Track course view and return course view data
     */
    public function courseView(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "course_id" => "required|exists:courses,id",
                "user_id" => "nullable|exists:users,id",
                "ip_address" => "nullable|ip",
                "user_agent" => "nullable|string|max:500",
                "session_id" => "nullable|string|max:255",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $courseId = $request->course_id;
            $userId = $request->user_id ?? (Auth::check() ? Auth::id() : null);
            $ipAddress = $request->ip_address ?? $request->ip();
            $userAgent = $request->user_agent ?? $request->userAgent();

            // Get session ID from request or generate one
            $sessionId = $request->session_id;
            if (empty($sessionId)) {
                try {
                    $sessionId = $request->session()->getId();
                } catch (Exception) {
                    // Session not available, generate a unique identifier
                    $sessionId = uniqid("view_", true);
                }
            }

            // Get course details
            $course = Course::with([
                "category",
                "user",
                "learnings",
                "requirements",
                "tags",
                "language",
                "ratings" => static function ($q): void {
                    $q->where("status", "approved")->with("user");
                },
                "chapters.lectures", // Eager load lectures relationship
            ])
                ->withAvg(["ratings" => static function ($q): void {
                    $q->where("status", "approved");
                }], "rating")
                ->withCount(["ratings" => static function ($q): void {
                    $q->where("status", "approved");
                }])
                ->find($courseId);

            if (!$course) {
                return ApiResponseService::validationError("Course not found");
            }

            // Track the view

            CourseView::create([
                "course_id" => $courseId,
                "user_id" => $userId,
                "ip_address" => $ipAddress,
                "user_agent" => $userAgent,
                "session_id" => $sessionId,
                "viewed_at" => now(),
            ]);

            // Calculate total course duration
            $totalCourseDuration = 0;
            $totalLectureCount = 0;
            $chapters = [];

            foreach ($course->chapters as $chapter) {
                $chapterDuration = 0;
                $chapterLectureCount = 0;

                foreach ($chapter->lectures as $lecture) {
                    $lectureDuration =
                        ($lecture->hours ?? 0) * 3600 +
                        ($lecture->minutes ?? 0) * 60 +
                        ($lecture->seconds ?? 0);
                    $chapterDuration += $lectureDuration;
                    $chapterLectureCount++;
                }

                $totalCourseDuration += $chapterDuration;
                $totalLectureCount += $chapterLectureCount;

                $chapters[] = [
                    "id" => $chapter->id,
                    "title" => $chapter->title,
                    "description" => $chapter->description,
                    "order" => $chapter->chapter_order,
                    "lecture_count" => $chapterLectureCount,
                    "duration" => $chapterDuration,
                    "duration_formatted" => $this->formatDuration(
                        $chapterDuration,
                    ),
                ];
            }

            // Prepare reviews data
            $reviews = $course->ratings->map(
                static fn($rating) => [
                    "id" => $rating->id,
                    "rating" => $rating->rating,
                    "review" => $rating->review,
                    "user_name" => $rating->user->name ?? "Anonymous",
                    "user_profile" => $rating->user->profile ?? null,
                    "created_at" => $rating->created_at,
                ],
            );

            // Check if user has purchased the course
            $isPurchased = false;
            if ($userId) {
                // Get latest completed order for this course
                $latestOrderCourse = OrderCourse::whereHas(
                    "order",
                    static function ($q) use ($userId): void {
                        $q->where("user_id", $userId)->where(
                            "status",
                            "completed",
                        );
                    },
                )
                    ->where("course_id", $courseId)
                    ->with("order")
                    ->orderBy("created_at", "desc")
                    ->first();

                if ($latestOrderCourse) {
                    $latestOrderDate =
                        $latestOrderCourse->order->created_at ??
                        $latestOrderCourse->created_at;

                    // Check if there's an approved refund for this course
                    $approvedRefund = RefundRequest::where("user_id", $userId)
                        ->where("course_id", $courseId)
                        ->where("status", "approved")
                        ->orderBy("processed_at", "desc")
                        ->first();

                    if ($approvedRefund && $approvedRefund->processed_at) {
                        // If latest order is after refund approval, user has repurchased
                        if (
                            $latestOrderDate->gt($approvedRefund->processed_at)
                        ) {
                            $isPurchased = true;
                        } else {
                            // Latest order is before or same as refund approval
                            $isPurchased = false;
                        }
                    } else {
                        // No approved refund, so if order exists, it's purchased
                        $isPurchased = true;
                    }
                }
            }

            $response = [
                "course" => [
                    "id" => $course->id,
                    "slug" => $course->slug,
                    "title" => $course->title,
                    "short_description" => $course->short_description,
                    "description" => $course->description ?? null,
                    "image" => $course->thumbnail,
                    "category_id" => $course->category->id ?? null,
                    "category_name" => $course->category->name ?? null,
                    "level" => $course->level,
                    "course_type" => $course->course_type,
                    "sequential_access" => $course->sequential_access ?? true,
                    "certificate_enabled" =>
                        $course->certificate_enabled ?? false,
                    "certificate_fee" => $course->certificate_fee
                        ? (float) $course->certificate_fee
                        : null,
                    "ratings" => $course->ratings_count ?? 0,
                    "average_rating" => round(
                        $course->ratings_avg_rating ?? 0,
                        2,
                    ),
                    "author_name" => $course->user->name ?? null,
                    "price" => (float) $course->display_price,
                    "discount_price" => (float) $course->display_discount_price,
                    "total_tax_percentage" =>
                        (float) $course->total_tax_percentage,
                    "tax_amount" => (float) $course->tax_amount,
                    "is_purchased" => $isPurchased,
                    "learnings" => $course->learnings ?? [],
                    "requirements" => $course->requirements ?? [],
                    "reviews" => $reviews,
                    "tags" => $course->tags ?? [],
                    "language" => $course->language->name ?? null,
                    "instructors" => $course->instructors
                        ? $course->instructors->map(
                            static fn($instructor) => [
                                "id" => $instructor->id,
                                "name" => $instructor->name,
                                "email" => $instructor->email,
                                "slug" => $instructor->slug ?? null,
                                "profile" => $instructor->profile ?? null,
                                "type" => $instructor->hasRole("Super Admin")
                                    ? "admin"
                                    : "instructor",
                            ],
                        )
                        : [],
                    "chapters" => $chapters,
                    "chapter_count" => $course->chapters->count(),
                    "lecture_count" => $totalLectureCount,
                    "total_duration" => $totalCourseDuration,
                    "total_duration_formatted" => $this->formatDuration(
                        $totalCourseDuration,
                    ),
                    "view_count" => $course->view_count,
                    "unique_view_count" => $course->unique_view_count,
                ],
                "view_info" => [
                    "viewed_at" => now()->toISOString(),
                    "user_id" => $userId,
                    "ip_address" => $ipAddress,
                    "user_agent" => $userAgent,
                    "total_views" => $course->view_count,
                    "unique_views" => $course->unique_view_count,
                ],
            ];

            return ApiResponseService::successResponse(
                "Course view tracked successfully",
                $response,
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
     * Format duration in seconds to human readable format
     */
    protected function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . "s";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;

            return $remainingSeconds > 0
                ? $minutes . "m " . $remainingSeconds . "s"
                : $minutes . "m";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remainingSeconds = $seconds % 60;

            $formatted = $hours . "h";
            if ($minutes > 0) {
                $formatted .= " " . $minutes . "m";
            }
            if ($remainingSeconds > 0) {
                $formatted .= " " . $remainingSeconds . "s";
            }

            return $formatted;
        }
    }

    /**
     * Get Single Course Details (Simple Version)
     * Accepts either course ID or slug
     */
    public function getCourseDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "nullable",
                "course_id" => "nullable",
                "slug" => "nullable|string",
                "course_slug" => "nullable|string",
                "user_team_slug" => "nullable|string|exists:users,slug", // Add user_team_slug parameter
                "statistics" => "nullable|boolean", // Add statistics parameter
                "quiz_reports" => "nullable|boolean", // Add quiz reports parameter
                "quiz_id" => "nullable|exists:course_chapter_quizzes,id", // Add quiz_id parameter
                "attempt_id" => "nullable|exists:user_quiz_attempts,id", // Add attempt_id parameter
                "discussion" => "nullable|boolean", // Add discussion parameter
                "ratings" => "nullable|boolean", // Add ratings parameter
                "assignment_list" => "nullable|boolean", // Add assignment list parameter
                "assignment_details" => "nullable|boolean", // Add assignment details parameter
                "assignment_id" =>
                    "nullable|exists:course_chapter_assignments,id", // Add assignment ID parameter
                "student_enrolled" => "nullable|boolean", // Add student enrolled parameter
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Build query with basic relationships
            $courseQuery = Course::with([
                "category",
                "user",
                "instructors.instructor_details.personal_details",
                "instructors.instructor_details.social_medias.social_media",
                "language",
                "tags",
                "learnings",
                "requirements",
                "chapters" => static function ($q): void {
                    $q->with([
                        "lectures.resources",
                        "resources",
                        "assignments.resources",
                        "quizzes" => static function ($quizQuery): void {
                            $quizQuery->with([
                                "resources",
                                "questions.options",
                            ]);
                        },
                    ]);
                },
            ])
                ->withAvg(["ratings" => static function ($q): void {
                    $q->where("status", "approved");
                }], "rating")
                ->withCount(["ratings" => static function ($q): void {
                    $q->where("status", "approved");
                }]);

            $id = $request->input("id") ?? $request->input("course_id");
            $slug = $request->input("slug") ?? $request->input("course_slug");

            $course = null;
            if (!empty($id)) {
                $course = (clone $courseQuery)->where("id", $id)->first();
            } elseif (!empty($slug)) {
                $rawSlug = trim((string) $slug);
                $decodedSlug = urldecode($rawSlug);
                $course = (clone $courseQuery)->where("slug", $rawSlug)
                    ->orWhere("slug", $decodedSlug)
                    ->first();

                // If slug is numeric, also allow fallback to ID
                if (!$course && is_numeric($rawSlug)) {
                    $course = (clone $courseQuery)->where("id", (int) $rawSlug)->first();
                }
            } else {
                return ApiResponseService::validationError(
                    "Course id or slug is required",
                );
            }

            if (!$course) {
                return ApiResponseService::validationError("Course not found");
            }

            // Check team validation if user_team_slug is provided
            if ($request->filled("user_team_slug")) {
                $user = Auth::user();
                if (!$user) {
                    return ApiResponseService::unauthorizedResponse(
                        "User authentication required",
                    );
                }

                // Get the team user by slug
                $teamUser = User::where(
                    "slug",
                    $request->user_team_slug,
                )->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError(
                        "Team user not found",
                    );
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId =
                    $user->instructor_details->id ?? null;
                $teamUserInstructorId =
                    $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError(
                        "User or team user is not an instructor",
                    );
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if authenticated user is a team member of the team user
                    $isTeamMember = TeamMember::where(
                        "instructor_id",
                        $teamUserInstructorId,
                    )
                        ->where("user_id", $user->id)
                        ->exists();
                    if ($isTeamMember) {
                        $isInSameTeam = true;
                    }

                    // Check if team user is a team member of the authenticated user
                    if (!$isInSameTeam) {
                        $isTeamMember = TeamMember::where(
                            "instructor_id",
                            $authenticatedUserInstructorId,
                        )
                            ->where("user_id", $teamUser->id)
                            ->exists();
                        if ($isTeamMember) {
                            $isInSameTeam = true;
                        }
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::validationError(
                        "You are not authorized to access this course. You are not in the same team.",
                    );
                }
            }

            // Check course access permissions
            $user = Auth::user();
            $hasAccess = false;

            if ($user) {
                // Check if user is the course creator
                if ($course->user_id == $user->id) {
                    $hasAccess = true;
                } else {
                    // Check if course creator is a team member of the authenticated user
                    $instructorId = $user->instructor_details->id ?? null;
                    if ($instructorId) {
                        $isTeamMember = TeamMember::where(
                            "instructor_id",
                            $instructorId,
                        )
                            ->where("user_id", $course->user_id)
                            ->exists();
                        if ($isTeamMember) {
                            $hasAccess = true;
                        }
                    }
                }
            }

            // If no access and course is not active, deny access
            if (!$hasAccess && $course->is_active != 1) {
                return ApiResponseService::validationError(
                    "Course is not available",
                );
            }

            // Calculate discount percentage
            $discountPercentage = 0;

            // Check if user has purchased the course
            $isPurchased = false;

            if ($course->course_type === "free") {
                $isPurchased = true;
            } elseif ($user) {
                // Get latest completed order for this course
                $latestOrderCourse = OrderCourse::whereHas(
                    "order",
                    static function ($q) use ($user): void {
                        $q->where("user_id", $user->id)->where(
                            "status",
                            "completed",
                        );
                    },
                )
                    ->where("course_id", $course->id)
                    ->with("order")
                    ->orderBy("created_at", "desc")
                    ->first();

                if ($latestOrderCourse) {
                    $latestOrderDate =
                        $latestOrderCourse->order->created_at ??
                        $latestOrderCourse->created_at;

                    // Check if there's an approved refund for this course
                    $approvedRefund = RefundRequest::where("user_id", $user->id)
                        ->where("course_id", $course->id)
                        ->where("status", "approved")
                        ->orderBy("processed_at", "desc")
                        ->first();

                    if ($approvedRefund && $approvedRefund->processed_at) {
                        // If latest order is after refund approval, user has repurchased
                        if (
                            $latestOrderDate->gt($approvedRefund->processed_at)
                        ) {
                            $isPurchased = true;
                        } else {
                            // Latest order is before or same as refund approval
                            $isPurchased = false;
                        }
                    } else {
                        // No approved refund, so if order exists, it's purchased
                        $isPurchased = true;
                    }
                }
            }

            // Prepare response data
            $response = [
                "id" => $course->id,
                "slug" => $course->slug,
                "title" => $course->title,
                "short_description" => $course->short_description,
                "description" => $course->description,
                "thumbnail" => $course->thumbnail,
                "price" => (float) $course->price,
                "discounted_price" => (float) $course->discount_price,
                "discount_percentage" => $discountPercentage,
                "course_type" => $course->course_type,
                "level" => $course->level,
                "sequential_access" => $course->sequential_access ?? true,
                "certificate_enabled" => $course->certificate_enabled ?? false,
                "certificate_fee" => $course->certificate_fee
                    ? (float) $course->certificate_fee
                    : null,
                "duration" => $course->duration,
                "is_active" => $course->is_active,
                "status" => $course->status,
                "approval_status" => $course->approval_status,
                "category" => $course->category
                    ? [
                        "id" => $course->category->id,
                        "name" => $course->category->name,
                        "slug" => $course->category->slug,
                    ]
                    : null,
                "author" => $course->user
                    ? [
                        "id" => $course->user->id,
                        "name" => $course->user->name,
                        "email" => $course->user->email,
                        "profile" => $course->user->profile,
                    ]
                    : null,
                "language" => $course->language
                    ? [
                        "id" => $course->language->id,
                        "name" => $course->language->name,
                    ]
                    : null,
                "tags" => $course->tags->map(
                    static fn($tag) => [
                        "id" => $tag->id,
                        "name" => $tag->tag,
                    ],
                ),
                "learnings" => $course->learnings->map(
                    static fn($learning) => [
                        "id" => $learning->id,
                        "title" => $learning->title,
                    ],
                ),
                "requirements" => $course->requirements->map(
                    static fn($requirement) => [
                        "id" => $requirement->id,
                        "requirement" => $requirement->requirement,
                    ],
                ),
                "ratings" => [
                    "count" => $course->ratings_count ?? 0,
                    "average" => round($course->ratings_avg_rating ?? 0, 2),
                ],
                "enroll_students" => OrderCourse::whereHas(
                    "order",
                    static function ($q): void {
                        $q->where("status", "completed");
                    },
                )
                    ->where("course_id", $course->id)
                    ->count(),
                "last_updated" => $course->updated_at
                    ? $course->updated_at->format("Y-m-d H:i:s")
                    : null,
                "is_purchased" => $isPurchased,
                "has_ai_assistant" => !empty(
                    $course->getRawOriginal("ai_knowledge_content")
                ),
                "meta_title" => $course->meta_title ?? $course->title,
                "meta_description" =>
                    $course->meta_description ?? $course->short_description,
                "preview_video" => $course->intro_video,
                "co_instructors" => $course->instructors->map(
                    static fn($instructor) => [
                        "id" => $instructor->id,
                        "name" => $instructor->name,
                        "email" => $instructor->email,
                        "slug" => $instructor->slug,
                        "profile" => $instructor->profile,
                        "type" => $instructor->hasRole("Super Admin")
                            ? "admin"
                            : "instructor",
                        "qualification" =>
                            $instructor->instructor_details->personal_details
                                ->qualification ?? "",
                        "years_of_experience" =>
                            $instructor->instructor_details->personal_details
                                ->years_of_experience ?? 0,
                        "skills" =>
                            $instructor->instructor_details->personal_details
                                ->skills ?? "",
                        "about_me" =>
                            $instructor->instructor_details->personal_details
                                ->about_me ?? "",
                        "social_medias" =>
                            $instructor->instructor_details &&
                            $instructor->instructor_details->social_medias
                                ? $instructor->instructor_details->social_medias->map(
                                    static fn($social) => [
                                        "url" => $social->url ?? "",
                                    ],
                                )
                                : [],
                        "is_active" => $instructor->pivot->is_active ?? 1,
                    ],
                ),
                "chapters" => $course->chapters->map(static function (
                    $chapter,
                ) use ($request) {
                    // Create encrypter for video URL encryption if user is authenticated
                    $encrypter = null;
                    $bearerToken = $request->bearerToken();

                    if ($bearerToken !== null) {
                        $key = hash("sha256", $bearerToken, true);
                        $encrypter = new Encrypter($key, "AES-256-CBC");
                    }
                    // Get user's completion status for this chapter
                    $user = Auth::user();
                    $isChapterCompleted = false;
                    $chapterProgress = 0;

                    if ($user) {
                        $userCurriculumTracking = UserCurriculumTracking::where(
                            "user_id",
                            $user->id,
                        )
                            ->where("course_chapter_id", $chapter->id)
                            ->where("model_type", CourseChapter::class)
                            ->first();

                        if ($userCurriculumTracking) {
                            $isChapterCompleted =
                                $userCurriculumTracking->status === "completed";
                            $chapterProgress =
                                $userCurriculumTracking->metadata[
                                    "progress_percentage"
                                ] ?? 0;
                        }
                    }

                    // Create a function to check if an item is completed
                    $isItemCompleted = static function (
                        $chapterId,
                        $modelType,
                        $modelId,
                    ) use ($user) {
                        if (!$user) {
                            return false;
                        }

                        $tracking = UserCurriculumTracking::where(
                            "user_id",
                            $user->id,
                        )
                            ->where("course_chapter_id", $chapterId)
                            ->where("model_id", $modelId)
                            ->where("model_type", $modelType)
                            ->first();

                        return $tracking
                            ? $tracking->status === "completed"
                            : false;
                    };

                    // Combine all content types and sort by chapter_order
                    $allContent = collect();

                    // Add lectures
                    $lectures = $chapter->lectures->map(static function (
                        $lecture,
                    ) use ($chapter, $isItemCompleted) {
                        $lectureData = (new \App\Http\Resources\CourseChapterLectureResource(
                            $lecture,
                        ))->resolve();

                        // Add curriculum-specific fields
                        $lectureData["is_completed"] = $isItemCompleted(
                            $chapter->id,
                            CourseChapterLecture::class,
                            $lecture->id,
                        );
                        $lectureData["has_resources"] =
                            $lecture->resources->count() > 0;
                        $lectureData["resources"] = $lecture->resources->map(
                            static fn($resource) => [
                                "id" => $resource->id,
                                "title" => $resource->title,
                                "file" => $resource->file,
                                "file_type" => $resource->file_type,
                                "file_size" => $resource->file_size,
                                "created_at" => $resource->created_at,
                                "updated_at" => $resource->updated_at,
                            ],
                        );

                        return $lectureData;
                    });

                    // Add quizzes
                    $quizzes = $chapter->quizzes->map(
                        static fn($quiz) => [
                            "id" => $quiz->id,
                            "type" => "quiz",
                            "title" => $quiz->title,
                            "slug" => $quiz->slug,
                            "description" => $quiz->description,
                            "duration" => $quiz->duration,
                            "total_marks" => $quiz->total_marks,
                            "passing_marks" => $quiz->passing_marks,
                            "is_active" => $quiz->is_active,
                            "chapter_order" => $quiz->chapter_order,
                            "is_completed" => $isItemCompleted(
                                $chapter->id,
                                CourseChapterQuiz::class,
                                $quiz->id,
                            ),
                            "has_resources" => $quiz->resources->count() > 0,
                            "questions_count" => $quiz->questions->count(),
                            "resources" => $quiz->resources->map(
                                static fn($resource) => [
                                    "id" => $resource->id,
                                    "title" => $resource->title,
                                    "file" => $resource->file,
                                    "file_type" => $resource->file_type,
                                    "file_size" => $resource->file_size,
                                    "created_at" => $resource->created_at,
                                    "updated_at" => $resource->updated_at,
                                ],
                            ),
                            "questions" => $quiz->questions->map(
                                static fn($question) => [
                                    "id" => $question->id,
                                    "question" => $question->question,
                                    "question_type" => $question->question_type,
                                    "marks" => $question->marks,
                                    "sort" => $question->sort,
                                    "options" => $question->options->map(
                                        static fn($option) => [
                                            "id" => $option->id,
                                            "option" => $option->option,
                                            "sort" => $option->sort,
                                        ],
                                    ),
                                ],
                            ),
                        ],
                    );

                    // Add assignments
                    $assignments = $chapter->assignments->map(static function (
                        $assignment,
                    ) use ($chapter, $isItemCompleted, $user) {
                        $userSubmission = null;
                        if ($user) {
                            $userSubmission = UserAssignmentSubmission::where(
                                "user_id",
                                $user->id,
                            )
                                ->where(
                                    "course_chapter_assignment_id",
                                    $assignment->id,
                                )
                                ->first();
                        }

                        return [
                            "id" => $assignment->id,
                            "type" => "assignment",
                            "title" => $assignment->title,
                            "slug" => $assignment->slug,
                            "description" => $assignment->description,
                            "total_marks" => $assignment->total_marks,
                            "is_active" => $assignment->is_active,
                            "chapter_order" => $assignment->chapter_order,
                            "is_completed" => $isItemCompleted(
                                $chapter->id,
                                CourseChapterAssignment::class,
                                $assignment->id,
                            ),
                            "has_resources" =>
                                $assignment->resources->count() > 0,
                            "user_submission" => $userSubmission
                                ? [
                                    "id" => $userSubmission->id,
                                    "status" => $userSubmission->status,
                                    "points" => $userSubmission->points,
                                    "comment" => $userSubmission->comment,
                                    "feedback" => $userSubmission->feedback,
                                    "created_at" => $userSubmission->created_at,
                                    "updated_at" => $userSubmission->updated_at,
                                ]
                                : null,
                            "resources" => $assignment->resources->map(
                                static fn($resource) => [
                                    "id" => $resource->id,
                                    "title" => $resource->title,
                                    "file" => $resource->file,
                                    "file_type" => $resource->file_type,
                                    "file_size" => $resource->file_size,
                                    "created_at" => $resource->created_at,
                                    "updated_at" => $resource->updated_at,
                                ],
                            ),
                        ];
                    });

                    // Add chapter resources
                    $chapterResources = $chapter->resources->map(
                        static fn($resource) => [
                            "id" => $resource->id,
                            "type" => "resource",
                            "title" => $resource->title,
                            "file" => $resource->file,
                            "file_type" => $resource->file_type,
                            "file_size" => $resource->file_size,
                            "chapter_order" => $resource->chapter_order ?? 999, // Default high order for resources
                            "is_completed" => false, // Resources don't have completion status
                            "has_resources" => false,
                            "created_at" => $resource->created_at,
                            "updated_at" => $resource->updated_at,
                        ],
                    );

                    // Combine all content
                    $allContent = $allContent
                        ->merge($lectures)
                        ->merge($quizzes)
                        ->merge($assignments)
                        ->merge($chapterResources);

                    // Sort all content by chapter_order
                    $sortedContent = $allContent
                        ->sortBy("chapter_order")
                        ->values();

                    return [
                        "id" => $chapter->id,
                        "course_id" => $chapter->course_id,
                        "title" => $chapter->title,
                        "slug" => $chapter->slug,
                        "description" => $chapter->description,
                        "is_active" => $chapter->is_active,
                        "chapter_order" => $chapter->chapter_order,
                        "is_completed" => $isChapterCompleted,
                        "progress_percentage" => $chapterProgress,
                        "lecture_count" => $chapter->lectures->count(),
                        "quizzes_count" => $chapter->quizzes->count(),
                        "assignments_count" => $chapter->assignments->count(),
                        "documents_count" => $chapter->resources->count(),
                        "total_content" => $sortedContent->count(),
                        "curriculum" => $sortedContent,
                        "created_at" => $chapter->created_at,
                        "updated_at" => $chapter->updated_at,
                    ];
                }),
                "created_at" => $course->created_at,
                "updated_at" => $course->updated_at,
            ];

            // Prepare final response with course_details
            $finalResponse = [
                "course_details" => $response,
            ];

            // Include statistics only if requested
            if (
                $request->filled("statistics") &&
                $request->boolean("statistics")
            ) {
                $courseStats = $this->getSingleCourseStatistics($course->id);
                $finalResponse["statistics"] = $courseStats;
            }

            // Include quiz reports if requested
            if (
                $request->filled("quiz_reports") &&
                $request->boolean("quiz_reports")
            ) {
                $quizId = $request->filled("quiz_id")
                    ? $request->quiz_id
                    : null;
                $quizReports = $this->getCourseQuizReports(
                    $course->id,
                    $quizId,
                );
                $finalResponse["quiz_reports"] = $quizReports;
            }

            // Include quiz attempt details if requested
            if ($request->filled("attempt_id")) {
                $attemptDetails = $this->getQuizAttemptDetailsForCourse(
                    $request->attempt_id,
                    $course->id,
                );
                $finalResponse["quiz_attempt_details"] = $attemptDetails;
            }

            // Include discussion data if requested
            if (
                $request->filled("discussion") &&
                $request->boolean("discussion")
            ) {
                $discussionData = $this->getCourseDiscussions($course->id);
                $finalResponse["discussions"] = $discussionData;
            }

            // Include detailed ratings if requested
            if ($request->filled("ratings") && $request->boolean("ratings")) {
                $ratingsData = $this->getCourseRatings($course->id);
                $finalResponse["ratings_details"] = $ratingsData;
            }

            // Include assignment list if requested
            if (
                $request->filled("assignment_list") &&
                $request->boolean("assignment_list")
            ) {
                $assignmentList = $this->getCourseAssignments($course->id);
                $finalResponse["assignments"] = $assignmentList;
            }

            // Include assignment details if requested
            if (
                $request->filled("assignment_details") &&
                $request->boolean("assignment_details")
            ) {
                $assignmentId = $request->filled("assignment_id")
                    ? $request->assignment_id
                    : null;
                $assignmentDetails = $this->getAssignmentDetails(
                    $course->id,
                    $assignmentId,
                );
                $finalResponse["assignment_details"] = $assignmentDetails;
            }

            // Include student enrolled data if requested
            if (
                $request->filled("student_enrolled") &&
                $request->boolean("student_enrolled")
            ) {
                $enrolledStudents = $course->getEnrolledStudents();
                $finalResponse["student_enrolled"] = $enrolledStudents->map(
                    static fn($student) => [
                        "id" => $student->id,
                        "name" => $student->name,
                        "email" => $student->email,
                        "profile" => $student->profile,
                        "enrolled_at" => $student->enrolled_at ?? null,
                    ],
                );
            }

            return ApiResponseService::successResponse(
                "Course details retrieved successfully",
                $finalResponse,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ApiResponseService::errorResponse(
                "Something went wrong: " . $e->getMessage(),
            );
        }
    }

}
