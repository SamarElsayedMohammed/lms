<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\PricingCalculationService;
use App\Services\UserEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WishlistApiController extends Controller
{
    private readonly PricingCalculationService $pricingService;

    public function __construct(
        ?PricingCalculationService $pricingService = null,
        private readonly ?UserEnrollmentService $enrollmentService = null,
    ) {
        $this->pricingService = $pricingService ?? app(PricingCalculationService::class);
    }

    /**
     * Get user's wishlist with DB-level pagination and batch-loaded access states
     */
    public function getWishlist(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            $perPage = max(1, min((int) ($request->per_page ?? 15), 50));

            // Bounded database-level pagination
            $paginator = $user
                ->wishlists()
                ->latest('id')
                ->with([
                    'course' => static function ($query): void {
                        $query
                            ->with([
                                'category:id,name',
                                'user:id,name',
                                'learnings',
                                'requirements',
                                'tags',
                                'language',
                                'instructors',
                                'taxes',
                                'ratings.user',
                            ])
                            ->withAvg(['ratings' => static function ($q): void { $q->where('status', 'approved'); }], 'rating')
                            ->withCount(['ratings' => static function ($q): void { $q->where('status', 'approved'); }])
                            ->withTrashed();
                    },
                ])
                ->paginate($perPage);

            $pageItems = $paginator->getCollection();
            $enrolledCourseIds = ($this->enrollmentService ?? app(UserEnrollmentService::class))
                ->resolveEnrolledCourseIds((int) $user->id)
                ->pluck('course_id')
                ->map(static fn ($courseId): int => (int) $courseId)
                ->flip()
                ->all();

            // Format and enrich page items using the same enrollment/access source as My Learning.
            $formattedItems = $pageItems
                ->map(function ($item) use ($enrolledCourseIds, $user) {
                    $course = $item->course;

                    if (!$course) {
                        return null;
                    }

                    $isDeleted = $course->trashed();
                    $isActive = (bool) $course->is_active && $course->status === 'publish';
                    $isApproved = $course->approval_status === 'approved';
                    $isAvailable = !$isDeleted && $isActive && $isApproved;
                    $availabilityStatus = $isDeleted ? 'removed' : (!$isActive ? 'inactive' : (!$isApproved ? 'unapproved' : 'available'));

                    $isEnrolled = $isAvailable && isset($enrolledCourseIds[(int) $course->id]);
                    $hasAccess = $isAvailable && ($isEnrolled || $course->isFreeNow());

                    // Safe pricing calculation: do not crash on soft-deleted or partial course relations
                    if ($isAvailable) {
                        $pricing = $this->pricingService->calculateCoursePricing($course, null, null, null, $user);
                    } else {
                        $pricing = [
                            'original_price' => (float) ($course->price ?? 0),
                            'course_discount' => 0.0,
                            'subtotal' => (float) ($course->price ?? 0),
                            'promo_discount' => 0.0,
                            'taxable_amount' => (float) ($course->price ?? 0),
                            'tax_percentage' => 0,
                            'tax_amount' => 0.0,
                            'total' => (float) ($course->price ?? 0),
                            'promo_code_details' => null,
                            'currency_code' => 'EGP',
                            'display_currency' => 'EGP',
                            'display_symbol' => 'EGP',
                            'display_price' => (float) ($course->price ?? 0),
                            'formatted_price' => ($course->price ?? 0) . ' EGP',
                            'price_egp' => (float) ($course->price ?? 0),
                            'discount_price_egp' => null,
                            'exchange_rate' => 1.0,
                            'is_country_specific' => false,
                        ];
                    }

                    $originalPrice = (float) ($pricing['original_price'] ?? 0);
                    $courseDiscount = (float) ($pricing['course_discount'] ?? 0);
                    $discountPercentage = $originalPrice > 0
                        ? round(($courseDiscount / $originalPrice) * 100, 2)
                        : 0.0;

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
                        'has_access' => $hasAccess,
                        'is_available' => $isAvailable,
                        'availability_status' => $availabilityStatus,
                        'is_wishlisted' => true,
                    ]);
                })
                ->filter()
                ->values();

            $paginator->setCollection($formattedItems);

            return ApiResponseService::successResponse('Wishlist fetched successfully', $paginator);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Wishlist Error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse('Failed to fetch wishlist: ' . $e->getMessage());
        }
    }

    /**
     * Add or remove a course from wishlist based on desired status
     * status: 1 = add to wishlist, status: 0 = remove from wishlist
     */
    public function addUpdateWishlist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|integer',
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            $courseId = (int) $request->course_id;
            $status = (int) $request->status;

            if ($status === 1) {
                // Verify course exists, is public, active, and approved before adding
                $course = Course::find($courseId);
                if (!$course
                    || !(bool) $course->is_active
                    || $course->status !== 'publish'
                    || $course->approval_status !== 'approved'
                ) {
                    return ApiResponseService::validationError('Course is not available to be added to wishlist.');
                }

                // Idempotent add: create if not exists
                Wishlist::firstOrCreate([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                ]);

                $message = 'Course added to wishlist successfully';
            } else {
                // Idempotent remove: delete if exists
                Wishlist::where('user_id', $user->id)->where('course_id', $courseId)->delete();
                $message = 'Course removed from wishlist successfully';
            }

            $wishlistCount = Wishlist::where('user_id', $user->id)->count();

            return ApiResponseService::successResponse($message, [
                'course_id' => $courseId,
                'status' => $status,
                'is_wishlisted' => $status === 1,
                'wishlist_count' => $wishlistCount,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update wishlist');
            return ApiResponseService::errorResponse('Failed to update wishlist');
        }
    }

    public function addToWishlist(Request $request)
    {
        $request->merge(['status' => 1]);
        return $this->addUpdateWishlist($request);
    }

    public function removeFromWishlist(Request $request)
    {
        $request->merge(['status' => 0]);
        return $this->addUpdateWishlist($request);
    }
}

