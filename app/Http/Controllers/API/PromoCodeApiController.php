<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Course\Course;
use App\Models\PromoCode;
use App\Models\PromoCodeCourse;
use App\Models\Tax;
use App\Services\ApiResponseService;
use App\Services\PricingCalculationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PromoCodeApiController extends Controller
{
    public function __construct(
        private PricingCalculationService $pricingService,
    ) {}

    public function getPromoCodesByCourse(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_ids' => 'required|array|min:1',
                'course_ids.*' => 'exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $courseIds = $request->course_ids;

            // 1. Get instructor promo codes linked to the course
            $instructorPromoCodes = PromoCode::whereHas('courses', static function ($query) use ($courseIds): void {
                $query->whereIn('course_id', $courseIds);
            })
                ->where('status', 1)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->where(static function ($query): void {
                    // Include promo codes with no usage limit (null) or with available uses (> 0)
                    $query->whereNull('no_of_users')->orWhere('no_of_users', '>', 0);
                })
                ->whereHas('user.roles', static function ($q): void {
                    $q->where('name', 'instructor');
                });

            // 2. Get admin promo codes (not bound to courses)
            $adminPromoCodes = PromoCode::where('status', 1)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->where(static function ($query): void {
                    // Include promo codes with no usage limit (null) or with available uses (> 0)
                    $query->whereNull('no_of_users')->orWhere('no_of_users', '>', 0);
                })
                ->whereHas('user.roles', static function ($q): void {
                    $q->where('name', 'admin');
                });

            // 3. Combine both using union
            $promoCodes = $instructorPromoCodes->union($adminPromoCodes)->get();

            return ApiResponseService::successResponse('Promo codes fetched successfully', $promoCodes);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to fetch promo codes');

            return ApiResponseService::errorResponse('Something went wrong while fetching promo codes.');
        }
    }

    /**
     * Get instructor promo codes for a single course (Instructor Only)
     */
    public function getPromoCodesForCourse(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $courseId = $request->course_id;

            // Get course details
            $course = Course::with('user')->find($courseId);

            // Get ONLY instructor promo codes linked to this specific course
            $instructorPromoCodes = PromoCode::whereHas('courses', static function ($query) use ($courseId): void {
                $query->where('course_id', $courseId);
            })
                ->where('status', 1)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->where('user_id', '!=', 1) // Exclude admin promo codes (user_id != 1)
                ->where(static function ($query): void {
                    // Include promo codes with no usage limit (null) or with available uses (> 0)
                    $query->whereNull('no_of_users')->orWhere('no_of_users', '>', 0);
                })
                ->with([
                    'user:id,name,email',
                    'courses' => static function ($q) use ($courseId): void {
                        $q->where('course_id', $courseId);
                    },
                ])
                ->get()
                ->map(static fn($promo) => [
                    'id' => $promo->id,
                    'promo_code' => $promo->promo_code,
                    'message' => $promo->message,
                    'discount' => $promo->discount,
                    'discount_type' => $promo->discount_type,
                    'start_date' => $promo->start_date,
                    'end_date' => $promo->end_date,
                    'instructor_name' => $promo->user->name,
                    'instructor_email' => $promo->user->email,
                    'no_of_users' => $promo->no_of_users,
                ]);

            $responseData = [
                'course' => [
                    'id' => $course?->id,
                    'title' => $course?->title,
                    'price' => $course?->price,
                    'discount_price' => $course?->discount_price,
                    'instructor_name' => $course->user->name ?? null,
                ],
                'promo_codes' => $instructorPromoCodes,
                'total_codes' => $instructorPromoCodes->count(),
            ];

            return ApiResponseService::successResponse(
                'Instructor promo codes for course fetched successfully',
                $responseData,
            );
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to fetch promo codes for course');

            return ApiResponseService::errorResponse('Something went wrong while fetching promo codes.');
        }
    }

    public function getValidPromoCodes()
    {
        try {
            $userId = Auth::id();

            // Get course IDs from cart
            $cartCourses = Cart::where('user_id', $userId)->pluck('course_id')->toArray();

            if (empty($cartCourses)) {
                return ApiResponseService::successResponse('No items in cart', []);
            }

            $today = Carbon::today();

            // Get cart with course price/discount_price
            $cartItems = Cart::where('user_id', $userId)->with('course:id,id,price,discount_price')->get();

            // Build course price map: use course.discount_price or course.price
            $cartPriceMap = [];
            foreach ($cartItems as $item) {
                $course = $item->course;
                $cartPriceMap[$item->course_id] = $course->discount_price ?? $course->price ?? 0;
            }

            // Only return admin promo codes (user_id = 1)
            $adminPromoCodes = PromoCode::where('status', 1)
                ->where('user_id', 1)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->where(static function ($query): void {
                    // Include promo codes with no usage limit (null) or with available uses (> 0)
                    $query->whereNull('no_of_users')->orWhere('no_of_users', '>', 0);
                })
                ->get();

            // Use only admin promo codes (exclude instructor promo codes)
            $promoCodes = $adminPromoCodes;

            // Calculate discount for each promo
            $promoCodesWithDiscount = $promoCodes->map(static function ($promo) use ($cartCourses, $cartPriceMap) {
                if ($promo->user_id == 1) {
                    $applicableCourses = $cartCourses;
                } else {
                    $applicableCourses = PromoCodeCourse::where('promo_code_id', $promo->id)
                        ->whereIn('course_id', $cartCourses)
                        ->pluck('course_id')
                        ->toArray();
                }

                // Total of applicable courses
                $total = collect($applicableCourses)->sum(static fn($courseId) => $cartPriceMap[$courseId] ?? 0);

                // Apply discount logic
                if ($promo->discount_type === 'percentage') {
                    // Clamp discount percentage to 100% max
                    $discountPercent = min($promo->discount, 100);
                    $discount = ($total * $discountPercent) / 100;
                } else {
                    $discount = min($promo->discount, $total);
                }

                $promo->discounted_amount = round($discount, 2);

                return $promo;
            });

            return ApiResponseService::successResponse('Valid promo codes fetched', $promoCodesWithDiscount->values());
        } catch (\Exception $e) {
            return ApiResponseService::logErrorResponse($e, 'Failed to get valid promo codes');
        }
    }

    /**
     * Preview promo code discount without applying to cart
     * Just check how much discount will be applied
     */
    public function applyPromoCode(Request $request)
    {
        try {
            // Clean extra quotes if present (handle null values safely)
            $requestData = $request->all();
            if (isset($requestData['promo_code_id'])) {
                $requestData['promo_code_id'] = trim($requestData['promo_code_id'], '"\'');
            }
            if (isset($requestData['promo_code'])) {
                $requestData['promo_code'] = trim($requestData['promo_code'], '"\'');
            }
            if (isset($requestData['course_id'])) {
                $requestData['course_id'] = trim($requestData['course_id'], '"\'');
            }
            $request->merge($requestData);

            $validator = Validator::make($request->all(), [
                'promo_code_id' => 'required_without:promo_code|nullable|exists:promo_codes,id',
                'promo_code' => 'required_without:promo_code_id|nullable|string|max:255',
                'course_id' => 'required|exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $courseId = $request->course_id;

            // Get promo code - either by ID or by code
            if ($request->filled('promo_code_id')) {
                $promo = PromoCode::with(['user.roles', 'courses'])->find($request->promo_code_id);
            } else {
                $promo = PromoCode::with(['user.roles', 'courses'])->where('promo_code', $request->promo_code)->first();
            }

            if (!$promo) {
                return ApiResponseService::validationError('Promo code not found');
            }

            // Validate promo code using service
            if (!$this->pricingService->isPromoCodeValid($promo)) {
                if ($promo->status != 1) {
                    return ApiResponseService::validationError('Promo code is not active');
                }
                if ($promo->start_date > today() || $promo->end_date < today()) {
                    return ApiResponseService::validationError('Promo code is expired or not yet active');
                }
                if ($promo->no_of_users !== null && $promo->no_of_users <= 0) {
                    return ApiResponseService::validationError(
                        'This promo code has reached its usage limit and is no longer available',
                    );
                }
            }

            // Reject admin promo codes (user_id = 1 or Admin role)
            $isAdmin = $promo->user_id == 1 || $promo->user->roles->contains('name', 'Admin');

            if ($isAdmin) {
                return ApiResponseService::validationError(
                    'Admin promo codes are not allowed. Only instructor promo codes can be previewed.',
                );
            }

            // Verify course exists
            $course = Course::find($courseId);
            if (!$course) {
                return ApiResponseService::validationError('Course not found');
            }

            // Check if instructor promo code is linked to this course
            $isInstructor = $promo->user->roles->contains('name', 'Instructor');
            if ($isInstructor) {
                $isLinkedToCourse = PromoCodeCourse::where('promo_code_id', $promo->id)
                    ->where('course_id', $courseId)
                    ->exists();

                if (!$isLinkedToCourse) {
                    return ApiResponseService::validationError(
                        'This instructor promo code is not applicable to the selected course',
                    );
                }
            }

            // Load course with relationships
            $course->load('taxes', 'user');

            // Get country code and tax percentage using service
            $countryCode = $this->pricingService->getCountryCodeFromRequest($request);
            $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

            // Check if course is wishlisted (if user is authenticated)
            $isWishlisted = false;
            if (Auth::check()) {
                $isWishlisted = \App\Models\Wishlist::where('user_id', Auth::id())
                    ->where('course_id', $courseId)
                    ->exists();
            }

            // Calculate pricing using service
            $pricing = $this->pricingService->calculateCoursePricing($course, $promo, $totalTaxPercentage);

            // Check if course has valid price
            if ($pricing['subtotal'] <= 0) {
                return ApiResponseService::validationError('Course price not available');
            }

            // Build promo discounts array
            $promoDiscounts = [];
            if ($pricing['promo_discount'] > 0) {
                $promoDiscounts[] = [
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'promo_code' => $promo->promo_code,
                    'discount_amount' => $pricing['promo_discount'],
                ];
            }

            // Format course with pricing
            $formattedCourse = $this->pricingService->formatCourseWithPricing($course, $pricing, $isWishlisted);

            return ApiResponseService::successResponse('Promo code preview', [
                'courses' => [$formattedCourse],
                'detected_country_code' => $countryCode,
                'promo_discounts' => $promoDiscounts,
                //
                'original_price' => $pricing['original_price'],
                'course_discount' => $pricing['course_discount'],
                'subtotal' => $pricing['subtotal'],
                'promo_discount' => $pricing['promo_discount'],
                'taxable_amount' => $pricing['taxable_amount'],
                'tax_percentage' => $pricing['tax_percentage'],
                'tax_amount' => $pricing['tax_amount'],
                'total' => $pricing['total'],
            ]);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to preview promo code discount');

            return ApiResponseService::errorResponse('Failed to preview promo code discount: ' . $e->getMessage());
        }
    }

    // 3. APPLIED PROMO CODES
    public function getAppliedPromoCodes()
    {
        try {
            // No applied promo codes in cart (promo codes are now applied at order level)
            return ApiResponseService::successResponse('Applied promo codes', [
                'total_discounted_amount' => 0,
                'applied_promo_codes' => [],
            ]);
        } catch (\Exception $e) {
            return ApiResponseService::logErrorResponse($e, 'Failed to get applied promo codes');
        }
    }
}
