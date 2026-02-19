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
            // Count active courses that have at least one active chapter
            $courseCount = Course::where('is_active', 1)
                ->whereHas('chapters', static function ($query): void {
                    $query->where('is_active', 1);
                })
                ->count();

            // Count active instructors who have at least one course with a user_id
            $instructorCount = Instructor::where('status', 'approved')
                ->whereIn('user_id', static function ($query): void {
                    $query->select('user_id')->from('courses')->whereNotNull('user_id');
                })
                ->count();

            // Count enrolled students (distinct users who have completed orders)
            $studentEnrollCount = User::whereHas('orders', static function ($query): void {
                $query->where('status', 'completed')->whereHas('orderCourses');
            })
                ->distinct()
                ->count();

            // Count positive feedback (ratings with 4 or 5 stars)
            $positiveFeedbackCount = Rating::whereIn('rating', [4, 5])->count();

            $data = [
                'course_count' => $courseCount,
                'instructor_count' => $instructorCount,
                'student_enroll_count' => $studentEnrollCount,
                'positive_feedback_count' => $positiveFeedbackCount,
            ];

            return ApiResponseService::successResponse('Counts retrieved successfully.', $data);
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getCounts method');
            return ApiResponseService::errorResponse();
        }
    }

    public function getCategoriesWithCourseCount()
    {
        try {
            $categories = Category::where('status', 1)->withCount([
                'courses as active_course_count' => static function ($query): void {
                    // Only count courses that are active, published, approved, and have at least one active chapter with curriculum
                    $query
                        ->where('is_active', true)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('chapters', static function ($chapterQuery): void {
                            $chapterQuery
                                ->where('is_active', true)
                                ->where(static function ($curriculumQuery): void {
                                    $curriculumQuery
                                        ->whereHas('lectures', static function ($lectureQuery): void {
                                            $lectureQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('quizzes', static function ($quizQuery): void {
                                            $quizQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                            $assignmentQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('resources', static function ($resourceQuery): void {
                                            $resourceQuery->where('is_active', true);
                                        });
                                });
                        });
                },
            ])->get();

            return ApiResponseService::successResponse(
                'Categories with active course count retrieved successfully.',
                $categories,
            );
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
            ApiResponseService::validationError($validator->errors()->first());
        }

        $sections = FeatureSection::where('is_active', 1)->orderBy('row_order')->get();

        $user = Auth::user();

        $result = $sections->map(function ($section) use ($user, $request) {
            $limit = $section->limit ?? 10;

            switch ($section->type) {
                case 'newly_added_courses':
                    $query = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                        ->where('is_active', 1)
                        ->where('status', 'publish')
                        ->where('approval_status', 'approved')
                        ->whereHas('user', static function ($userQuery): void {
                            $userQuery
                                ->where('is_active', 1)
                                ->where(static function ($query): void {
                                    $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                        $instructorQuery->where('status', 'approved');
                                    })->orWhereHas('roles', static function ($roleQuery): void {
                                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                    });
                                });
                        })
                        ->whereHas('chapters', static function ($chapterQuery): void {
                            $chapterQuery
                                ->where('is_active', true)
                                ->where(static function ($curriculumQuery): void {
                                    $curriculumQuery
                                        ->whereHas('lectures', static function ($lectureQuery): void {
                                            $lectureQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('quizzes', static function ($quizQuery): void {
                                            $quizQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                            $assignmentQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('resources', static function ($resourceQuery): void {
                                            $resourceQuery->where('is_active', true);
                                        });
                                });
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
                                ->where(static function ($query): void {
                                    $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                        $instructorQuery->where('status', 'approved');
                                    })->orWhereHas('roles', static function ($roleQuery): void {
                                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                    });
                                });
                        })
                        ->whereHas('chapters', static function ($chapterQuery): void {
                            $chapterQuery
                                ->where('is_active', true)
                                ->where(static function ($curriculumQuery): void {
                                    $curriculumQuery
                                        ->whereHas('lectures', static function ($lectureQuery): void {
                                            $lectureQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('quizzes', static function ($quizQuery): void {
                                            $quizQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                            $assignmentQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('resources', static function ($resourceQuery): void {
                                            $resourceQuery->where('is_active', true);
                                        });
                                });
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
                                ->where(static function ($query): void {
                                    $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                        $instructorQuery->where('status', 'approved');
                                    })->orWhereHas('roles', static function ($roleQuery): void {
                                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                    });
                                });
                        })
                        ->whereHas('chapters', static function ($chapterQuery): void {
                            $chapterQuery
                                ->where('is_active', true)
                                ->where(static function ($curriculumQuery): void {
                                    $curriculumQuery
                                        ->whereHas('lectures', static function ($lectureQuery): void {
                                            $lectureQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('quizzes', static function ($quizQuery): void {
                                            $quizQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                            $assignmentQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('resources', static function ($resourceQuery): void {
                                            $resourceQuery->where('is_active', true);
                                        });
                                });
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
                                ->where(static function ($query): void {
                                    $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                        $instructorQuery->where('status', 'approved');
                                    })->orWhereHas('roles', static function ($roleQuery): void {
                                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                    });
                                });
                        })
                        ->whereHas('chapters', static function ($chapterQuery): void {
                            $chapterQuery
                                ->where('is_active', true)
                                ->where(static function ($curriculumQuery): void {
                                    $curriculumQuery
                                        ->whereHas('lectures', static function ($lectureQuery): void {
                                            $lectureQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('quizzes', static function ($quizQuery): void {
                                            $quizQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                            $assignmentQuery->where('is_active', true);
                                        })
                                        ->orWhereHas('resources', static function ($resourceQuery): void {
                                            $resourceQuery->where('is_active', true);
                                        });
                                });
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
                            ->whereHas('chapters', static function ($chapterQuery): void {
                                $chapterQuery
                                    ->where('is_active', true)
                                    ->where(static function ($curriculumQuery): void {
                                        $curriculumQuery
                                            ->whereHas('lectures', static function ($lectureQuery): void {
                                                $lectureQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                $quizQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                $assignmentQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('resources', static function ($resourceQuery): void {
                                                $resourceQuery->where('is_active', true);
                                            });
                                    });
                            })
                            ->count();
                        $publishedCoursesCount = Course::where('user_id', $instructor->user_id)
                            ->where('is_active', 1)
                            ->where('status', 'publish')
                            ->where('approval_status', 'approved')
                            ->whereHas('user', static function ($userQuery): void {
                                $userQuery
                                    ->where('is_active', 1)
                                    ->where(static function ($query): void {
                                        $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                            $instructorQuery->where('status', 'approved');
                                        })->orWhereHas('roles', static function ($roleQuery): void {
                                            $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                        });
                                    });
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
                            ->where('approval_status', 'approved')
                            ->whereHas('chapters', static function ($chapterQuery): void {
                                $chapterQuery
                                    ->where('is_active', true)
                                    ->where(static function ($curriculumQuery): void {
                                        $curriculumQuery
                                            ->whereHas('lectures', static function ($lectureQuery): void {
                                                $lectureQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                $quizQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                $assignmentQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('resources', static function ($resourceQuery): void {
                                                $resourceQuery->where('is_active', true);
                                            });
                                    });
                            });

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
                                        ->where(static function ($query): void {
                                            $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                                $instructorQuery->where('status', 'approved');
                                            })->orWhereHas('roles', static function ($roleQuery): void {
                                                $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                            });
                                        });
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
                                        ->where(static function ($query): void {
                                            $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                                $instructorQuery->where('status', 'approved');
                                            })->orWhereHas('roles', static function ($roleQuery): void {
                                                $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                            });
                                        });
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
                                    $userQuery
                                        ->where('is_active', 1)
                                        ->where(static function ($query): void {
                                            $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                                $instructorQuery->where('status', 'approved');
                                            })->orWhereHas('roles', static function ($roleQuery): void {
                                                $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                            });
                                        });
                                })
                                ->whereHas('chapters', static function ($chapterQuery): void {
                                    $chapterQuery
                                        ->where('is_active', true)
                                        ->where(static function ($curriculumQuery): void {
                                            $curriculumQuery
                                                ->whereHas('lectures', static function ($lectureQuery): void {
                                                    $lectureQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                    $quizQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                    $assignmentQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                                    $resourceQuery->where('is_active', true);
                                                });
                                        });
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
                                    $userQuery
                                        ->where('is_active', 1)
                                        ->where(static function ($query): void {
                                            $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                                $instructorQuery->where('status', 'approved');
                                            })->orWhereHas('roles', static function ($roleQuery): void {
                                                $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                            });
                                        });
                                })
                                ->whereHas('chapters', static function ($chapterQuery): void {
                                    $chapterQuery
                                        ->where('is_active', true)
                                        ->where(static function ($curriculumQuery): void {
                                            $curriculumQuery
                                                ->whereHas('lectures', static function ($lectureQuery): void {
                                                    $lectureQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                    $quizQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                    $assignmentQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                                    $resourceQuery->where('is_active', true);
                                                });
                                        });
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
                                $userQuery
                                    ->where('is_active', 1)
                                    ->where(static function ($query): void {
                                        $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                            $instructorQuery->where('status', 'approved');
                                        })->orWhereHas('roles', static function ($roleQuery): void {
                                            $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                        });
                                    });
                            })
                            ->whereHas('chapters', static function ($chapterQuery): void {
                                $chapterQuery
                                    ->where('is_active', true)
                                    ->where(static function ($curriculumQuery): void {
                                        $curriculumQuery
                                            ->whereHas('lectures', static function ($lectureQuery): void {
                                                $lectureQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                $quizQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                $assignmentQuery->where('is_active', true);
                                            })
                                            ->orWhereHas('resources', static function ($resourceQuery): void {
                                                $resourceQuery->where('is_active', true);
                                            });
                                    });
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
                                    $userQuery
                                        ->where('is_active', 1)
                                        ->where(static function ($query): void {
                                            $query->whereHas('instructor_details', static function ($instructorQuery): void {
                                                $instructorQuery->where('status', 'approved');
                                            })->orWhereHas('roles', static function ($roleQuery): void {
                                                $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                                            });
                                        });
                                })
                                ->whereHas('chapters', static function ($chapterQuery): void {
                                    $chapterQuery
                                        ->where('is_active', true)
                                        ->where(static function ($curriculumQuery): void {
                                            $curriculumQuery
                                                ->whereHas('lectures', static function ($lectureQuery): void {
                                                    $lectureQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                                    $quizQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                                    $assignmentQuery->where('is_active', true);
                                                })
                                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                                    $resourceQuery->where('is_active', true);
                                                });
                                        });
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
                        $enrolledCoursesQuery = Order::where('user_id', $user->id)
                            ->where('status', 'completed')
                            ->with([
                                'orderCourses.course' => static function ($query): void {
                                    $query
                                        ->with([
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
                                        ->where('is_active', 1) // Only active courses
                                        ->where('status', 'publish'); // Only published courses

                                    // Removed strict whereHas constraints - user has already purchased these courses
                                    // We'll filter out courses without proper instructor details in application logic if needed
                                },
                            ]);

                        // Get refunded order IDs (orders that have approved refunds)
                        // RefundRequest -> transaction_id -> Transaction -> order_id
                        $refundedOrderIds = DB::table('refund_requests')
                            ->join('transactions', 'refund_requests.transaction_id', '=', 'transactions.id')
                            ->where('refund_requests.user_id', $user->id)
                            ->where('refund_requests.status', 'approved')
                            ->whereNotNull('refund_requests.transaction_id')
                            ->whereNotNull('transactions.order_id')
                            ->select('transactions.order_id')
                            ->distinct()
                            ->pluck('order_id')
                            ->map(static function ($id) {
                                return (int) $id; // Ensure integer type
                            })
                            ->toArray();

                        // Get all enrolled courses and filter based on refunded orders
                        $orders = $enrolledCoursesQuery->get();
                        $enrolledCoursesWithPurchaseDate = collect();

                        foreach ($orders as $order) {
                            // Skip if this order has been refunded
                            $orderId = (int) $order->id; // Ensure integer type for comparison
                            if (in_array($orderId, $refundedOrderIds, true)) {
                                continue; // Skip this order as it has been refunded
                            }

                            foreach ($order->orderCourses as $orderCourse) {
                                // Check if course exists and is valid (active and published)
                                if (
                                    !(
                                        $orderCourse->course
                                        && $orderCourse->course->is_active == 1
                                        && $orderCourse->course->status == 'publish'
                                    )
                                ) {
                                    continue;
                                }

                                $courseId = $orderCourse->course->id;
                                $purchaseDate = $order->created_at;

                                // Find existing entry index
                                $existingIndex = $enrolledCoursesWithPurchaseDate->search(
                                    static fn($item) => $item['course_id'] == $courseId,
                                );

                                if ($existingIndex !== false) {
                                    // Update if this purchase is more recent
                                    $existing = $enrolledCoursesWithPurchaseDate[$existingIndex];
                                    if ($purchaseDate->gt($existing['purchase_date'])) {
                                        $enrolledCoursesWithPurchaseDate[$existingIndex] = [
                                            'course_id' => $courseId,
                                            'course' => $orderCourse->course,
                                            'purchase_date' => $purchaseDate,
                                        ];
                                    }
                                } else {
                                    // Add new course with purchase date
                                    $enrolledCoursesWithPurchaseDate->push([
                                        'course_id' => $courseId,
                                        'course' => $orderCourse->course,
                                        'purchase_date' => $purchaseDate,
                                    ]);
                                }
                            }
                        }

                        // Sort by purchase date (most recent first) and extract courses
                        $enrolledCourses = $enrolledCoursesWithPurchaseDate
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

                        // Transform courses with progress tracking (same format as getMyLearning)
                        $data = $enrolledCourses
                            ->map(function ($course) use ($user) {
                                if (!$course) {
                                    return null;
                                }

                                // Load course with chapters and their relationships for progress calculation
                                $courseWithChapters = $course->load([
                                    'chapters' => static function ($q): void {
                                        $q->with([
                                            'lectures',
                                            'quizzes',
                                            'assignments',
                                            'resources',
                                        ]);
                                    },
                                ]);

                                // Calculate total chapters
                                $totalChapters = 0;
                                $totalCurriculumItems = 0;

                                // Get chapter IDs for progress tracking
                                $chapterIds = $courseWithChapters->chapters->pluck('id')->toArray();

                                // Calculate completed curriculum items
                                $completedCurriculumItems = 0;
                                $completedChapters = 0;
                                $progressPercentage = 0;
                                $currentChapterName = null;
                                $lastCompletedChapterId = null;

                                if (!empty($chapterIds)) {
                                    // Count completed curriculum items
                                    $completedCurriculumItems = UserCurriculumTracking::where('user_id', $user->id)
                                        ->whereIn('course_chapter_id', $chapterIds)
                                        ->where('status', 'completed')
                                        ->count();

                                    // Calculate completed chapters (chapters where all items are completed)
                                    foreach ($courseWithChapters->chapters as $chapter) {
                                        $chapterTotalItems =
                                            $chapter->lectures->count()
                                            + $chapter->quizzes->count()
                                            + $chapter->assignments->count()
                                            + $chapter->resources->count();

                                        if ($chapterTotalItems > 0) {
                                            $totalChapters++;
                                            $totalCurriculumItems += $chapterTotalItems;

                                            $chapterCompletedItems = UserCurriculumTracking::where('user_id', $user->id)
                                                ->where('course_chapter_id', $chapter->id)
                                                ->where('status', 'completed')
                                                ->count();

                                            if ($chapterCompletedItems >= $chapterTotalItems) {
                                                $completedChapters++;
                                                $lastCompletedChapterId = $chapter->id;
                                            }
                                        }
                                    }

                                    // Calculate progress percentage
                                    if ($totalChapters > 0) {
                                        $progressPercentage = round(($completedChapters / $totalChapters) * 100, 2);
                                    }
                                }

                                // Determine current chapter name
                                if ($completedChapters > 0 && $lastCompletedChapterId) {
                                    $lastCompletedChapter = $courseWithChapters->chapters->firstWhere(
                                        'id',
                                        $lastCompletedChapterId,
                                    );
                                    $currentChapterName = $lastCompletedChapter ? $lastCompletedChapter->title : null;
                                } else {
                                    $firstChapter = $courseWithChapters->chapters->first();
                                    $currentChapterName = $firstChapter ? $firstChapter->title : null;
                                }

                                // Remove "Chapter X:" prefix if exists
                                if ($currentChapterName) {
                                    $currentChapterName = preg_replace(
                                        '/^Chapter\s+\d+:\s*/i',
                                        '',
                                        $currentChapterName,
                                    );
                                    $currentChapterName = trim($currentChapterName);
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
                                $isWishlisted = \App\Models\Wishlist::where('user_id', $user->id)
                                    ->where('course_id', $course->id)
                                    ->exists();

                                // Always enrolled (true) for my learning
                                $isEnrolled = true;

                                // Get last learned timestamp (last completed_at from UserCurriculumTracking)
                                $lastLearnedAt = null;
                                if (!empty($chapterIds)) {
                                    $lastTracking = UserCurriculumTracking::where('user_id', $user->id)
                                        ->whereIn('course_chapter_id', $chapterIds)
                                        ->where('status', 'completed')
                                        ->whereNotNull('completed_at')
                                        ->orderBy('completed_at', 'desc')
                                        ->first();

                                    $lastLearnedAt = $lastTracking && $lastTracking->completed_at
                                        ? $lastTracking->completed_at->toDateTimeString()
                                        : null;
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
}
