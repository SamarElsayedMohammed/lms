<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OrderCourse;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\PricingCalculationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WishlistApiController extends Controller
{
    public function __construct(
        private readonly PricingCalculationService $pricingService,
    ) {}

    /**
     * Get user's wishlist
     */
    public function getWishlist(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            $perPage = $request->per_page ?? 15;
            $page = $request->page ?? 1;

            // Your wishlist is a collection
            $wishlistCollection = $this->getFormattedWishlist();

            // Manually slice the collection and reset keys
            $wishlist = new LengthAwarePaginator(
                $wishlistCollection->forPage($page, $perPage)->values(),
                $wishlistCollection->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );

            return ApiResponseService::successResponse('Wishlist fetched successfully', $wishlist);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Wishlist Error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse('Failed to fetch wishlist: ' . $e->getMessage());
        }
    }

    /**
     * Add or remove a course from wishlist based on status
     * status: 1 = add to wishlist, status: 0 = remove from wishlist
     */
    public function addUpdateWishlist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;
            $status = (int) $request->status;

            $existingWishlist = Wishlist::where('user_id', $user?->id)->where('course_id', $courseId)->first();

            if ($status === 1) {
                // Add to wishlist
                if ($existingWishlist) {
                    return ApiResponseService::errorResponse('Course already in wishlist');
                }

                Wishlist::create([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                ]);

                $message = 'Course added to wishlist successfully';
            } else {
                // Remove from wishlist
                if (!$existingWishlist) {
                    return ApiResponseService::errorResponse('Course not found in wishlist');
                }

                $existingWishlist->delete();
                $message = 'Course removed from wishlist successfully';
            }

            return ApiResponseService::successResponse($message, [
                'course_id' => $courseId,
                'status' => $status,
                'is_wishlisted' => $status === 1,
                'wishlist_count' => Wishlist::where('user_id', $user->id)->count(),
            ]);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update wishlist');
            return ApiResponseService::errorResponse('Failed to update wishlist');
        }
    }

    /**
     * Shared method to fetch and format wishlist
     */
    private function getFormattedWishlist()
    {
        $user = Auth::user();

        if (!$user) {
            return collect([]);
        }

        $wishlistItems = $user
            ->wishlists()
            ->with([
                'course' => static function ($query): void {
                    $query
                        ->with([
                            'category',
                            'user',
                            'learnings',
                            'requirements',
                            'tags',
                            'language',
                            'instructors',
                            'taxes',
                            'ratings.user',
                        ])
                        ->withAvg('ratings', 'rating')
                        ->withCount('ratings')
                        // Always include wishlist courses even if inactive/unapproved/soft-deleted
                        ->withTrashed();
                },
            ])
            ->get();

        return $wishlistItems
            ->map(function ($item) {
                $course = $item->course;

                // Skip if course is null or deleted
                if (!$course) {
                    return null;
                }

                // Calculate discount percentage
                $discountPercentage = 0;
                if (isset($course->has_discount) && $course->has_discount) {
                    if ($course->price > 0) {
                        $discountPercentage = round(
                            (($course->price - $course->discount_price) / $course->price) * 100,
                            2,
                        );
                    }
                }

                // Check if user is enrolled in the course
                $isEnrolled = false;
                if (Auth::check()) {
                    if ($course->course_type === 'free') {
                        $isEnrolled = true;
                    } else {
                        $isEnrolled = OrderCourse::whereHas('order', static function ($q): void {
                            $q->where('user_id', Auth::id())->where('status', 'completed');
                        })
                            ->where('course_id', $course->id)
                            ->exists();
                    }
                }

                $pricing = $this->pricingService->calculateCoursePricing($course);

                return $this->pricingService->formatCourseWithPricing($course, $pricing, true, [
                    'category_id' => $course->category->id ?? null,
                    'category_name' => $course->category->name ?? null,
                    'course_type' => $course->course_type ?? 'free',
                    'level' => $course->level ?? 'beginner',
                    'ratings' => $course->ratings_count ?? 0,
                    'average_rating' => round($course->ratings_avg_rating ?? 0, 2),
                    'short_description' => $course->short_description ?? '',
                    'author_name' => $course->user->name ?? 'Unknown',
                    'discount_percentage' => $discountPercentage,
                    'is_enrolled' => $isEnrolled,
                ]);
            })
            ->filter()
            ->values(); // Remove null items and ensure clean array indexes
    }
}
