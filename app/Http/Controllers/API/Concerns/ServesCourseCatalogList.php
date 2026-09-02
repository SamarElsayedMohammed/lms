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

trait ServesCourseCatalogList
{
    public function getCourses(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "id" => "nullable|exists:courses,id",
            "level" => "nullable",
            "search" => "nullable|string|max:255",
            "sort_by" =>
                "nullable|in:id,title,name,price,course_type,latest,created_at",
            "sort_order" => "nullable|in:asc,desc",
            "per_page" => "nullable|integer|min:1|max:100",
            "page" => "nullable|integer|min:1",
            "course_type" => "nullable",
            "category_id" => "nullable|exists:categories,id",
            "category_slug" => "nullable|string|max:255",
            "instructor_id" => "nullable|exists:users,id",
            "instructor_slug" => "nullable|string|max:255",
            "language_id" => "nullable|exists:course_languages,id",
            "slug" => "nullable|exists:courses,slug",
            "post_filter" => "nullable|string|in:newest,oldest,most_popular",
            "rating_filter" => "nullable|string", // Comma separated: 1,2,3,4,5
            "duration_filter" => "nullable|string", // Comma separated: 1-4_weeks,4-12_weeks,3-6_months,6-12_months
            "feature_section_id" => "nullable|exists:feature_sections,id", // Optional: Filter by feature section
            "is_featured" => "nullable|boolean",
            // A podcast is a course explicitly classified by its metadata or
            // tags. Keep this as a boolean query contract rather than making
            // clients infer it from an unfiltered catalog response.
            "is_podcast" => "nullable|boolean",
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError(
                $validator->errors()->first(),
            );
        }

        // Apply feature section filtering if provided
        $featureSection = null;
        if ($request->filled("feature_section_id")) {
            $featureSection = FeatureSection::where(
                "id",
                $request->feature_section_id,
            )
                ->where("is_active", 1)
                ->first();

            if (!$featureSection) {
                return ApiResponseService::validationError(
                    "Feature section not found or inactive",
                );
            }
        }

        $query = Course::with([
            "category",
            "user",
            "tags",
            "taxes",
            "chapters:id,course_id",
            "chapters.lectures:id,course_chapter_id,hours,minutes,seconds",
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
                    $q->whereHas("order", static function ($orderQuery): void {
                        $orderQuery->where("status", "completed");
                    });
                },
            ])
            ->where("is_active", 1) // ensure active only
            ->where("status", "publish") // ensure published status
            ->where("approval_status", "approved") // ensure approved status
            ->whereHas("user", static function ($userQuery): void {
                $userQuery
                    ->where("is_active", 1);
            });

        // Filters
        if ($request->id) {
            $query->where("id", $request->id);
        }

        if ($request->slug) {
            $query->where("slug", $request->slug);
        }

        if ($request->filled("level")) {
            $query->whereIn("level", explode(",", $request->level));
        }

        if ($request->filled("is_featured")) {
            $query->where("is_featured", $request->boolean("is_featured"));
        }

        if ($request->filled("course_type")) {
            $query->whereIn("course_type", explode(",", $request->course_type));
        }

        if ($request->boolean("is_podcast")) {
            $this->applyPodcastFilter($query);
        }

        if ($request->filled("category_id")) {
            $categoryIds = array_map(
                intval(...),
                explode(",", $request->category_id),
            );
            // Get all child category IDs for the given parent categories
            $allCategoryIds = $categoryIds;
            foreach ($categoryIds as $categoryId) {
                $childIds = $this->getAllChildCategoryIds($categoryId);
                $allCategoryIds = array_merge($allCategoryIds, $childIds);
            }
            // Remove duplicates and filter
            $allCategoryIds = array_unique($allCategoryIds);

            if (empty($allCategoryIds)) {
                // No categories found, return empty result
                $query->whereRaw("1 = 0");
            } else {
                $query->whereIn("category_id", $allCategoryIds);
            }
        }

        if ($request->filled("category_slug")) {
            $categorySlugs = array_map(
                "trim",
                explode(",", $request->category_slug),
            );
            $categoryIds = $this->getCategoryIdsWithChildren($categorySlugs);

            if (empty($categoryIds)) {
                // No categories found with given slugs, return empty result
                $query->whereRaw("1 = 0");
            } else {
                $query->whereIn("category_id", $categoryIds);
            }
        }

        if ($request->filled("instructor_id")) {
            $instructorIds = array_filter(array_map('intval', explode(",", (string) $request->instructor_id)));
            $query->where(static function ($q) use ($instructorIds): void {
                $q->whereIn("user_id", $instructorIds)
                  ->orWhereHas("instructors", static function ($iq) use ($instructorIds): void {
                      $iq->whereIn("users.id", $instructorIds);
                  });
            });
        }

        if ($request->filled("instructor_slug")) {
            $rawSlugs = explode(",", (string) $request->instructor_slug);
            $decodedSlugs = array_map(urldecode(...), $rawSlugs);
            $allSlugs = array_values(array_unique(array_filter(array_merge($rawSlugs, $decodedSlugs))));

            $query->where(static function ($q) use ($allSlugs): void {
                $q->whereHas("user", static function ($uq) use ($allSlugs): void {
                    $uq->whereIn("slug", $allSlugs)
                       ->orWhereIn("name", $allSlugs);
                })->orWhereHas("instructors", static function ($iq) use ($allSlugs): void {
                    $iq->whereIn("slug", $allSlugs)
                       ->orWhereIn("name", $allSlugs);
                });
            });
        }

        if ($request->filled("language_id")) {
            $query->whereIn("language_id", explode(",", $request->language_id));
        }

        if ($request->filled("tag")) {
            $query->whereHas("tags", static function ($tagQuery) use (
                $request,
            ): void {
                $tagQuery->where("tag", $request->tag);
            });
        }

        if ($request->filled("search")) {
            $search = trim((string) $request->search);

            // Record search history (non-blocking)
            try {
                $userId = Auth::id();
                $ipAddress = $request->ip();
                SearchHistory::recordSearch($search, $userId, $ipAddress);
            } catch (\Throwable $e) {
                Log::warning('SearchHistory: Failed to record search query', ['error' => $e->getMessage()]);
            }

            $searchVariants = $this->buildArabicSearchVariants($search);

            $query->where(static function ($q) use ($searchVariants): void {
                foreach ($searchVariants as $term) {
                    $q->orWhere("title", "LIKE", "%{$term}%")
                        ->orWhere("short_description", "LIKE", "%{$term}%")
                        ->orWhere("level", "LIKE", "%{$term}%")
                        ->orWhereHas("language", static function ($langQuery) use ($term): void {
                            $langQuery->where("name", "LIKE", "%{$term}%");
                        })
                        ->orWhereHas("category", static function ($categoryQuery) use ($term): void {
                            $categoryQuery->where("name", "LIKE", "%{$term}%");
                        })
                        ->orWhereHas("tags", static function ($tagQuery) use ($term): void {
                            $tagQuery->where("tag", "LIKE", "%{$term}%");
                        })
                        ->orWhereHas("user", static function ($userQuery) use ($term): void {
                            $userQuery->where("name", "LIKE", "%{$term}%")
                                ->orWhere("slug", "LIKE", "%{$term}%");
                        });
                }
            });
        }

        // Apply feature section filtering
        if ($featureSection) {
            $limit = $featureSection->limit ?? null;
            $user = Auth::user();

            if ($featureSection->sorting === "manual") {
                $manualCourseIds = $featureSection
                    ->manualCourses()
                    ->pluck("courses.id")
                    ->toArray();
                if (!empty($manualCourseIds)) {
                    $query->whereIn("id", $manualCourseIds);
                    // To maintain manual sort order, use orderByRaw
                    $query->orderByRaw(
                        "FIELD(id, " . implode(",", $manualCourseIds) . ")",
                    );
                } else {
                    $query->whereRaw("1 = 0");
                }
            } else {
                switch ($featureSection->type) {
                    case "newly_added_courses":
                        $query->latest();
                        if ($limit) {
                            // Note: Limit will be applied after pagination, so we'll handle it differently
                        }
                        break;

                    case "top_rated_courses":
                        // withAvg is already applied at line 102, so just add having and orderBy
                        $query
                            ->having("ratings_avg_rating", ">=", 4)
                            ->orderByDesc("ratings_avg_rating");
                        break;

                    case "most_viewed_courses":
                        $query
                            ->withCount("views")
                            ->orderByDesc("views_count")
                            ->orderByDesc("ratings_avg_rating");
                        break;

                    case "free_courses":
                        $query->where("course_type", "free");
                        break;

                    case "wishlist":
                        if ($user) {
                            $wishlistCourseIds = Wishlist::where(
                                "user_id",
                                $user->id,
                            )
                                ->pluck("course_id")
                                ->toArray();
                            if (!empty($wishlistCourseIds)) {
                                $query->whereIn("id", $wishlistCourseIds);
                            } else {
                                // No wishlist items, return empty result
                                $query->whereRaw("1 = 0");
                            }
                        } else {
                            // Guest user, return empty result
                            $query->whereRaw("1 = 0");
                        }
                        break;

                    case "recommend_for_you":
                        if ($user) {
                            $recommendedCourseIds = [];

                            // Get user's purchased course IDs
                            $purchasedCourseIds = OrderCourse::whereHas(
                                "order",
                                static function ($q) use ($user): void {
                                    $q->where("user_id", $user->id)->where(
                                        "status",
                                        "completed",
                                    );
                                },
                            )
                                ->pluck("course_id")
                                ->toArray();

                            // 1. Get instructor IDs from purchased courses
                            if (!empty($purchasedCourseIds)) {
                                $instructorIds = Course::whereIn(
                                    "id",
                                    $purchasedCourseIds,
                                )
                                    ->pluck("user_id")
                                    ->unique()
                                    ->toArray();

                                // Get other courses from these instructors (excluding already purchased)
                                $instructorCourseIds = Course::where(
                                    "is_active",
                                    1,
                                )
                                    ->whereIn("user_id", $instructorIds)
                                    ->whereNotIn("id", $purchasedCourseIds)
                                    ->pluck("id")
                                    ->toArray();

                                $recommendedCourseIds = array_merge(
                                    $recommendedCourseIds,
                                    $instructorCourseIds,
                                );
                            }

                            // 2. Get wishlisted courses (excluding already purchased)
                            $wishlistCourseIds = Wishlist::where(
                                "user_id",
                                $user->id,
                            )
                                ->whereNotIn("course_id", $purchasedCourseIds)
                                ->pluck("course_id")
                                ->toArray();

                            $recommendedCourseIds = array_merge(
                                $recommendedCourseIds,
                                $wishlistCourseIds,
                            );

                            // 3. Get courses based on search history
                            $searchHistories = SearchHistory::where(
                                "user_id",
                                $user->id,
                            )
                                ->orderBy("last_searched_at", "desc")
                                ->limit(10)
                                ->pluck("query")
                                ->toArray();

                            if (!empty($searchHistories)) {
                                $searchBasedCourseIds = Course::where(
                                    "is_active",
                                    1,
                                )
                                    ->where(static function ($q) use (
                                        $searchHistories,
                                    ): void {
                                        foreach (
                                            $searchHistories
                                            as $searchQuery
                                        ) {
                                            $q->orWhere(
                                                "title",
                                                "LIKE",
                                                "%{$searchQuery}%",
                                            )
                                                ->orWhere(
                                                    "short_description",
                                                    "LIKE",
                                                    "%{$searchQuery}%",
                                                )
                                                ->orWhereHas(
                                                    "category",
                                                    static function (
                                                        $catQuery,
                                                    ) use ($searchQuery): void {
                                                        $catQuery->where(
                                                            "name",
                                                            "LIKE",
                                                            "%{$searchQuery}%",
                                                        );
                                                    },
                                                )
                                                ->orWhereHas(
                                                    "tags",
                                                    static function (
                                                        $tagQuery,
                                                    ) use ($searchQuery): void {
                                                        $tagQuery->where(
                                                            "tag",
                                                            "LIKE",
                                                            "%{$searchQuery}%",
                                                        );
                                                    },
                                                );
                                        }
                                    })
                                    ->whereNotIn("id", $purchasedCourseIds)
                                    ->pluck("id")
                                    ->toArray();

                                $recommendedCourseIds = array_merge(
                                    $recommendedCourseIds,
                                    $searchBasedCourseIds,
                                );
                            }

                            // Remove duplicates
                            $recommendedCourseIds = array_unique(
                                $recommendedCourseIds,
                            );

                            if (!empty($recommendedCourseIds)) {
                                $query
                                    ->whereIn("id", $recommendedCourseIds)
                                    ->inRandomOrder();
                            } else {
                                // If no recommendations, show popular courses
                                $query->orderByDesc("ratings_avg_rating");
                            }
                        } else {
                            // Guest user, show popular courses
                            $query->orderByDesc("ratings_avg_rating");
                        }
                        break;

                    case "searching_based":
                        if ($user) {
                            $searchHistories = SearchHistory::where(
                                "user_id",
                                $user->id,
                            )
                                ->orderBy("last_searched_at", "desc")
                                ->limit(10)
                                ->pluck("query")
                                ->toArray();

                            if (!empty($searchHistories)) {
                                $purchasedCourseIds = OrderCourse::whereHas(
                                    "order",
                                    static function ($q) use ($user): void {
                                        $q->where("user_id", $user->id)->where(
                                            "status",
                                            "completed",
                                        );
                                    },
                                )
                                    ->pluck("course_id")
                                    ->toArray();

                                $wishlistCourseIds = Wishlist::where(
                                    "user_id",
                                    $user->id,
                                )
                                    ->pluck("course_id")
                                    ->toArray();

                                $excludeCourseIds = array_unique(
                                    array_merge(
                                        $purchasedCourseIds,
                                        $wishlistCourseIds,
                                    ),
                                );

                                $query->where(static function ($q) use (
                                    $searchHistories,
                                ): void {
                                    foreach ($searchHistories as $searchQuery) {
                                        $q->orWhere(
                                            "title",
                                            "LIKE",
                                            "%{$searchQuery}%",
                                        )
                                            ->orWhere(
                                                "short_description",
                                                "LIKE",
                                                "%{$searchQuery}%",
                                            )
                                            ->orWhereHas(
                                                "category",
                                                static function (
                                                    $catQuery,
                                                ) use ($searchQuery): void {
                                                    $catQuery->where(
                                                        "name",
                                                        "LIKE",
                                                        "%{$searchQuery}%",
                                                    );
                                                },
                                            )
                                            ->orWhereHas(
                                                "tags",
                                                static function (
                                                    $tagQuery,
                                                ) use ($searchQuery): void {
                                                    $tagQuery->where(
                                                        "tag",
                                                        "LIKE",
                                                        "%{$searchQuery}%",
                                                    );
                                                },
                                            );
                                    }
                                });

                                if (!empty($excludeCourseIds)) {
                                    $query->whereNotIn("id", $excludeCourseIds);
                                }

                                $query->orderByDesc("ratings_avg_rating");
                            } else {
                                // No search history, show trending courses
                                $query
                                    ->orderByDesc("ratings_count")
                                    ->orderByDesc("ratings_avg_rating");
                            }
                        } else {
                            // Guest user, show trending courses
                            $query
                                ->orderByDesc("ratings_count")
                                ->orderByDesc("ratings_avg_rating");
                        }
                        break;

                    case "my_learning":
                        if ($user) {
                            $enrolledCourseIds = OrderCourse::whereHas(
                                "order",
                                static function ($q) use ($user): void {
                                    $q->where("user_id", $user->id)->where(
                                        "status",
                                        "completed",
                                    );
                                },
                            )
                                ->pluck("course_id")
                                ->toArray();

                            if (!empty($enrolledCourseIds)) {
                                $query
                                    ->whereIn("id", $enrolledCourseIds)
                                    ->latest();
                            } else {
                                // No enrolled courses, return empty result
                                $query->whereRaw("1 = 0");
                            }
                        } else {
                            // Guest user, return empty result
                            $query->whereRaw("1 = 0");
                        }
                        break;

                    default:
                        // For other types (offer, why_choose_us, become_instructor, top_rated_instructors),
                        break;
                }
            }
        }

        // Sorting with strict allow-list and stable secondary tiebreaker
        $sortField = $request->sort_by ?? "id";
        $sortOrder = strtolower((string) ($request->sort_order ?? "desc"));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $allowedSorts = [
            'id' => 'id',
            'latest' => 'created_at',
            'newest' => 'created_at',
            'created_at' => 'created_at',
            'price' => 'price',
            'title' => 'title',
            'name' => 'title',
            'course_type' => 'course_type',
            'ratings' => 'ratings_avg_rating',
            'views' => 'views_count',
        ];

        $mappedColumn = $allowedSorts[$sortField] ?? 'id';

        if ($request->filled("post_filter")) {
            if ($request->post_filter == "newest") {
                $mappedColumn = "created_at";
                $sortOrder = "desc";
            } elseif ($request->post_filter == "oldest") {
                $mappedColumn = "created_at";
                $sortOrder = "asc";
            } elseif ($request->post_filter == "most_popular") {
                // Sort by enrollments/purchases count (completed orders)
                $query
                    ->withCount([
                        "orderCourses" => static function ($q): void {
                            $q->whereHas("order", static function (
                                $orderQuery,
                            ): void {
                                $orderQuery->where("status", "completed");
                            });
                        },
                    ])
                    ->orderByDesc("order_courses_count")
                    ->orderByDesc("created_at")
                    ->orderByDesc("id");
                $mappedColumn = null; // Custom order already applied
            }
        }

        if ($mappedColumn !== null) {
            if ($mappedColumn === 'ratings_avg_rating') {
                $query->orderBy('ratings_avg_rating', $sortOrder)->orderByDesc('id');
            } elseif ($mappedColumn === 'views_count') {
                $query->orderBy('views_count', $sortOrder)->orderByDesc('id');
            } else {
                $query->orderBy($mappedColumn, $sortOrder)->orderByDesc('id');
            }
        }

        if ($request->filled("rating_filter")) {
            $ratingFilters = array_map(intval(...), explode(",", $request->rating_filter));
            $minRating = min($ratingFilters);
            $query->having('ratings_avg_rating', '>=', $minRating);
        }

        if ($request->filled("duration_filter")) {
            $durationFilters = explode(",", $request->duration_filter);
            $query->where(function ($q) use ($durationFilters) {
                foreach ($durationFilters as $filter) {
                    $filter = trim($filter);
                    if ($filter === "1-4_weeks") {
                        $q->orWhereBetween('duration_seconds', [604800, 2419200]);
                    } elseif ($filter === "4-12_weeks") {
                        $q->orWhereBetween('duration_seconds', [2419200, 7257600]);
                    } elseif ($filter === "3-6_months") {
                        $q->orWhereBetween('duration_seconds', [7776000, 15552000]);
                    } elseif ($filter === "6-12_months") {
                        $q->orWhereBetween('duration_seconds', [15552000, 31536000]);
                    }
                }
            });
        }

        $perPage = $request->per_page ?? 15;
        $courses = $query->paginate($perPage);

        // Get country code and tax percentage using service
        $countryCode = $this->pricingService->getCountryCodeFromRequest(
            $request,
        );
        $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

        // Check active subscription once to avoid N+1 queries
        $hasActiveSubscription = false;
        $purchasedCourseIds = [];
        $refundedCourseIds = [];
        $wishlistedCourseIds = [];
        if (Auth::check()) {
            $user = Auth::user();
            $hasActiveSubscription = $user->activeSubscription()->exists();

            $wishlistedCourseIds = Wishlist::where("user_id", $user->id)
                ->pluck("course_id")
                ->toArray();

            $purchasedCourseIds = \App\Models\Course\OrderCourse::whereHas(
                "order",
                static function ($q) use ($user): void {
                    $q->where("user_id", $user->id)->where(
                        "status",
                        "completed",
                    );
                },
            )
                ->pluck("course_id")
                ->toArray();

            $refundedCourseIds = \App\Models\RefundRequest::where(
                "user_id",
                $user->id,
            )
                ->where("status", "approved")
                ->pluck("course_id")
                ->toArray();
        }

        // Transform data
        $courses
            ->getCollection()
            ->transform(function ($course) use (
                $totalTaxPercentage,
                $countryCode,
                $hasActiveSubscription,
                $purchasedCourseIds,
                $refundedCourseIds,
                $wishlistedCourseIds,
            ) {
                $discountPercentage = 0;

                $isWishlisted = in_array($course->id, $wishlistedCourseIds);

                $isPurchased = in_array($course->id, $purchasedCourseIds);
                $isEnrolled = $isPurchased || $hasActiveSubscription;

                // Calculate total course duration
                $totalDuration = $course->duration_seconds;

                // Calculate pricing using service
                $coursePricingData = $this->pricingService->calculateCoursePricing(
                    $course,
                    taxPercentage: $totalTaxPercentage,
                    countryCode: $countryCode,
                );

                return [
                    "id" => $course->id,
                    "slug" => $course->slug,
                    "image" => $course->thumbnail,
                    "intro_video" => $course->intro_video,
                    "intro_video_type" => $course->intro_video_type,
                    "category_id" => $course->category->id ?? null,
                    "category_name" => $course->category->name ?? null,
                    "course_type" => $course->course_type,
                    "level" => $course->level,
                    "sequential_access" => $course->sequential_access ?? true,
                    "certificate_enabled" =>
                        $course->certificate_enabled ?? false,
                    "certificate_fee" => $course->certificate_fee
                        ? (float) $course->certificate_fee
                        : null,
                    "ratings" => $course->ratings_count ?? 0,
                    "view_count" =>
                        ($course->views_count ?? 0) +
                        ($course->initial_views ?? 0),
                    "student_count" =>
                        ($course->order_courses_count ?? 0) +
                        ($course->initial_students ?? 0),
                    "average_rating" =>
                        ($course->ratings_avg_rating ?? 0) > 0
                            ? round($course->ratings_avg_rating, 2)
                            : $course->initial_rating ?? 0,
                    "title" => $course->title,
                    "short_description" => $course->short_description,
                    "author_id" => $course->user->id ?? null,
                    "author_name" => $course->user->name ?? null,
                    "author_slug" => $course->user->slug ?? null,
                    "is_featured" => (bool) $course->is_featured,
                    "is_podcast" => $this->isPodcastCourse($course),
                    "created_at" => $course->created_at,
                    ...$coursePricingData,
                    "discount_percentage" => $discountPercentage,
                    "total_duration" => $totalDuration, // in seconds
                    "total_duration_formatted" => $this->formatDuration(
                        $totalDuration,
                    ),
                    "is_wishlisted" => $isWishlisted,
                    "is_purchased" => $isPurchased,
                    "is_enrolled" =>
                        $isEnrolled &&
                        !in_array($course->id, $refundedCourseIds),
                    // Currency specific fields (explicitly copied for clarity)
                    "currency_code" => $coursePricingData["currency_code"],
                    "currency_symbol" =>
                        $coursePricingData["display_symbol"] ?? null,
                    "has_content" => $course->hasContent(),
                ];
            });

        ApiResponseService::successResponse(
            "Courses retrieved successfully",
            $courses,
        );
    }

    /**
     * Podcast courses are the existing course records that content managers
     * classify with a podcast/podcourse marker in course metadata or tags.
     * This matches the established web catalog convention while making the
     * mobile query server-authoritative and paginatable.
     */
    private function applyPodcastFilter($query): void
    {
        $keywords = $this->podcastKeywords();

        $query->where(function ($podcastQuery) use ($keywords): void {
            foreach ($keywords as $keyword) {
                $like = '%' . $keyword . '%';
                $podcastQuery
                    ->orWhere('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('meta_keywords', 'like', $like)
                    ->orWhereHas('tags', static function ($tagQuery) use ($like): void {
                        $tagQuery->where('tag', 'like', $like);
                    });
            }
        });
    }

    private function isPodcastCourse(Course $course): bool
    {
        $tagText = $course->relationLoaded('tags')
            ? $course->tags->pluck('tag')->implode(' ')
            : '';
        $haystack = mb_strtolower(implode(' ', [
            (string) $course->title,
            (string) $course->slug,
            (string) $course->meta_keywords,
            $tagText,
        ]), 'UTF-8');

        foreach ($this->podcastKeywords() as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    private function podcastKeywords(): array
    {
        return ['podcast', 'podcourse', 'بودكاست', 'بودكورس'];
    }

    /**
     * Build search term variants for robust Arabic text search
     */
    protected function buildArabicSearchVariants(string $search): array
    {
        $raw = trim($search);
        if ($raw === '') {
            return [];
        }

        $variants = [$raw];

        // Strip diacritics & tatweel
        $cleaned = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $raw);
        if ($cleaned !== null && $cleaned !== '' && $cleaned !== $raw) {
            $variants[] = $cleaned;
        }

        $base = ($cleaned !== null && $cleaned !== '') ? $cleaned : $raw;

        // Normalize Alef variants (أ, إ, آ -> ا)
        $normalizedAlef = preg_replace('/[أإآ]/u', 'ا', $base);
        if ($normalizedAlef !== null && !in_array($normalizedAlef, $variants, true)) {
            $variants[] = $normalizedAlef;
        }

        // Generate Hamza variants for leading Alef
        if (str_starts_with($base, 'ا')) {
            $suffix = mb_substr($base, 1);
            $variants[] = 'إ' . $suffix;
            $variants[] = 'أ' . $suffix;
            $variants[] = 'آ' . $suffix;
            $variants[] = 'إ' . str_replace('ة', 'ه', $suffix);
            $variants[] = 'أ' . str_replace('ة', 'ه', $suffix);
            $variants[] = 'إ' . str_replace('ه', 'ة', $suffix);
            $variants[] = 'أ' . str_replace('ه', 'ة', $suffix);
        }

        // Normalize Ta Marbuta (ة -> ه) and vice-versa
        $normalizedTa = str_replace('ة', 'ه', $base);
        if (!in_array($normalizedTa, $variants, true)) {
            $variants[] = $normalizedTa;
        }
        $normalizedHa = str_replace('ه', 'ة', $base);
        if (!in_array($normalizedHa, $variants, true)) {
            $variants[] = $normalizedHa;
        }

        // Normalize Ya / Alef Maksura (ى -> ي) and vice-versa
        $normalizedYa = str_replace('ى', 'ي', $base);
        if (!in_array($normalizedYa, $variants, true)) {
            $variants[] = $normalizedYa;
        }
        $normalizedMaksura = str_replace('ي', 'ى', $base);
        if (!in_array($normalizedMaksura, $variants, true)) {
            $variants[] = $normalizedMaksura;
        }

        // Full normalized combination
        if ($normalizedAlef !== null) {
            $fullNormalized = str_replace(['ة', 'ى'], ['ه', 'ي'], $normalizedAlef);
            if (!in_array($fullNormalized, $variants, true)) {
                $variants[] = $fullNormalized;
            }
            $fullNormalized2 = str_replace(['ه', 'ي'], ['ة', 'ى'], $normalizedAlef);
            if (!in_array($fullNormalized2, $variants, true)) {
                $variants[] = $fullNormalized2;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Get category IDs and all their children for given category slugs
     */
    protected function getCategoryIdsWithChildren(array $categorySlugs): array
    {
        $directCategoryIds = Category::whereIn('slug', $categorySlugs)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->toArray();

        $allCategoryIds = $directCategoryIds;
        foreach ($directCategoryIds as $categoryId) {
            $childIds = $this->getAllChildCategoryIds($categoryId);
            $allCategoryIds = array_merge($allCategoryIds, $childIds);
        }

        return array_values(array_unique($allCategoryIds));
    }

    /**
     * Recursively get all child category IDs for a given category ID (with cycle protection)
     */
    protected function getAllChildCategoryIds(int $categoryId, array &$visited = [], int $depth = 0): array
    {
        if ($depth > 10 || in_array($categoryId, $visited, true)) {
            return [];
        }
        $visited[] = $categoryId;

        $childIds = [];

        // Get direct children
        $children = Category::where("parent_category_id", $categoryId)->get(['id']);

        foreach ($children as $child) {
            $childId = (int) $child->id;
            if (!in_array($childId, $visited, true)) {
                $childIds[] = $childId;
                // Recursively get grandchildren
                $grandchildIds = $this->getAllChildCategoryIds($childId, $visited, $depth + 1);
                $childIds = array_merge($childIds, $grandchildIds);
            }
        }

        return array_values(array_unique($childIds));
    }
}
