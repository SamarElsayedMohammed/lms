<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\Tax;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PricingCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CartApiController extends Controller
{
    public function __construct(
        private PricingCalculationService $pricingService,
    ) {}

    /**
     * Get the current user's cart.
     * If course_id is provided, return cart with only that course for buy now.
     * Otherwise, return all courses from user's cart.
     */
    public function getUserCart(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'course_id' => 'nullable|exists:courses,id',
                'promo_code_id' => [
                    'nullable',
                    Rule::prohibitedIf(static fn() => !$request->has('course_id')),
                    'exists:promo_codes,id',
                ],
            ],
            [
                'promo_code_id.prohibited' => 'Promo code cannot be used without selecting a course.',
            ],
        );

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();

            // Buy Now
            if ($request->has('course_id')) {
                $cartData = $this->formatUserCourseForBuyNow($user, $request);

                return ApiResponseService::successResponse('Buy now cart fetched successfully', $cartData);
            }

            // Cart
            $cartData = $this->formatUserCart($user, $request);

            return ApiResponseService::successResponse('Cart fetched successfully', $cartData);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get cart');

            return ApiResponseService::errorResponse('Failed to get cart');
        }
    }

    /**
     * Add a course to the current user's cart.
     */
    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'promo_code_id' => 'nullable|exists:promo_codes,id',
            'promo_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;
            $promoCodeId = null;
            $promoCode = null;

            // Check if course already in cart
            $existingCart = $user->carts()->where('course_id', $courseId)->first();

            // If promo code is provided (either by ID or code), validate it
            if ($request->filled('promo_code_id') || $request->filled('promo_code')) {
                // Get promo code - either by ID or by code
                if ($request->filled('promo_code_id')) {
                    $promoCode = PromoCode::with(['user.roles', 'courses'])->find($request->promo_code_id);
                } else {
                    $promoCode = PromoCode::with(['user.roles', 'courses'])->where(
                        'promo_code',
                        $request->promo_code,
                    )->first();
                }

                if (!$promoCode) {
                    return ApiResponseService::validationError('Promo code not found');
                }

                $promoCodeId = $promoCode->id;

                // Validate promo code using service
                if (!$this->pricingService->isPromoCodeValid($promoCode)) {
                    if ($promoCode->status != 1) {
                        return ApiResponseService::validationError('Promo code is not active');
                    }
                    if ($promoCode->start_date > today() || $promoCode->end_date < today()) {
                        return ApiResponseService::validationError('Promo code is expired or not yet active');
                    }
                    if ($promoCode->no_of_users !== null && $promoCode->no_of_users <= 0) {
                        return ApiResponseService::validationError(
                            'This promo code has reached its usage limit and is no longer available',
                        );
                    }
                }

                // Check if promo code is applicable to this course
                $isAdmin = $promoCode->user->roles->contains('name', 'Admin');
                $isInstructor = $promoCode->user->roles->contains('name', 'Instructor');
                $isApplicable = false;

                if ($isAdmin) {
                    // Admin promo codes apply to all courses
                    $isApplicable = true;
                } elseif ($isInstructor) {
                    // Instructor promo codes apply only to their courses
                    $instructorCourseIds = $promoCode->courses->pluck('id')->toArray();
                    $isApplicable = in_array($courseId, $instructorCourseIds);
                }

                if (!$isApplicable) {
                    return ApiResponseService::validationError('Promo code is not applicable to this course');
                }
            }

            if ($existingCart) {
                // Course already in cart - update promo code if different
                if ($existingCart->promo_code_id != $promoCodeId) {
                    $existingCart->update(['promo_code_id' => $promoCodeId]);

                    return ApiResponseService::successResponse('Cart updated with promo code', $this->formatUserCart(
                        $user,
                        $request,
                    ));
                } else {
                    return ApiResponseService::successResponse('Course already in cart with the same promo code', $this->formatUserCart(
                        $user,
                        $request,
                    ));
                }
            }

            // Create new cart row
            $user->carts()->create([
                'course_id' => $courseId,
                'promo_code_id' => $promoCodeId,
            ]);

            return ApiResponseService::successResponse('Course added to cart', $this->formatUserCart($user, $request));
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to add course to cart');

            return ApiResponseService::errorResponse('Failed to add course to cart' . $e->getMessage());
        }
    }

    /**
     * Apply promo code to cart (smart detection)
     * - Automatically detects if admin or instructor promo code
     * - Admin promo codes (user_id = 1) replace ALL existing promo codes and apply to ALL courses
     * - Instructor promo codes remove ALL admin promo codes first, then apply to their mapped courses only
     */
    public function applyPromoCodeToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'promo_code_id' => 'nullable|exists:promo_codes,id',
            'promo_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        // At least one field must be provided
        if (!$request->filled('promo_code_id') && !$request->filled('promo_code')) {
            return ApiResponseService::validationError('Either promo_code_id or promo_code is required');
        }

        try {
            $user = Auth::user();

            // Get promo code with relationships - either by ID or by code
            if ($request->filled('promo_code_id')) {
                $promoCode = PromoCode::with(['user.roles', 'courses'])->find($request->promo_code_id);
            } else {
                $promoCode = PromoCode::with(['user.roles', 'courses'])->where(
                    'promo_code',
                    $request->promo_code,
                )->first();
            }

            if (!$promoCode) {
                return ApiResponseService::validationError('Promo code not found');
            }

            $promoCodeId = $promoCode->id;

            // Validate promo code using service
            if (!$this->pricingService->isPromoCodeValid($promoCode)) {
                if ($promoCode->status != 1) {
                    return ApiResponseService::validationError('Promo code is not active');
                }
                if ($promoCode->start_date > now() || $promoCode->end_date < now()) {
                    return ApiResponseService::validationError('Promo code is expired or not yet active');
                }
                if ($promoCode->no_of_users !== null && $promoCode->no_of_users <= 0) {
                    return ApiResponseService::validationError(
                        'This promo code has reached its usage limit and is no longer available',
                    );
                }
            }

            // Get all cart items
            $cartItems = $user->carts()->with('course')->get();

            if ($cartItems->isEmpty()) {
                return ApiResponseService::validationError('Cart is empty');
            }

            // Determine if this is an admin promo code (user_id = 1)
            $isAdminPromo = $promoCode->user_id == 1;

            if ($isAdminPromo) {
                // ========== ADMIN PROMO CODE ==========
                // Replace ALL existing promo codes and apply to ALL courses
                $allCourseIds = $cartItems->pluck('course_id')->toArray();

                // Update all cart items with admin promo code
                $user->carts()->update(['promo_code_id' => $promoCodeId]);

                $message = 'Admin promo code applied to all ' . count($allCourseIds) . ' course(s) in cart';
            } else {
                // ========== INSTRUCTOR PROMO CODE ==========
                // First, remove ALL admin promo codes from entire cart
                $cartItemsWithAdminPromo = $user
                    ->carts()
                    ->whereHas('promoCode', static function ($query): void {
                        $query->where('user_id', 1);
                    })
                    ->get();

                if ($cartItemsWithAdminPromo->isNotEmpty()) {
                    // Remove admin promo codes from all courses
                    $user->carts()->update(['promo_code_id' => null]);
                }

                // Get instructor's mapped courses
                $promoCourseIds = $promoCode->courses->pluck('id')->toArray();

                // Find which cart courses are applicable
                $applicableCourseIds = [];
                foreach ($cartItems as $cartItem) {
                    if (!in_array($cartItem->course_id, $promoCourseIds)) {
                        continue;
                    }

                    $applicableCourseIds[] = $cartItem->course_id;
                }

                if (empty($applicableCourseIds)) {
                    return ApiResponseService::validationError(
                        'This promo code is not applicable to any course in your cart',
                    );
                }

                // Apply instructor promo code only to applicable courses
                $user->carts()->whereIn('course_id', $applicableCourseIds)->update(['promo_code_id' => $promoCodeId]);

                $message = 'Promo code applied to ' . count($applicableCourseIds) . ' applicable course(s) in cart';
            }

            // Refresh user to clear relationship cache and reload cart with updated promo codes
            $user->refresh();

            return ApiResponseService::successResponse($message, $this->formatUserCart($user, $request));
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to apply promo code');

            return ApiResponseService::errorResponse('Failed to apply promo code: ' . $e->getMessage());
        }
    }

    /**
     * Remove promo code from entire cart
     */
    public function removePromoCode(Request $request)
    {
        try {
            $user = Auth::user();

            // Remove promo codes from all cart items
            $user->carts()->update(['promo_code_id' => null]);

            return ApiResponseService::successResponse('Promo codes removed from cart', $this->formatUserCart(
                $user,
                $request,
            ));
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to remove promo codes');

            return ApiResponseService::errorResponse('Failed to remove promo codes: ' . $e->getMessage());
        }
    }

    /**
     * Remove a course from the current user's cart.
     */
    public function removeFromCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;

            $cartItem = $user->carts()->where('course_id', $courseId)->first();

            if (!$cartItem) {
                return ApiResponseService::errorResponse('Course not found in cart');
            }

            $cartItem->delete();

            return ApiResponseService::successResponse('Course removed from cart', $this->formatUserCart(
                $user,
                $request,
            ));
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to remove course from cart');

            return ApiResponseService::errorResponse('Failed to remove course from cart');
        }
    }

    /**
     * Clear all items from the current user's cart.
     */
    public function clearCart(Request $request)
    {
        try {
            $user = Auth::user();

            // Delete all cart items for the user
            $user->carts()->delete();

            return ApiResponseService::successResponse('Cart cleared successfully', $this->formatUserCart(
                $user,
                $request,
            ));
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to clear cart');

            return ApiResponseService::errorResponse('Failed to clear cart: ' . $e->getMessage());
        }
    }

    /**
     * Format the user's cart with total price and course data.
     */
    public function formatUserCart(User $user, Request $request)
    {
        // Eager load course relationships and promo codes (force reload)
        $cart = $user
            ->carts()
            ->with([
                'course.taxes',
                'course.user',
                'promoCode.user.roles',
            ])
            ->with(['course' => static function ($query): void {
                $query
                    ->withAvg('ratings', 'rating')
                    ->withCount('ratings')
                    ->where('is_active', 1) // Only active courses
                    ->where('status', 'publish') // Only published courses
                    ->where('approval_status', 'approved') // Only approved courses
                    ->whereHas('user', static function ($userQuery): void {
                        $userQuery
                            ->where('is_active', 1) // User should be active
                            ->where(static function ($q): void {
                                // Either user is admin OR has approved instructor details
                                $q->whereHas('roles', static fn($roleQuery) => $roleQuery->where(
                                    'name',
                                    'Admin',
                                ))->orWhereHas('instructor_details', static fn($instructorQuery) => $instructorQuery->where(
                                    'status',
                                    'approved',
                                ));
                            });
                    });
            }])
            ->get();

        // Get country code and tax percentage using service
        $countryCode = $this->pricingService->getCountryCodeFromRequest($request);
        $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

        // Load user's billing details
        $billingDetails = $user->load('billingDetails')->billingDetails;

        if ($cart->isEmpty()) {
            $emptyResponse = $this->pricingService->buildEmptyPricingResponse($totalTaxPercentage, $countryCode);
            $emptyResponse['billing_details'] = $billingDetails?->formatForApi();

            return $emptyResponse;
        }

        $courses = $cart->pluck('course')->filter();

        // Get all course IDs in cart
        $courseIds = $courses->pluck('id')->toArray();

        // Check which courses are wishlisted by the user
        $wishlistedCourseIds = \App\Models\Wishlist::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->pluck('course_id')
            ->toArray();

        // Calculate pricing for each cart item
        $promoDiscounts = [];
        $coursePricingData = collect();

        $formattedCourses = $cart->map(function ($cartItem) use (
            $wishlistedCourseIds,
            &$promoDiscounts,
            &$coursePricingData,
            $totalTaxPercentage,
        ) {
            $course = $cartItem->course;
            if (!$course) {
                return null;
            }

            // Get promo code if exists
            $promoCode = null;
            if ($cartItem->promo_code_id) {
                if (!$cartItem->relationLoaded('promoCode') || !$cartItem->promoCode) {
                    $cartItem->load('promoCode.user.roles');
                }
                $promoCode = $cartItem->promoCode;
            }

            // Calculate pricing using service
            $pricing = $this->pricingService->calculateCoursePricing($course, $promoCode, $totalTaxPercentage);

            // Store for aggregate calculation
            $coursePricingData->push(['pricing' => $pricing, 'course' => $course]);

            // Add to promo discounts if applicable
            if ($pricing['promo_discount'] > 0 && $promoCode) {
                $promoDiscounts[] = [
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'promo_code' => $promoCode->promo_code,
                    'discount_amount' => $pricing['promo_discount'],
                ];
            }

            // Format course with pricing
            $isWishlisted = in_array($course->id, $wishlistedCourseIds);

            return $this->pricingService->formatCourseWithPricing($course, $pricing, $isWishlisted, [
                'ratings' => (int) ($course->ratings_count ?? 0),
                'average_rating' => round((float) ($course->ratings_avg_rating ?? 0), 2),
            ]);
        })->filter()->values();

        // Calculate aggregate pricing
        $aggregatePricing = $this->pricingService->calculateAggregatePricing($coursePricingData, $totalTaxPercentage);

        return [
            'courses' => $formattedCourses,
            'detected_country_code' => $countryCode,
            'promo_discounts' => $promoDiscounts,
            'billing_details' => $billingDetails?->formatForApi(),
            //
            ...$aggregatePricing,
        ];
    }

    public function formatUserCourseForBuyNow(User $user, Request $request): array
    {
        $courseId = $request->input('course_id');
        $promoCodeId = $request->input('promo_code_id', null);

        // Load user's billing details
        $billingDetails = $user->load('billingDetails')->billingDetails;

        // Fetch course with same constraints as formatUserCart
        $course = \App\Models\Course\Course::query()
            ->with([
                'taxes',
                'user',
            ])
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->where('id', $courseId)
            ->where('is_active', 1) // Only active courses
            ->where('status', 'publish') // Only published courses
            ->where('approval_status', 'approved') // Only approved courses
            ->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1) // User should be active
                    ->where(static function ($q): void {
                        // Either user is admin OR has approved instructor details
                        $q->whereHas('roles', static fn($roleQuery) => $roleQuery->where(
                            'name',
                            'Admin',
                        ))->orWhereHas('instructor_details', static fn($instructorQuery) => $instructorQuery->where(
                            'status',
                            'approved',
                        ));
                    });
            })
            ->first();

        // Get country code and tax percentage using service
        $countryCode = $this->pricingService->getCountryCodeFromRequest($request);
        $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

        if (!$course) {
            $emptyResponse = $this->pricingService->buildEmptyPricingResponse($totalTaxPercentage, $countryCode);
            $emptyResponse['billing_details'] = $billingDetails?->formatForApi();

            return $emptyResponse;
        }

        // Check if course is wishlisted
        $isWishlisted = \App\Models\Wishlist::where('user_id', $user->id)->where('course_id', $courseId)->exists();

        // Get promo code if provided
        $promoCode = null;
        $promoDiscounts = [];

        if ($promoCodeId) {
            $promoCode = PromoCode::with(['user.roles', 'courses'])->find($promoCodeId);

            if ($promoCode && $this->pricingService->isPromoCodeValid($promoCode)) {
                // Check if promo code is applicable to this course
                $isAdmin = $promoCode->user->roles->contains('name', 'Admin');
                $isInstructor = $promoCode->user->roles->contains('name', 'Instructor');
                $isApplicable = false;

                if ($isAdmin) {
                    $isApplicable = true;
                } elseif ($isInstructor) {
                    $instructorCourseIds = $promoCode->courses->pluck('id')->toArray();
                    $isApplicable = in_array($courseId, $instructorCourseIds);
                }

                if (!$isApplicable) {
                    $promoCode = null; // Not applicable, don't use it
                }
            } else {
                $promoCode = null; // Invalid promo code
            }
        }

        // Calculate pricing using service
        $pricing = $this->pricingService->calculateCoursePricing($course, $promoCode, $totalTaxPercentage);

        // Build promo discounts if applicable
        if ($pricing['promo_discount'] > 0 && $promoCode) {
            $promoDiscounts[] = [
                'course_id' => $course->id,
                'course_title' => $course->title,
                'promo_code' => $promoCode->promo_code,
                'discount_amount' => $pricing['promo_discount'],
            ];
        }

        // Format course with pricing
        $formattedCourse = $this->pricingService->formatCourseWithPricing($course, $pricing, $isWishlisted, [
            'ratings' => (int) ($course->ratings_count ?? 0),
            'average_rating' => round((float) ($course->ratings_avg_rating ?? 0), 2),
        ]);

        return [
            'courses' => [$formattedCourse],
            'detected_country_code' => $countryCode,
            'promo_discounts' => $promoDiscounts,
            'billing_details' => $billingDetails?->formatForApi(),
            //
            'original_price' => $pricing['original_price'],
            'course_discount' => $pricing['course_discount'],
            'subtotal' => $pricing['subtotal'],
            'promo_discount' => $pricing['promo_discount'],
            'taxable_amount' => $pricing['taxable_amount'],
            'tax_percentage' => $pricing['tax_percentage'],
            'tax_amount' => $pricing['tax_amount'],
            'total' => $pricing['total'],
        ];
    }
}
