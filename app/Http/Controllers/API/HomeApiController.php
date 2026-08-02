<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course\Course;
use App\Models\FeatureSection;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\Rating;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\PricingCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class HomeApiController extends Controller
{
    public function __construct(
        private PricingCalculationService $pricingService,
    ) {}

    public function getCounts(Request $request)
    {
        try {
            $data = \App\Services\CachingService::cacheRemember('home_public_counts', static function () {
                $courseCount = Course::where('is_active', 1)
                    ->whereHas('chapters', static function ($query): void {
                        $query->where('is_active', 1);
                    })
                    ->count();

                $instructorCount = Instructor::where('status', 'approved')
                    ->whereIn('user_id', static function ($query): void {
                        $query->select('user_id')->from('courses')->whereNotNull('user_id');
                    })
                    ->count();

                $studentEnrollCount = User::whereHas('orders', static function ($query): void {
                    $query->where('status', 'completed')->whereHas('orderCourses');
                })
                    ->distinct()
                    ->count();

                $positiveFeedbackCount = Rating::whereIn('rating', [4, 5])->count();

                return [
                    'course_count' => $courseCount,
                    'instructor_count' => $instructorCount,
                    'student_enroll_count' => $studentEnrollCount,
                    'positive_feedback_count' => $positiveFeedbackCount,
                ];
            }, 600);

            return ApiResponseService::successResponse('Counts retrieved successfully.', $data);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getCounts method');
            return ApiResponseService::errorResponse();
        }
    }

    public function getCategoriesWithCourseCount()
    {
        try {
            $categories = Category::where('status', 1)
                ->select('categories.*')
                ->selectRaw('(SELECT COUNT(DISTINCT courses.id) FROM courses
                        WHERE courses.category_id IN (
                            SELECT cat.id FROM categories cat
                            WHERE cat.id = categories.id
                            OR cat.parent_category_id = categories.id
                            OR cat.parent_category_id IN (
                                SELECT subcat.id FROM categories subcat
                                WHERE subcat.parent_category_id = categories.id
                            )
                        )
                        AND courses.is_active = 1
                        AND courses.status = "publish"
                        AND courses.approval_status = "approved"
                        AND courses.deleted_at IS NULL) as active_course_count')
                ->get();

            return ApiResponseService::successResponse(
                'Categories with active course count retrieved successfully.',
                $categories,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getCategoriesWithCourseCount method');
            return ApiResponseService::errorResponse();
        }
    }

    public function getFeatureSections(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_type' => 'nullable',
            'level' => 'nullable',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $sections = FeatureSection::with('manualCourses')->where('is_active', 1)->orderBy('row_order')->get();

        $user = Auth::user();

        $result = $sections->map(function ($section) use ($user, $request) {
            $limit = $section->limit ?? 10;

            if ($section->manualCourses->isNotEmpty()) {
                $data = $section->manualCourses()
                    ->with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                    ->take($limit)
                    ->get();
            } else {
                switch ($section->type) {
                case 'courses':
                case 'newly_added_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            });

                    if ($request->filled('course_type')) {
                        $query->whereIn('course_type', explode(',', $request->course_type));
                    }

                    if ($request->filled('level')) {
                        $query->whereIn('level', explode(',', $request->level));
                    }

                    $data = $query->latest()->take($limit)->get();
                    break;

                case 'featured_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->where('is_featured', 1)
                        ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            });

                    if ($request->filled('course_type')) {
                        $query->whereIn('course_type', explode(',', $request->course_type));
                    }

                    if ($request->filled('level')) {
                        $query->whereIn('level', explode(',', $request->level));
                    }

                    $data = $query->latest()->take($limit)->get();
                    break;

                case 'top_rated_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                        ->withAvg('ratings', 'rating')
                        ->having('ratings_avg_rating', '>=', 4);

                    if ($request->filled('course_type')) {
                        $query->whereIn('course_type', explode(',', $request->course_type));
                    }

                    if ($request->filled('level')) {
                        $query->whereIn('level', explode(',', $request->level));
                    }

                    $data = $query->orderByDesc('ratings_avg_rating')->take($limit)->get();
                    break;

                case 'most_viewed_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers', 'views'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                        ->withCount('views')
                        ->withAvg('ratings', 'rating')
                        ->withCount('ratings');

                    if ($request->filled('course_type')) {
                        $query->whereIn('course_type', explode(',', $request->course_type));
                    }

                    if ($request->filled('level')) {
                        $query->whereIn('level', explode(',', $request->level));
                    }

                    $data = $query->orderByDesc('views_count')->orderByDesc('ratings_avg_rating')->take($limit)->get();
                    break;

                case 'free_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                        ->whereNull('price')
                        ->where('course_type', 'free');

                    if ($request->filled('level')) {
                        $query->whereIn('level', explode(',', $request->level));
                    }

                    $data = $query->take($limit)->get();
                    break;

                case 'offer':
                    $data = $section
                        ->images()
                        ->when($section->limit, static fn($q) => $q->take($section->limit))
                        ->get();
                    break;

                case 'top_rated_instructors':
                    $instructors = Instructor::with(['user', 'personal_details', 'ratings.user'])
                        ->where('status', 'approved')
                        ->withAvg('ratings', 'rating')
                        ->withCount('ratings')
                        ->whereHas('user', static function ($query): void {
                            $query->where('is_active', 1);
                        })
                        ->havingRaw('ratings_avg_rating >= ?', [4.0])
                        ->orderByDesc('ratings_avg_rating')
                        ->take($limit)
                        ->get();

                    // Transform data to match get-instructors API format
                    $data = $instructors->map(static function ($instructor) {
                        // Calculate course counts using user_id
                        // Only count courses that are active, published, approved, and have active curriculum
                        $activeCoursesCount = Course::where('user_id', $instructor->user_id)
                            ->where('is_active', 1)
                            ->where('status', 'publish')
                            ->where('approval_status', 'approved')
                            ->count();
                        $publishedCoursesCount = Course::where('user_id', $instructor->user_id)
                            ->where('is_active', 1)
                            ->where('status', 'publish')
                            ->where('approval_status', 'approved')
                            ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                            ->count();

                        // Calculate review count (ratings with reviews)
                        $reviewCount = \App\Models\Rating::where('rateable_type', \App\Models\Instructor::class)
                            ->where('rateable_id', $instructor->id)
                            ->whereNotNull('review')
                            ->where('review', '!=', '')
                            ->count();

                        // Calculate total students enrolled in instructor's courses
                        $studentEnrolledCount = \App\Models\OrderCourse::whereHas('course', static function ($q) use (
                            $instructor,
                        ): void {
                            $q->where('user_id', $instructor->user_id);
                        })
                            ->whereHas('order', static function ($q): void {
                                $q->where('status', 'completed');
                            })
                            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                            ->distinct('orders.user_id')
                            ->count('orders.user_id');

                        return [
                            'id' => $instructor->id,
                            'user_id' => $instructor->user_id,
                            'type' => $instructor->type,
                            'status' => $instructor->status,
                            'name' => $instructor->user->name ?? '',
                            'email' => $instructor->user->email ?? '',
                            'slug' => $instructor->user->slug ?? '',
                            'profile' => $instructor->user->profile ?? '',
                            'qualification' => $instructor->personal_details->qualification ?? '',
                            'years_of_experience' => $instructor->personal_details->years_of_experience ?? 0,
                            'skills' => $instructor->personal_details->skills ?? '',
                            'about_me' => $instructor->personal_details->about_me ?? '',
                            'preview_video' => $instructor->personal_details->preview_video ?? '',
                            'team_name' => $instructor->personal_details->team_name ?? '',
                            'average_rating' => round($instructor->ratings_avg_rating ?? 0, 1),
                            'total_ratings' => (int) ($instructor->ratings_count ?? 0),
                            'review_count' => $reviewCount,
                            'student_enrolled_count' => $studentEnrolledCount,
                            'active_courses_count' => $activeCoursesCount,
                            'published_courses_count' => $publishedCoursesCount,
                        ];
                    });
                    break;

                case 'wishlist':
                    if ($user) {
                        $query = $user
                            ->wishlistCourses()
                            ->with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                            ->where('is_active', 1)
                            ->where('approval_status', 'approved');

                        if ($request->filled('course_type')) {
                            $query->whereIn('course_type', explode(',', $request->course_type));
                        }

                        if ($request->filled('level')) {
                            $query->whereIn('level', explode(',', $request->level));
                        }

                        $data = $query->take($limit)->get();
                    } else {
                        $data = collect();
                    }
                    break;

                case 'why_choose_us':
                    $whyChooseUsSettings = HelperService::systemSettings([
                        'why_choose_us_title',
                        'why_choose_us_description',
                        'why_choose_us_point_1',
                        'why_choose_us_point_2',
                        'why_choose_us_point_3',
                        'why_choose_us_point_4',
                        'why_choose_us_point_5',
                        'why_choose_us_image',
                        'why_choose_us_button_text',
                        'why_choose_us_button_link',
                    ]);

                    $data = collect([
                        'title' => $whyChooseUsSettings['why_choose_us_title'] ?? '',
                        'description' => $whyChooseUsSettings['why_choose_us_description'] ?? '',
                        'image' => $whyChooseUsSettings['why_choose_us_image'] ?? null,
                        'button_text' => $whyChooseUsSettings['why_choose_us_button_text'] ?? '',
                        'button_link' => $whyChooseUsSettings['why_choose_us_button_link'] ?? '',
                        'points' => array_filter([
                            $whyChooseUsSettings['why_choose_us_point_1'] ?? '',
                            $whyChooseUsSettings['why_choose_us_point_2'] ?? '',
                            $whyChooseUsSettings['why_choose_us_point_3'] ?? '',
                            $whyChooseUsSettings['why_choose_us_point_4'] ?? '',
                            $whyChooseUsSettings['why_choose_us_point_5'] ?? '',
                        ]),
                    ]);
                    break;

                case 'become_instructor':
                    // In single instructor mode, return empty data
                    if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                        $data = collect([
                            'title' => '',
                            'description' => '',
                            'button_text' => '',
                            'button_link' => '',
                            'steps' => [],
                        ]);
                        break;
                    }

                    $becomeInstructorSettings = HelperService::systemSettings([
                        'become_instructor_title',
                        'become_instructor_description',
                        'become_instructor_button_text',
                        'become_instructor_button_link',
                        'become_instructor_step_1_title',
                        'become_instructor_step_1_description',
                        'become_instructor_step_1_image',
                        'become_instructor_step_2_title',
                        'become_instructor_step_2_description',
                        'become_instructor_step_2_image',
                        'become_instructor_step_3_title',
                        'become_instructor_step_3_description',
                        'become_instructor_step_3_image',
                        'become_instructor_step_4_title',
                        'become_instructor_step_4_description',
                        'become_instructor_step_4_image',
                    ]);

                    $data = collect([
                        'title' => $becomeInstructorSettings['become_instructor_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_description'] ?? '',
                        'button_text' => $becomeInstructorSettings['become_instructor_button_text'] ?? '',
                        'button_link' => $becomeInstructorSettings['become_instructor_button_link'] ?? '',
                        'steps' => array_filter([
                            [
                                'step' => 1,
                                'title' => $becomeInstructorSettings['become_instructor_step_1_title'] ?? '',
                                'description' =>
                                    $becomeInstructorSettings['become_instructor_step_1_description'] ?? '',
                                'image' => $becomeInstructorSettings['become_instructor_step_1_image'] ?? null,
                            ],
                            [
                                'step' => 2,
                                'title' => $becomeInstructorSettings['become_instructor_step_2_title'] ?? '',
                                'description' =>
                                    $becomeInstructorSettings['become_instructor_step_2_description'] ?? '',
                                'image' => $becomeInstructorSettings['become_instructor_step_2_image'] ?? null,
                            ],
                            [
                                'step' => 3,
                                'title' => $becomeInstructorSettings['become_instructor_step_3_title'] ?? '',
                                'description' =>
                                    $becomeInstructorSettings['become_instructor_step_3_description'] ?? '',
                                'image' => $becomeInstructorSettings['become_instructor_step_3_image'] ?? null,
                            ],
                            [
                                'step' => 4,
                                'title' => $becomeInstructorSettings['become_instructor_step_4_title'] ?? '',
                                'description' =>
                                    $becomeInstructorSettings['become_instructor_step_4_description'] ?? '',
                                'image' => $becomeInstructorSettings['become_instructor_step_4_image'] ?? null,
                            ],
                        ], static function ($step) {
                            return !empty($step['title']); // Filter steps with empty titles
                        }),
                    ]);
                    break;

                case 'recommend_for_you':
                    if ($user) {
                        $recommendedCourseIds = [];

                        // Get user's purchased course IDs
                        $purchasedCourseIds = \App\Models\OrderCourse::whereHas('order', static function ($q) use (
                            $user,
                        ): void {
                            $q->where('user_id', $user->id)->where('status', 'completed');
                        })
                            ->pluck('course_id')
                            ->toArray();

                        // 1. Get instructor IDs from purchased courses
                        if (!empty($purchasedCourseIds)) {
                            $instructorIds = Course::whereIn('id', $purchasedCourseIds)
                                ->pluck('user_id')
                                ->unique()
                                ->toArray();

                            // Get other courses from these instructors (excluding already purchased)
                            $instructorCourseIds = Course::where('is_active', 1)
                                ->where('status', 'publish')
                                ->where('approval_status', 'approved')
                                ->whereIn('user_id', $instructorIds)
                                ->whereNotIn('id', $purchasedCourseIds)
                                ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                                ->pluck('id')
                                ->toArray();

                            $recommendedCourseIds = array_merge($recommendedCourseIds, $instructorCourseIds);
                        }

                        // 2. Get wishlisted courses (excluding already purchased)
                        $wishlistCourseIds = \App\Models\Wishlist::where('user_id', $user->id)
                            ->whereNotIn('course_id', $purchasedCourseIds)
                            ->pluck('course_id')
                            ->toArray();

                        $recommendedCourseIds = array_merge($recommendedCourseIds, $wishlistCourseIds);

                        // 3. Get courses based on search history (excluding already purchased)
                        $searchHistories = \App\Models\SearchHistory::where('user_id', $user->id)
                            ->orderBy('last_searched_at', 'desc')
                            ->limit(10)
                            ->pluck('query')
                            ->toArray();

                        if (!empty($searchHistories)) {
                            $searchBasedCourseIds = Course::where('is_active', 1)
                                ->where('status', 'publish')
                                ->where('approval_status', 'approved')
                                ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ;
            })
                                ->where(static function ($q) use ($searchHistories): void {
                                    foreach ($searchHistories as $searchQuery) {
                                        $q
                                            ->orWhere('title', 'LIKE', "%{$searchQuery}%")
                                            ->orWhere('short_description', 'LIKE', "%{$searchQuery}%")
                                            ->orWhereHas('category', static function ($catQuery) use (
                                                $searchQuery,
                                            ): void {
                                                $catQuery->where('name', 'LIKE', "%{$searchQuery}%");
                                            })
                                            ->orWhereHas('tags', static function ($tagQuery) use ($searchQuery): void {
                                                $tagQuery->where('tag', 'LIKE', "%{$searchQuery}%");
                                            });
                                    }
                                })
                                ->whereNotIn('id', $purchasedCourseIds)
                                ->pluck('id')
                                ->toArray();

                            $recommendedCourseIds = array_merge($recommendedCourseIds, $searchBasedCourseIds);
                        }

                        // Remove duplicates
                        $recommendedCourseIds = array_unique($recommendedCourseIds);

                        if (!empty($recommendedCourseIds)) {
                            // Fetch all recommended courses
                            $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                                ->where('is_active', 1)
                                ->where('status', 'publish')
                                ->where('approval_status', 'approved')
                                ->whereHas('user', static function ($userQuery): void {
                                    $userQuery->where('is_active', 1);
                                })
                                ->whereIn('id', $recommendedCourseIds)
                                ->withAvg('ratings', 'rating')
                                ->withCount('ratings');

                            if ($request->filled('course_type')) {
                                $query->whereIn('course_type', explode(',', $request->course_type));
                            }

                            if ($request->filled('level')) {
                                $query->whereIn('level', explode(',', $request->level));
                            }

                            $data = $query->inRandomOrder()->take($limit)->get();
                        } else {
                            // If no recommendations found, show popular courses
                            $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                                ->where('is_active', 1)
                                ->where('status', 'publish')
                                ->where('approval_status', 'approved')
                                ->whereHas('user', static function ($userQuery): void {
                                    $userQuery->where('is_active', 1);
                                })
                                ->withAvg('ratings', 'rating')
                                ->withCount('ratings')
                                ->orderByDesc('ratings_avg_rating');

                            if ($request->filled('course_type')) {
                                $query->whereIn('course_type', explode(',', $request->course_type));
                            }

                            if ($request->filled('level')) {
                                $query->whereIn('level', explode(',', $request->level));
                            }

                            $data = $query->take($limit)->get();
                        }
                    } else {
                        // For guest users, show popular courses
                        $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                            ->where('is_active', 1)
                            ->where('status', 'publish')
                            ->where('approval_status', 'approved')
                            ->whereHas('user', static function ($userQuery): void {
                                $userQuery->where('is_active', 1);
                            })
                            ->withAvg('ratings', 'rating')
                            ->withCount('ratings')
                            ->orderByDesc('ratings_avg_rating');

                        if ($request->filled('course_type')) {
                            $query->whereIn('course_type', explode(',', $request->course_type));
                        }

                        if ($request->filled('level')) {
                            $query->whereIn('level', explode(',', $request->level));
                        }

                        $data = $query->take($limit)->get();
                    }
                    break;

                case 'searching_based':
                    if ($user) {
                        // Get user's recent search queries
                        $searchHistories = \App\Models\SearchHistory::where('user_id', $user->id)
                            ->orderBy('last_searched_at', 'desc')
                            ->limit(10)
                            ->pluck('query')
                            ->toArray();

                        if (!empty($searchHistories)) {
                            // Get user's already purchased/wishlisted courses to exclude
                            $purchasedCourseIds = \App\Models\OrderCourse::whereHas('order', static function ($q) use (
                                $user,
                            ): void {
                                $q->where('user_id', $user->id)->where('status', 'completed');
                            })
                                ->pluck('course_id')
                                ->toArray();

                            $wishlistCourseIds = \App\Models\Wishlist::where('user_id', $user->id)
                                ->pluck('course_id')
                                ->toArray();

                            $excludeCourseIds = array_unique(array_merge($purchasedCourseIds, $wishlistCourseIds));

                            // Search courses based on search history
                            $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                                ->where('is_active', 1)
                                ->where('status', 'publish')
                                ->where('approval_status', 'approved')
                                ->whereHas('user', static function ($userQuery): void {
                                    $userQuery->where('is_active', 1);
                                })
                                ->where(static function ($q) use ($searchHistories): void {
                                    foreach ($searchHistories as $searchQuery) {
                                        $q
                                            ->orWhere('title', 'LIKE', "%{$searchQuery}%")
                                            ->orWhere('short_description', 'LIKE', "%{$searchQuery}%")
                                            ->orWhereHas('category', static function ($catQuery) use (
                                                $searchQuery,
                                            ): void {
                                                $catQuery->where('name', 'LIKE', "%{$searchQuery}%");
                                            })
                                            ->orWhereHas('tags', static function ($tagQuery) use ($searchQuery): void {
                                                $tagQuery->where('tag', 'LIKE', "%{$searchQuery}%");
                                            });
                                    }
                                })
                                ->withAvg('ratings', 'rating')
                                ->withCount('ratings');

                            if (!empty($excludeCourseIds)) {
                                $query->whereNotIn('id', $excludeCourseIds);
                            }

                            if ($request->filled('course_type')) {
                                $query->whereIn('course_type', explode(',', $request->course_type));
                            }

                            if ($request->filled('level')) {
                                $query->whereIn('level', explode(',', $request->level));
                            }

                            $data = $query->orderByDesc('ratings_avg_rating')->take($limit)->get();
                        } else {
                            // No search history, return empty data
                            $data = collect();
                        }
                    } else {
                        // For guest users, return empty data (no search history)
                        $data = collect();
                    }
                    break;

                case 'my_learning':
                    if ($user) {
                        // Get enrolled courses through completed orders (same as getMyLearning)
                        // Load all order courses first, then filter in application logic
                        $enrolledCoursesCollection = app(\App\Services\UserEnrollmentService::class)->resolveEnrolledCourses($user->id, static function($query) {
                            $query->with([
                                'category',
                                'user',
                                'taxes',
                                'ratings.user',
                                'chapters' => static function ($chapterQuery): void {
                                    $chapterQuery
                                        ->where('is_active', true)
                                        ->with(['lectures', 'quizzes', 'assignments', 'resources']);
                                },
                            ])
                            ->withAvg('ratings', 'rating')
                            ->withCount('ratings')
                            ->where('is_active', 1)
                            ->where('status', 'publish');
                        });

                        // Sort by purchase date (most recent first) and extract courses
                        $enrolledCourses = $enrolledCoursesCollection
                            ->sortByDesc('purchase_date')
                            ->pluck('course')
                            ->filter()
                            ->unique('id')
                            ->values();

                        // Apply filters
                        if ($request->filled('course_type')) {
                            $courseTypes = explode(',', $request->course_type);
                            $enrolledCourses = $enrolledCourses
                                ->filter(static fn($course) => $course && in_array($course->course_type, $courseTypes))
                                ->values();
                        }

                        if ($request->filled('level')) {
                            $levels = explode(',', $request->level);
                            $enrolledCourses = $enrolledCourses
                                ->filter(static fn($course) => $course && in_array($course->level, $levels))
                                ->values();
                        }

                        // Preload data for N+1 fixes
                        $courseIdsArray = $enrolledCourses->pluck('id')->toArray();

                        $wishlistedCourseIds = \App\Models\Wishlist::where('user_id', $user->id)
                            ->whereIn('course_id', $courseIdsArray)
                            ->pluck('course_id')
                            ->toArray();

                        $latestTrackings = \App\Models\UserCurriculumTracking::where('user_id', $user->id)
                            ->whereHas('chapter', function($q) use ($courseIdsArray) { 
                                $q->whereIn('course_id', $courseIdsArray); 
                            })
                            ->with('chapter')
                            ->orderByDesc('completed_at')
                            ->get()
                            ->groupBy(function($item) {
                                return $item->chapter->course_id ?? 0;
                            });

                        $firstChapters = \App\Models\Course\CourseChapter\CourseChapter::whereIn('course_id', $courseIdsArray)
                            ->where('is_active', 1)
                            ->orderBy('chapter_order')
                            ->get()
                            ->groupBy('course_id');

                        // Transform courses with progress tracking (same format as getMyLearning)
                        $data = $enrolledCourses
                            ->map(function ($course) use (
                                $user,
                                $wishlistedCourseIds,
                                $latestTrackings,
                                $firstChapters
                            ) {
                                if (!$course) {
                                    return null;
                                }

                                // Remove manual N+1 chapter tracking loops and use the optimized cache service
                                $progressService = app(\App\Services\CourseProgressService::class);
                                $progress = $progressService->getProgressWithCache($user->id, $course->id);

                                $completedCurriculumItems = $progress->completed_items;
                                $totalCurriculumItems = $progress->total_items;
                                $progressPercentage = $progress->progress_percentage;
                                $lastLearnedAt = $progress->last_accessed_at ? $progress->last_accessed_at->toDateTimeString() : null;

                                // Determine current chapter name efficiently without loading full relations
                                $currentChapterName = null;
                                if ($completedCurriculumItems > 0) {
                                    $lastTracking = $latestTrackings->get($course->id)?->first();
                                        
                                    if ($lastTracking && $lastTracking->chapter) {
                                        $currentChapterName = $lastTracking->chapter->title;
                                        // Remove "Chapter X:" prefix if exists
                                        $currentChapterName = preg_replace('/^Chapter\s+\d+:\s*/i', '', $currentChapterName);
                                        $currentChapterName = trim($currentChapterName);
                                    }
                                } else {
                                    $firstChapter = $firstChapters->get($course->id)?->first();
                                    $currentChapterName = $firstChapter ? $firstChapter->title : null;
                                }

                                // Calculate discount percentage
                                $discountPercentage = 0;
                                if ($course->has_discount) {
                                    $discountPercentage = round(
                                        (($course->price - $course->discount_price) / $course->price) * 100,
                                        2,
                                    );
                                }

                                // Check if wishlisted
                                $isWishlisted = in_array($course->id, $wishlistedCourseIds);

                                // Always enrolled (true) for my learning
                                $isEnrolled = true;

                                // Calculate pricing using service
                                $coursePricingData = $this->pricingService->calculateCoursePricing($course);

                                return [
                                    'id' => $course->id,
                                    'slug' => $course->slug,
                                    'image' => $course->thumbnail,
                                    'category_id' => $course->category->id ?? null,
                                    'category_name' => $course->category->name ?? null,
                                    'course_type' => $course->course_type,
                                    'level' => $course->level,
                                    'sequential_access' => $course->sequential_access ?? true,
                                    'certificate_enabled' => $course->certificate_enabled ?? false,
                                    'certificate_fee' => $course->certificate_fee
                                        ? (float) $course->certificate_fee
                                        : null,
                                    'ratings' => $course->ratings_count ?? 0,
                                    'average_rating' => round($course->ratings_avg_rating ?? 0, 2),
                                    'title' => $course->title,
                                    'short_description' => $course->short_description,
                                    'author_id' => $course->user->id ?? null,
                                    'author_name' => $course->user->name ?? null,
                                    'author_slug' => $course->user->slug ?? null,
                                    ...$coursePricingData,
                                    'discount_percentage' => $discountPercentage,
                                    'is_wishlisted' => $isWishlisted,
                                    'is_enrolled' => $isEnrolled,
                                    'enrolled_at' => $course->created_at,
                                    // Progress tracking data
                                    'total_chapters' => $totalChapters,
                                    'completed_chapters' => $completedChapters,
                                    'current_chapter_name' => $currentChapterName,
                                    'total_curriculum_items' => $totalCurriculumItems,
                                    'completed_curriculum_items' => $completedCurriculumItems,
                                    'progress_percentage' => $progressPercentage,
                                    'progress_status' => $this->getProgressStatus($progressPercentage),
                                    'last_learned_at' => $lastLearnedAt, // For sorting
                                ];
                            })
                            ->filter(static fn($course) => $course !== null)
                            ->sortByDesc(
                                // Sort by last_learned_at (most recent first), fallback to enrolled_at if no learning activity

                                static fn($course) => (
                                    $course['last_learned_at'] ?? $course['enrolled_at'] ?? '1970-01-01 00:00:00'
                                ),
                            )
                            ->values()
                            ->take($limit);
                    } else {
                        $data = collect();
                    }
                    break;

                default:
                    $data = collect();
                    break;
                }
            }

            // Map courses only (skip for offers/instructors since structure is different)
            // Note: my_learning is already transformed above, so skip it here
            if (in_array($section->type, [
                'newly_added_courses',
                'top_rated_courses',
                'free_courses',
                'wishlist',
                'recommend_for_you',
                'searching_based',
                'most_viewed_courses',
                'courses',
            ])) {
                $data = $data->map(function ($course) {
                    $discountPercentage = 0;
                    if ($course->has_discount && $course->price > 0 && $course->discount_price > 0) {
                        $discountPercentage = round(
                            (($course->price - $course->discount_price) / $course->price) * 100,
                            2,
                        );
                    }

                    $isWishlisted = Auth::check() ? $course->wishlistedByUsers->contains('id', Auth::id()) : false;

                    $isEnrolled = Auth::check()
                        ? \App\Models\OrderCourse::whereHas('order', static function ($q): void {
                            $q->where('user_id', Auth::id())->where('status', 'completed');
                        })
                            ->where('course_id', $course->id)
                            ->exists()
                        : false;

                    // If enrolled, check if there's an approved refund - if so, set is_enrolled to false
                    if ($isEnrolled && Auth::check()) {
                        $hasApprovedRefund = RefundRequest::where('user_id', Auth::id())
                            ->where('course_id', $course->id)
                            ->where('status', 'approved')
                            ->exists();
                        if ($hasApprovedRefund) {
                            $isEnrolled = false;
                        }
                    }

                    // Calculate pricing using service
                    $coursePricingData = $this->pricingService->calculateCoursePricing($course);

                    return [
                        'id' => $course->id,
                        'slug' => $course->slug,
                        'image' => $course->thumbnail,
                        'category_id' => $course->category->id ?? null,
                        'category_name' => $course->category->name ?? null,
                        'course_type' => $course->course_type,
                        'level' => $course->level,
                        'ratings' => $course->ratings_count ?? 0,
                        'average_rating' => round($course->ratings_avg_rating ?? 0, 2),
                        'title' => $course->title,
                        'short_description' => $course->short_description,
                        'author_name' => $course->user->name ?? null,
                        ...$coursePricingData,
                        'discount_percentage' => $discountPercentage,
                        'is_wishlisted' => $isWishlisted,
                        'is_enrolled' => $isEnrolled,
                    ];
                });
            }

            return [
                'id' => $section->id,
                'title' => $section->title,
                'type' => $section->type,
                'layout' => $section->layout,
                'grid_columns' => $section->grid_columns,
                'background' => $section->background,
                'sorting' => $section->sorting,
                'responsive_limits' => $section->responsive_limits,
                'visibility_permissions' => $section->visibility_permissions,
                'visibility_devices' => $section->visibility_devices,
                'show_on_web' => $section->show_on_web,
                'show_on_mobile' => $section->show_on_mobile,
                'manual_courses' => $section->manualCourses->pluck('id')->values()->all(),
                'data' => $data,
            ];
        });

        return ApiResponseService::successResponse('Feature sections fetched.', $result);
    }

    /**
     * Get progress status based on percentage
     */
    private function getProgressStatus($percentage)
    {
        if ($percentage == 0) {
            return 'not_started';
        } elseif ($percentage < 25) {
            return 'just_started';
        } elseif ($percentage < 50) {
            return 'in_progress';
        } elseif ($percentage < 75) {
            return 'almost_done';
        } elseif ($percentage < 100) {
            return 'nearly_complete';
        } else {
            return 'completed';
        }
    }

    public function interact(Request $request)
    {
        $interactions = $request->all();

        // Ensure it's an array of arrays
        if (!is_array($interactions) || (count($interactions) > 0 && !is_array(reset($interactions)))) {
            return ApiResponseService::errorResponse('Payload must be an array of objects');
        }

        foreach ($interactions as $interaction) {
            if (isset($interaction['id'], $interaction['type']) && in_array($interaction['type'], ['view', 'click'])) {
                $id = (int) $interaction['id'];
                $type = $interaction['type'];
                $key = "feature_section:{$id}:{$type}s";
                try {
                    \Illuminate\Support\Facades\Redis::incr($key);
                } catch (\Throwable $e) {
                    // Redis may not be available in all environments (local/staging).
                    // Interaction tracking is non-critical — log and continue silently.
                    \Illuminate\Support\Facades\Log::warning('interact: Redis unavailable, skipping counter increment.', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ApiResponseService::successResponse('Interactions recorded.');
    }
}
