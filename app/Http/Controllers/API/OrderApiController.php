<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SendOrderNotifications;
use App\Models\Cart;
use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\PromoCode;
use App\Models\RefundRequest;
use App\Models\Tax;
use App\Models\Transaction;
use App\Services\ApiResponseService;
use App\Services\CommissionService;
use App\Services\HelperService;
use App\Services\OrderTrackingService;
use App\Services\Payment\PaymentFactory;
use App\Services\PricingCalculationService;
use App\Services\WalletService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mpdf\Mpdf;
use Throwable;

class OrderApiController extends Controller
{
    public function placeOrder(Request $request)
    {
        // Normalize payment_method to always be a string
        if ($request->has('payment_method')) {
            $paymentMethod = $request->input('payment_method');
            if (is_array($paymentMethod)) {
                $paymentMethod = $paymentMethod[0] ?? null;
            }
            $request->merge(['payment_method' => $paymentMethod]);
        }

        if ($request->boolean('buy_now', false) && $request->course_id) {
            $course = Course::find($request->course_id);
            $isFree = $course && $course->course_type === 'free';
        } else {
            // Check if all cart items are free
            $user = Auth::user();
            $cartItems = Cart::with('course')->where('user_id', $user?->id)->get();
            $isFree =
                $cartItems->isNotEmpty()
                && $cartItems->every(static fn($cart) => $cart->course && $cart->course->course_type === 'free');
        }

        $validator = Validator::make($request->all(), [
            'payment_method' => $isFree
                ? 'nullable|in:stripe,razorpay,flutterwave,wallet,free'
                : 'required|in:stripe,razorpay,flutterwave,wallet',
            'buy_now' => 'nullable|boolean', // For direct purchase
            'course_id' => 'nullable|required_if:buy_now,true|exists:courses,id', // Required when buy_now is true
            'promo_code_id' => 'nullable|exists:promo_codes,id', // Only one promo code allowed for both buy_now and cart orders
            'promo_code' => 'nullable|string|max:255', // Alternative to promo_code_id
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $customMessages = [
                'course_id.required_if' => 'Course ID is required when using buy now feature.',
            ];

            foreach ($customMessages as $message) {
                if (!($errors->has('course_id') && str_contains($errors->first('course_id'), 'required'))) {
                    continue;
                }

                return ApiResponseService::validationError($message);
            }

            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $isBuyNow = $request->boolean('buy_now', false);

            // check if user has billing details, as it is required (for paid courses only).
            if (!$isFree && $user->billingDetails === null) {
                return ApiResponseService::errorResponse(
                    'Billing details are required before placing an order.',
                    code: 422,
                );
            }

            // Resolve promo code - either by ID or by code
            if ($request->filled('promo_code_id') || $request->filled('promo_code')) {
                if ($request->filled('promo_code_id')) {
                    $promoCodeId = $request->promo_code_id;
                } else {
                    $promoCodeModel = PromoCode::where('promo_code', $request->promo_code)->first();
                    $promoCodeId = $promoCodeModel?->id;
                }

                // Temporarily store the resolved ID in the request for handlers to use
                $request->merge(['resolved_promo_code_id' => $promoCodeId]);
            }

            // Handle direct purchase (Buy Now)
            if ($isBuyNow && $request->course_id) {
                return $this->handleBuyNowOrder($request, $user);
            }

            // Handle cart-based order
            return $this->handleCartOrder($request, $user);
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function getOrder()
    {
        try {
            $user = Auth::user();

            // Get refund settings
            $refundEnabled = HelperService::systemSettings('refund_enabled') == 1;
            $refundPeriodDays = (int) HelperService::systemSettings('refund_period_days') ?? 7;

            $orders = Order::with([
                'orderCourses.course.user',
                'promoCode',
            ])
                ->where('user_id', $user?->id)
                ->latest()
                ->get()
                ->map(static function ($order) use ($user, $refundEnabled, $refundPeriodDays) {
                    // Get order date for refund eligibility
                    $orderDate = $order->created_at;

                    // Get transaction ID from order (Transaction belongs to Order)
                    $transaction = Transaction::where('order_id', $order->id)->first();
                    $transactionId = $transaction ? $transaction->id : null;

                    // Get approved refunds for this user with their approval dates
                    $approvedRefunds = RefundRequest::where('user_id', $user?->id)
                        ->where('status', 'approved')
                        ->get()
                        ->groupBy('course_id')
                        ->map(
                            // Get the latest refund approval date for each course

                            static fn($refunds) => $refunds->max('processed_at'),
                        );

                    // Filter out courses with approved refunds that were approved before this order
                    $validOrderCourses = $order->orderCourses->filter(static function ($oc) use (
                        $approvedRefunds,
                        $orderDate,
                    ) {
                        if (!$oc->course) {
                            return false;
                        }

                        $courseId = $oc->course->id;
                        $refundApprovalDate = $approvedRefunds->get($courseId);

                        // If no refund or refund was approved after this order, include the course
                        if (!$refundApprovalDate || $orderDate->gt($refundApprovalDate)) {
                            return true;
                        }

                        // Refund was approved before this order, exclude it
                        return false;
                    });

                    // Recalculate pricing based on remaining courses (same as invoice logic)
                    $subtotal = $validOrderCourses->sum('price');
                    $taxAmount = $validOrderCourses->sum('tax_price');
                    $totalDiscount = $order->discount_amount ?? 0;

                    // Calculate final total as sum of all courses' final_price
                    // final_price = price + tax_price for each course
                    $finalTotal = $validOrderCourses->sum(static fn($oc) => ($oc->price ?? 0) + ($oc->tax_price ?? 0));

                    // Calculate total refund amount for this order (approved refunds only)
                    $courseIds = $order->orderCourses->pluck('course_id')->filter()->toArray();
                    $totalRefundAmount = 0;
                    if (!empty($courseIds)) {
                        $totalRefundAmount = RefundRequest::where('user_id', $user->id)
                            ->whereIn('course_id', $courseIds)
                            ->where('status', 'approved')
                            ->sum('refund_amount');
                    }

                    // Check if order is eligible for refund (only completed orders)
                    $isOrderRefundEligible = false;
                    $refundDaysRemaining = 0;
                    if ($refundEnabled && $order->status === 'completed') {
                        // Calculate days since purchase (ensure positive value)
                        $daysSincePurchase = abs(now()->diffInDays($orderDate, false));

                        if ($daysSincePurchase <= $refundPeriodDays) {
                            $isOrderRefundEligible = true;
                            // Calculate remaining days (ensure non-negative)
                            $refundDaysRemaining = max(0, $refundPeriodDays - $daysSincePurchase);
                        }
                    }

                    return [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'payment_method' => $order->payment_method,
                        'total_price' => round($subtotal, 2),
                        'tax_price' => round($taxAmount, 2),
                        'total_discount' => round($totalDiscount, 2),
                        'final_total' => round($finalTotal, 2),
                        'refund_amount' => round($totalRefundAmount, 2),
                        'transaction_date' => $order->created_at,
                        'transaction_date_formatted' => $order->created_at->format('Y-m-d H:i:s'),
                        'transaction_date_human' => $order->created_at->diffForHumans(),
                        'courses' => $order->orderCourses->map(static function ($oc) use (
                            $user,
                            $order,
                            $refundEnabled,
                            $refundPeriodDays,
                            $transactionId,
                        ) {
                            if (!$oc->course) {
                                return null;
                            }

                            // Check if course is eligible for refund
                            $isRefundEligible = false;
                            $refundDaysRemaining = 0;
                            $hasRefundRequest = false;
                            $refundRequestStatus = null;
                            $refundRequestId = null;
                            $refundAdminNotes = null;

                            if (
                                $refundEnabled
                                && $order->status === 'completed'
                                && $oc->course->course_type !== 'free'
                            ) {
                                // Calculate days since purchase (ensure positive value)
                                $daysSincePurchase = abs(now()->diffInDays($order->created_at, false));

                                if ($daysSincePurchase <= $refundPeriodDays) {
                                    $isRefundEligible = true;
                                    // Calculate remaining days (ensure non-negative)
                                    $refundDaysRemaining = max(0, $refundPeriodDays - $daysSincePurchase);
                                }

                                // Check if there's an existing refund request for this course
                                $refundRequest = RefundRequest::where('user_id', $user->id)
                                    ->where('course_id', $oc->course->id)
                                    ->where('transaction_id', $transactionId)
                                    ->latest()
                                    ->first();

                                if ($refundRequest) {
                                    $hasRefundRequest = true;
                                    $refundRequestStatus = $refundRequest->status;
                                    $refundRequestId = $refundRequest->id;

                                    // Add admin_notes if refund is rejected
                                    if ($refundRequest->status === 'rejected' && $refundRequest->admin_notes) {
                                        $refundAdminNotes = $refundRequest->admin_notes;
                                    }
                                }
                            }

                            // Get creator name (instructor or admin)
                            $creatorName = null;
                            if ($oc->course->user) {
                                $creatorName = $oc->course->user->name;
                            }

                            // Calculate original price (price before promo discount)
                            // This is the base price that was used when order was placed
                            $originalPrice = $oc->price + ($oc->discount_amount ?? 0);
                            $discountAmount = $oc->discount_amount ?? 0;
                            $taxPrice = $oc->tax_price ?? 0;
                            $priceWithoutTax = $oc->price; // Price after promo discount, before tax
                            $finalPrice = $oc->price + $taxPrice; // Final price with tax

                            // Get course's current price (for reference - may differ from order time price)
                            $courseCurrentPrice = $oc->course
                                ? (
                                    $oc->course->discount_price
                                    && $oc->course->discount_price > 0
                                        ? $oc->course->discount_price
                                        : $oc->course->price ?? 0
                                )
                                : 0;

                            return [
                                'course_id' => $oc->course->id,
                                'title' => $oc->course->title,
                                'image' => $oc->course->thumbnail,
                                'original_price' => round($originalPrice, 2), // Base price at order time (before promo discount)
                                'price' => round($priceWithoutTax, 2), // Price after promo discount (before tax) - same as original_price if no discount
                                'price_without_tax' => round($priceWithoutTax, 2), // Price after promo discount (before tax) - explicit field
                                'discount_amount' => round($discountAmount, 2), // Promo discount amount
                                'tax_price' => round($taxPrice, 2), // Tax amount
                                'final_price' => round($finalPrice, 2), // Final price paid (price + tax)
                                'price_with_tax' => round($finalPrice, 2), // Final price with tax - explicit field
                                'course_current_price' => round($courseCurrentPrice, 2), // Course's current price (may differ from order time)
                                'course_type' => $oc->course->course_type ?? null,
                                'creator_name' => $creatorName,
                                // Refund information
                                'refund_enabled' => $refundEnabled,
                                'refund_period_days' => $refundPeriodDays,
                                'is_refund_eligible' => $isRefundEligible,
                                'refund_days_remaining' => $refundDaysRemaining,
                                'has_refund_request' => $hasRefundRequest,
                                'refund_request_status' => $refundRequestStatus,
                                'refund_request_id' => $refundRequestId,
                                'refund_admin_notes' => $refundAdminNotes,
                                'purchase_date' => $order->created_at->format('Y-m-d H:i:s'),
                            ];
                        })->filter(),
                        'promo_code' => $order->promo_code
                            ? [
                                'id' => $order->promo_code_id,
                                'code' => $order->promo_code,
                                'discount_amount' => $order->discount_amount,
                                'discount_type' => $order->promoCode->discount_type ?? null,
                                'discount_value' => $order->promoCode->discount ?? null,
                            ] : null,
                    ];
                });

            return ApiResponseService::successResponse('Orders fetched successfully', $orders);
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    /**
     * Download Invoice for Completed Order
     */
    public function downloadInvoice(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|integer|exists:orders,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();
            $orderId = $request->order_id;

            // Get the order with all related data
            $order = Order::with([
                'orderCourses.course',
                'promoCode',
                'user.billingDetails',
            ])
                ->where('id', $orderId)
                ->where('user_id', $user?->id)
                ->where('status', 'completed')
                ->first();

            if (!$order) {
                return ApiResponseService::errorResponse('Order not found or not completed');
            }

            // Get promo code information from order
            $totalDiscount = $order->discount_amount ?? 0;
            $appliedPromoCodes = collect();

            if ($order->promo_code) {
                $appliedPromoCodes = collect([[
                    'promo_code' => $order->promo_code,
                    'discount_type' => $order->promoCode->discount_type ?? null,
                    'discount_value' => $order->promoCode->discount ?? null,
                    'discounted_amount' => $order->discount_amount ?? 0,
                    'course_id' => null, // Promo code applies to entire order
                ]]);
            }

            // Get app settings for dynamic content
            $appSettings = HelperService::systemSettings(['app_name', 'horizontal_logo', 'currency_symbol']);

            // Prepare logo as base64 for PDF rendering (relative URLs don't work in mPDF)
            $logoBase64 = null;
            if (!empty($appSettings['horizontal_logo'])) {
                $logoPath = storage_path('app/public/' . $appSettings['horizontal_logo']);
                if (file_exists($logoPath)) {
                    $logoContent = file_get_contents($logoPath);
                    $logoMime = mime_content_type($logoPath);
                    $logoBase64 = 'data:' . $logoMime . ';base64,' . base64_encode($logoContent);
                    Log::info('Logo loaded as base64 for PDF');
                } else {
                    Log::warning('Logo file not found at: ' . $logoPath);
                }
            } else {
                Log::info('No horizontal_logo found in app settings');
            }

            // Get approved refunds for this user with their approval dates
            $approvedRefunds = RefundRequest::where('user_id', $order->user_id)
                ->where('status', 'approved')
                ->get()
                ->groupBy('course_id')
                ->map(
                    // Get the latest refund approval date for each course

                    static fn($refunds) => $refunds->max('processed_at'),
                );

            $orderDate = $order->created_at;

            // Filter out courses with approved refunds that were approved before this order
            $validOrderCourses = $order->orderCourses->filter(static function ($oc) use ($approvedRefunds, $orderDate) {
                if (!$oc->course) {
                    return false;
                }

                $courseId = $oc->course->id;
                $refundApprovalDate = $approvedRefunds->get($courseId);

                // If no refund or refund was approved after this order, include the course
                if (!$refundApprovalDate || $orderDate->gt($refundApprovalDate)) {
                    return true;
                }

                // Refund was approved before this order, exclude it
                return false;
            });

            // Recalculate pricing based on remaining courses
            $subtotal = $validOrderCourses->sum('price');
            $taxAmount = $validOrderCourses->sum('tax_price');

            // Prepare billing details
            $billingDetails = null;
            if ($order->user && $order->user->billingDetails) {
                $billing = $order->user->billingDetails;
                $countries = config('countries');
                $billingDetails = [
                    'first_name' => $billing->first_name,
                    'last_name' => $billing->last_name,
                    'address' => $billing->address,
                    'city' => $billing->city,
                    'state' => $billing->state,
                    'postal_code' => $billing->postal_code,
                    'country' => $countries[$billing->country_code] ?? $billing->country_code,
                    'tax_id' => $billing->tax_id,
                ];
            }

            // Prepare invoice data with null checks
            $invoiceData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_date' => $order->created_at->format('Y-m-d'),
                'app_name' => $appSettings['app_name'] ?? 'Learning Management System',
                'app_logo' => $logoBase64,
                'currency_symbol' => $appSettings['currency_symbol'] ?? '$',
                'customer' => [
                    'name' => $order->user ? $order->user->name : 'Unknown User',
                    'email' => $order->user ? $order->user->email : 'unknown@example.com',
                    'mobile' => $order->user ? $order->user->mobile : null,
                ],
                'billing_details' => $billingDetails,
                'payment_method' => $order->payment_method,
                'courses' => $validOrderCourses->map(static fn($oc) => [
                    'course_id' => $oc->course ? $oc->course->id : null,
                    'title' => $oc->course ? $oc->course->title : 'Course not found',
                    'price' => $oc->price,
                ]),
                'pricing' => [
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total_discount' => round($totalDiscount, 2),
                    'final_total' => round(max(0, $subtotal + $taxAmount - $totalDiscount), 2),
                ],
                'applied_promo_codes' => collect($appliedPromoCodes),
                'status' => $order->status,
            ];

            // Generate PDF invoice
            try {
                try {
                    $html = view('invoices.order-invoice', $invoiceData)->render();
                } catch (Exception $viewError) {
                    Log::error('View rendering error: ' . $viewError->getMessage());
                    throw new Exception('Failed to render invoice template: ' . $viewError->getMessage());
                }

                // Ensure temp directory exists
                $tempDir = storage_path('app/temp');
                if (!file_exists($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                $mpdf = new Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'orientation' => 'P',
                    'margin_left' => 15,
                    'margin_right' => 15,
                    'margin_top' => 16,
                    'margin_bottom' => 16,
                    'margin_header' => 9,
                    'margin_footer' => 9,
                    'tempDir' => $tempDir,
                    'debug' => false,
                ]);

                $mpdf->WriteHTML($html);

                $filename = 'Invoice-' . $order->order_number . '-' . date('Y-m-d') . '.pdf';

                // Generate PDF content as string
                $pdfContent = $mpdf->Output('', 'S');

                // Clear any output buffer
                if (ob_get_level()) {
                    ob_end_clean();
                }

                return response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                    'Content-Length' => strlen((string) $pdfContent),
                ]);
            } catch (Exception $e) {
                Log::error('MPDF Error: ' . $e->getMessage());
                throw $e;
            }
        } catch (Throwable $th) {
            Log::error('Invoice generation failed: ' . $th->getMessage(), [
                'order_id' => $request->order_id,
                'user_id' => Auth::id(),
                'trace' => $th->getTraceAsString(),
            ]);

            return ApiResponseService::errorResponse('Failed to generate invoice: ' . $th->getMessage());
        }
    }

    /**
     * Test Invoice Download (for debugging)
     */
    public function testInvoiceDownload(Request $request)
    {
        try {
            // Get the first completed order for testing
            $order = Order::with([
                'orderCourses.course',
                'promoCode',
                'user.billingDetails',
            ])->where('status', 'completed')->first();

            if (!$order) {
                return ApiResponseService::errorResponse('No completed orders found for testing');
            }

            // Get promo code information from order
            $totalDiscount = $order->discount_amount ?? 0;
            $appliedPromoCodes = collect();

            if ($order->promo_code) {
                $appliedPromoCodes = collect([[
                    'promo_code' => $order->promo_code,
                    'discount_type' => $order->promoCode->discount_type ?? null,
                    'discount_value' => $order->promoCode->discount ?? null,
                    'discounted_amount' => $order->discount_amount ?? 0,
                    'course_id' => null, // Promo code applies to entire order
                ]]);
            }

            // Get app settings for dynamic content
            $appSettings = HelperService::systemSettings(['app_name', 'horizontal_logo', 'currency_symbol']);

            // Prepare logo URL for API response (not base64 since this is JSON API)
            $logoUrl = null;
            if (!empty($appSettings['horizontal_logo'])) {
                $logoUrl = url(Storage::url($appSettings['horizontal_logo']));
            }

            // Get approved refunds for this user with their approval dates
            $approvedRefunds = RefundRequest::where('user_id', $order->user_id)
                ->where('status', 'approved')
                ->get()
                ->groupBy('course_id')
                ->map(
                    // Get the latest refund approval date for each course

                    static fn($refunds) => $refunds->max('processed_at'),
                );

            $orderDate = $order->created_at;

            // Filter out courses with approved refunds that were approved before this order
            $validOrderCourses = $order->orderCourses->filter(static function ($oc) use ($approvedRefunds, $orderDate) {
                if (!$oc->course) {
                    return false;
                }

                $courseId = $oc->course->id;
                $refundApprovalDate = $approvedRefunds->get($courseId);

                // If no refund or refund was approved after this order, include the course
                if (!$refundApprovalDate || $orderDate->gt($refundApprovalDate)) {
                    return true;
                }

                // Refund was approved before this order, exclude it
                return false;
            });

            // Recalculate pricing based on remaining courses
            $subtotal = $validOrderCourses->sum('price');
            $taxAmount = $validOrderCourses->sum('tax_price');

            // Prepare billing details
            $billingDetails = null;
            if ($order->user && $order->user->billingDetails) {
                $billing = $order->user->billingDetails;
                $countries = config('countries');
                $billingDetails = [
                    'first_name' => $billing->first_name,
                    'last_name' => $billing->last_name,
                    'address' => $billing->address,
                    'city' => $billing->city,
                    'state' => $billing->state,
                    'postal_code' => $billing->postal_code,
                    'country' => $countries[$billing->country_code] ?? $billing->country_code,
                    'tax_id' => $billing->tax_id,
                ];
            }

            // Prepare invoice data
            $invoiceData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_date' => $order->created_at->format('Y-m-d'),
                'app_name' => $appSettings['app_name'] ?? 'Learning Management System',
                'app_logo' => $logoUrl,
                'currency_symbol' => $appSettings['currency_symbol'] ?? '$',
                'customer' => [
                    'name' => $order->user ? $order->user->name : 'Unknown User',
                    'email' => $order->user ? $order->user->email : 'unknown@example.com',
                    'mobile' => $order->user ? $order->user->mobile : null,
                ],
                'billing_details' => $billingDetails,
                'payment_method' => $order->payment_method,
                'courses' => $validOrderCourses->map(static fn($oc) => [
                    'course_id' => $oc->course ? $oc->course->id : null,
                    'title' => $oc->course ? $oc->course->title : 'Course not found',
                    'price' => $oc->price,
                ]),
                'pricing' => [
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total_discount' => round($totalDiscount, 2),
                    'final_total' => round(max(0, $subtotal + $taxAmount - $totalDiscount), 2),
                ],
                'applied_promo_codes' => collect($appliedPromoCodes),
                'status' => $order->status,
            ];

            return ApiResponseService::successResponse('Test invoice data generated successfully', $invoiceData);
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Test failed: ' . $th->getMessage());
        }
    }

    /**
     * Get Invoice Data for Preview (without downloading)
     */
    public function getInvoiceData(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|integer|exists:orders,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();
            $orderId = $request->order_id;

            // Get the order with all related data
            $order = Order::with([
                'orderCourses.course',
                'promoCode',
                'user.billingDetails',
            ])
                ->where('id', $orderId)
                ->where('user_id', $user?->id)
                ->where('status', 'completed')
                ->first();

            if (!$order) {
                return ApiResponseService::errorResponse('Order not found or not completed');
            }

            // Get promo code information from order
            $totalDiscount = $order->discount_amount ?? 0;
            $appliedPromoCodes = collect();

            if ($order->promo_code) {
                $appliedPromoCodes = collect([[
                    'promo_code' => $order->promo_code,
                    'discount_type' => $order->promoCode->discount_type ?? null,
                    'discount_value' => $order->promoCode->discount ?? null,
                    'discounted_amount' => $order->discount_amount ?? 0,
                    'course_id' => null, // Promo code applies to entire order
                ]]);
            }

            // Get app settings for dynamic content
            $appSettings = HelperService::systemSettings(['app_name', 'horizontal_logo', 'currency_symbol']);

            // Prepare logo URL for API response
            $logoUrl = null;
            if (!empty($appSettings['horizontal_logo'])) {
                $logoUrl = url(Storage::url($appSettings['horizontal_logo']));
            }

            // Get approved refunds for this user with their approval dates
            $approvedRefunds = RefundRequest::where('user_id', $order->user_id)
                ->where('status', 'approved')
                ->get()
                ->groupBy('course_id')
                ->map(
                    // Get the latest refund approval date for each course

                    static fn($refunds) => $refunds->max('processed_at'),
                );

            $orderDate = $order->created_at;

            // Filter out courses with approved refunds that were approved before this order
            $validOrderCourses = $order->orderCourses->filter(static function ($oc) use ($approvedRefunds, $orderDate) {
                if (!$oc->course) {
                    return false;
                }

                $courseId = $oc->course->id;
                $refundApprovalDate = $approvedRefunds->get($courseId);

                // If no refund or refund was approved after this order, include the course
                if (!$refundApprovalDate || $orderDate->gt($refundApprovalDate)) {
                    return true;
                }

                // Refund was approved before this order, exclude it
                return false;
            });

            // Recalculate pricing based on remaining courses
            $subtotal = $validOrderCourses->sum('price');
            $taxAmount = $validOrderCourses->sum('tax_price');

            // Prepare billing details
            $billingDetails = null;
            if ($order->user && $order->user->billingDetails) {
                $billing = $order->user->billingDetails;
                $countries = config('countries');
                $billingDetails = [
                    'first_name' => $billing->first_name,
                    'last_name' => $billing->last_name,
                    'address' => $billing->address,
                    'city' => $billing->city,
                    'state' => $billing->state,
                    'postal_code' => $billing->postal_code,
                    'country' => $countries[$billing->country_code] ?? $billing->country_code,
                    'tax_id' => $billing->tax_id,
                ];
            }

            // Prepare invoice data
            $invoiceData = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_date' => $order->created_at->format('Y-m-d'),
                'transaction_date' => $order->created_at,
                'app_name' => $appSettings['app_name'] ?? 'Learning Management System',
                'app_logo' => $logoUrl,
                'currency_symbol' => $appSettings['currency_symbol'] ?? '$',
                'customer' => [
                    'name' => $order->user ? $order->user->name : 'Unknown User',
                    'email' => $order->user ? $order->user->email : 'unknown@example.com',
                    'mobile' => $order->user ? $order->user->mobile : null,
                ],
                'billing_details' => $billingDetails,
                'payment_method' => $order->payment_method,
                'courses' => $validOrderCourses->map(static fn($oc) => [
                    'course_id' => $oc->course ? $oc->course->id : null,
                    'title' => $oc->course ? $oc->course->title : 'Course not found',
                    'price' => $oc->price,
                ]),
                'pricing' => [
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total_discount' => round($totalDiscount, 2),
                    'final_total' => round(max(0, $subtotal + $taxAmount - $totalDiscount), 2),
                ],
                'applied_promo_codes' => collect($appliedPromoCodes),
                'status' => $order->status,
            ];

            return ApiResponseService::successResponse('Invoice data retrieved successfully', $invoiceData);
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to retrieve invoice data: ' . $th->getMessage());
        }
    }

    /**
     * Get real IP address from request (handles proxies and load balancers)
     */
    private function getRealIpAddress(Request $request): null|string
    {
        // Check for IP in various headers (for proxies/load balancers)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP', // Nginx proxy
            'HTTP_X_FORWARDED_FOR', // Standard proxy header
            'HTTP_X_FORWARDED', // Alternative proxy header
            'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
            'HTTP_CLIENT_IP', // Some proxies
        ];

        foreach ($ipHeaders as $header) {
            $ip = $request->server($header);
            if ($ip) {
                // X-Forwarded-For can contain multiple IPs, get the first one
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback to Laravel's ip() method
        $ip = $request->ip();
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }

        return null;
    }

    /**
     * Get country code from IP address
     */
    private function getCountryCodeFromIp(null|string $ipAddress): null|string
    {
        if (!$ipAddress) {
            return null;
        }

        try {
            // Skip local/private IPs
            if (
                filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                === false
            ) {
                return null;
            }

            // Try ipapi.co first (free, 1000 requests/day)
            $url = "https://ipapi.co/{$ipAddress}/country/";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && !empty($response) && !$curlError) {
                $countryCode = trim($response);
                // Validate country code (should be 2 letters)
                if (strlen($countryCode) === 2 && ctype_alpha($countryCode)) {
                    return strtoupper($countryCode);
                }
            }

            // Fallback to ip-api.com (free, 45 requests/minute)
            $url = "http://ip-api.com/json/{$ipAddress}?fields=countryCode";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && !$curlError) {
                $data = json_decode($response, true);
                if (isset($data['countryCode']) && !empty($data['countryCode'])) {
                    $countryCode = strtoupper((string) $data['countryCode']);
                    if (strlen($countryCode) === 2 && ctype_alpha($countryCode)) {
                        return $countryCode;
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to get country from IP: ' . $e->getMessage(), [
                'ip' => $ipAddress,
            ]);
        }

        return null;
    }

    /**
     * Get country code from request (IP-based with fallback to user's country)
     */
    private function getCountryCodeForTax(Request $request, $user): null|string
    {
        // Get country code from IP address if available
        $countryCode = null;
        $ipAddress = $this->getRealIpAddress($request);
        if ($ipAddress) {
            $countryCode = $this->getCountryCodeFromIp($ipAddress);
        }

        // Fallback to user's country code if IP detection fails
        if (!$countryCode) {
            $countryCode = $user->country_code ?? null;
        }

        return $countryCode;
    }

    /**
     * Handle Buy Now (Direct Purchase) Order
     */
    private function handleBuyNowOrder(Request $request, $user)
    {
        $course = Course::find($request->course_id);
        if (!$course) {
            return ApiResponseService::validationError('Course not found.');
        }

        // Check if user already purchased this course (excluding approved refunds)
        $existingOrder = Order::where('user_id', $user->id)
            ->whereHas('orderCourses', static function ($query) use ($course): void {
                $query->where('course_id', $course->id);
            })
            ->where('status', 'completed')
            ->first();

        if ($existingOrder) {
            // Check if there's an approved refund for this course
            $hasApprovedRefund = RefundRequest::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', 'approved')
                ->exists();

            // If no approved refund, user already purchased
            if (!$hasApprovedRefund) {
                return ApiResponseService::validationError('You have already purchased this course.');
            }
        }

        // Check if course is free
        $isFree = $course->course_type === 'free';

        DB::beginTransaction();

        try {
            // Create order
            $paymentMethod = $isFree ? 'free' : (string) $request->payment_method;

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'payment_method' => $paymentMethod,
                'total_price' => 0,
                'tax_price' => 0,
                'final_price' => 0,
                'promo_code_id' => null,
                'discount_amount' => 0,
                'promo_code' => null,
                'status' => $isFree ? 'completed' : 'pending', // Auto-complete for free courses
            ]);

            // Tax is always exclusive - get tax based on IP country (with fallback to user's country)
            $countryCode = $this->getCountryCodeForTax($request, $user);
            $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

            // Get base price (from database - always exclusive)
            $basePrice = $course->discount_price && $course->discount_price > 0
                ? $course->discount_price
                : $course->price ?? 0;

            $total = $isFree ? 0 : $basePrice;

            // Apply promo code if provided (only for paid courses)
            $resolvedPromoCodeId = $request->get('resolved_promo_code_id');
            if (!$isFree && $resolvedPromoCodeId) {
                // Apply promo code to base price (tax is exclusive)
                $total = $this->applyPromoCodeToOrder(
                    $order,
                    $course,
                    $resolvedPromoCodeId,
                    $total,
                    'exclusive',
                    $totalTaxPercentage,
                );
            }

            // Calculate tax on base price after promo code (always exclusive)
            $totalTax = 0;
            if (!$isFree && $total > 0 && $totalTaxPercentage > 0) {
                // Tax is exclusive - calculate tax on base price
                $totalTax = ($total * $totalTaxPercentage) / 100;
            }

            // Create order course with calculated tax
            OrderCourse::create([
                'order_id' => $order->id,
                'course_id' => $course->id,
                'price' => $isFree ? 0 : $total,
                'tax_price' => $totalTax,
            ]);

            // Calculate final price (base price + tax)
            $finalPrice = $total + $totalTax;

            // Update order totals
            $order->update([
                'total_price' => $total,
                'tax_price' => $totalTax,
                'final_price' => $finalPrice,
            ]);

            // If free course, complete the order directly
            if ($isFree) {
                // Create curriculum tracking entries for all curriculum items
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                DB::commit();

                // Dispatch FCM Job
                dispatch(new SendOrderNotifications($order, $user));

                return ApiResponseService::successResponse('Free course enrolled successfully', [
                    'order' => $order->fresh(),
                    'is_free' => true,
                ]);
            }

            // Handle 100% coupon discount (final price is 0 after promo code)
            if ($finalPrice <= 0) {
                // Generate transaction ID
                $transactionId = 'FREE-' . strtoupper(uniqid());

                // Update order with free payment method and complete status
                $order->update([
                    'payment_method' => 'free',
                    'status' => 'completed',
                    'is_payment' => 1,
                    'transaction_id' => $transactionId,
                ]);

                // Create transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => 0,
                    'payment_method' => 'free',
                    'status' => 'completed',
                    'message' => 'Payment successful via 100% discount coupon',
                ]);

                // Decrement promo code usage
                if ($order->promo_code_id) {
                    PromoCode::where('id', $order->promo_code_id)
                        ->whereNotNull('no_of_users')
                        ->where('no_of_users', '>', 0)
                        ->decrement('no_of_users', 1);
                }

                // Create curriculum tracking entries
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                // Calculate and create commission records (even for 0 amount, for tracking)
                try {
                    CommissionService::calculateCommissions($order);
                    CommissionService::markCommissionsAsPaid($order);
                    Log::info("Commission processing completed for 100% coupon Order: {$order->id}");
                } catch (Exception $e) {
                    Log::error("Commission processing failed for Order: {$order->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                DB::commit();

                // Dispatch notification
                dispatch(new SendOrderNotifications($order->fresh(), $user));

                return ApiResponseService::successResponse('Course enrolled successfully with 100% discount', [
                    'order' => $order->fresh(),
                    'payment_method' => 'free',
                    'transaction_id' => $transactionId,
                ]);
            }

            // Handle wallet payment
            $paymentMethod = (string) $request->payment_method;
            if ($paymentMethod === 'wallet') {
                // Check wallet balance
                $walletBalance = WalletService::getWalletBalance($user->id);
                if ($walletBalance < $finalPrice) {
                    DB::rollBack();

                    return ApiResponseService::validationError(
                        'Insufficient wallet balance. Required: '
                        . number_format($finalPrice, 2)
                        . ', Available: '
                        . number_format($walletBalance, 2),
                    );
                }

                // Deduct from wallet
                WalletService::debitWallet(
                    $user->id,
                    $finalPrice,
                    'order',
                    "Order payment for course: {$course->title}",
                    $order->id,
                    Order::class,
                );

                // Generate transaction ID
                $transactionId = 'WLT-' . strtoupper(uniqid());

                // Create transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => $finalPrice,
                    'payment_method' => 'wallet',
                    'status' => 'completed',
                    'message' => 'Payment successful via Wallet',
                ]);

                // Update order status
                $order->update([
                    'status' => 'completed',
                    'is_payment' => 1,
                    'transaction_id' => $transactionId,
                ]);

                // Decrement promo code usage if promo code was used (Buy Now)
                if ($order->promo_code_id) {
                    PromoCode::where('id', $order->promo_code_id)
                        ->whereNotNull('no_of_users')
                        ->where('no_of_users', '>', 0)
                        ->decrement('no_of_users', 1);
                }

                // Create curriculum tracking entries
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                // Calculate and create commission records
                try {
                    CommissionService::calculateCommissions($order);
                    CommissionService::markCommissionsAsPaid($order);
                    Log::info("Commission processing completed for Order: {$order->id}");
                } catch (Exception $e) {
                    Log::error("Commission processing failed for Order: {$order->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                DB::commit();

                // Dispatch notification
                dispatch(new SendOrderNotifications($order->fresh(), $user));

                return ApiResponseService::successResponse('Order placed and paid successfully using wallet', [
                    'order' => $order->fresh(),
                    'payment_method' => 'wallet',
                    'transaction_id' => $transactionId,
                ]);
            }

            // Payment initialization for other payment methods
            $currency = HelperService::systemSettings(['currency_code']);
            $paymentService = app(PaymentFactory::class)->for($paymentMethod);

            // Get type parameter (web/app) from request
            $type = $request->input('type', 'web');

            // Refresh order to get updated prices (after promo code and tax calculations)
            $order->refresh();

            $paymentInit = $paymentService->initiate($order, [
                'currency' => $currency,
                'customer' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'type' => $type, // Pass type to payment service
            ]);

            DB::commit();

            return ApiResponseService::successResponse('Order placed successfully', [
                'order' => $order->fresh(),
                'payment' => $paymentInit,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();

            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    /**
     * Handle Cart-Based Order (Original Logic)
     */
    private function handleCartOrder(Request $request, $user)
    {
        // 1. Get cart with promo codes
        $cartItems = Cart::with(['course', 'promoCode'])->where('user_id', $user->id)->get();
        if ($cartItems->isEmpty()) {
            return ApiResponseService::validationError('Your cart is empty.');
        }

        // 2. Check if user already purchased any of these courses (excluding approved refunds)
        $courseIds = $cartItems->pluck('course.id')->filter()->toArray();
        $alreadyPurchased = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereHas('orderCourses', static function ($query) use ($courseIds): void {
                $query->whereIn('course_id', $courseIds);
            })
            ->with(['orderCourses' => static function ($query) use ($courseIds): void {
                $query->whereIn('course_id', $courseIds)->with('course');
            }])
            ->first();

        if ($alreadyPurchased) {
            // Filter out courses with approved refunds
            $purchasedCourses = $alreadyPurchased->orderCourses->filter(static function ($orderCourse) use ($user) {
                $hasApprovedRefund = RefundRequest::where('user_id', $user->id)
                    ->where('course_id', $orderCourse->course_id)
                    ->where('status', 'approved')
                    ->exists();

                return !$hasApprovedRefund;
            });

            // If there are still courses without approved refunds, show error
            if ($purchasedCourses->isNotEmpty()) {
                $purchasedCourseNames = $purchasedCourses->pluck('course.title')->filter()->toArray();

                $courseList = implode(', ', $purchasedCourseNames);

                return ApiResponseService::validationError('You have already purchased the following course(s): '
                . $courseList);
            }
        }

        // 3. Check if all courses are free
        $allCoursesAreFree = $cartItems->every(
            static fn($cart) => $cart->course && $cart->course->course_type === 'free',
        );

        DB::beginTransaction();

        try {
            // 4. Create order
            $paymentMethod = $allCoursesAreFree ? 'free' : (string) $request->payment_method;

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'payment_method' => $paymentMethod,
                'total_price' => 0,
                'tax_price' => 0,
                'final_price' => 0,
                'promo_code_id' => null,
                'discount_amount' => 0,
                'promo_code' => null,
                'status' => $allCoursesAreFree ? 'completed' : 'pending', // Auto-complete for free courses
            ]);

            $total = 0;
            $totalTax = 0;
            $totalDiscountAmount = 0;
            $hasFreeCourses = false;
            $appliedPromoCodes = [];

            // Tax is always exclusive - get tax based on IP country (with fallback to user's country)
            $countryCode = $this->getCountryCodeForTax($request, $user);
            $totalTaxPercentage = Tax::getTotalTaxPercentageByCountry($countryCode);

            // 5. Calculate price and apply individual promo codes per course
            foreach ($cartItems as $cart) {
                $course = $cart->course;
                if (!$course) {
                    continue;
                }

                // Check if course is free
                $isFree = $course->course_type === 'free';
                if ($isFree) {
                    $hasFreeCourses = true;
                }

                // Get base price (from database - always exclusive)
                $basePrice = $isFree
                    ? 0
                    : (
                        $course->discount_price
                        && $course->discount_price > 0
                            ? $course->discount_price
                            : $course->price ?? 0
                    );

                // Price is always base price (tax is exclusive)
                $originalPrice = $basePrice;

                $priceAfterDiscount = $originalPrice;
                $courseDiscount = 0;

                // Apply promo code if exists for this course
                if (!$isFree && $cart->promo_code_id && $cart->promoCode) {
                    $promo = $cart->promoCode;

                    // Check if promo code is still valid
                    if ($promo->status == 1 && $promo->start_date <= today() && $promo->end_date >= today()) {
                        // Check usage limit (no_of_users)
                        $isUsageLimitReached = $promo->no_of_users !== null && $promo->no_of_users <= 0;

                        // Calculate discount
                        if ($promo->discount_type === 'amount') {
                            $courseDiscount = min($promo->discount, $originalPrice);
                        } elseif ($promo->discount_type === 'percentage') {
                            // Clamp discount percentage to 100% max
                            $discountPercent = min($promo->discount, 100);
                            $courseDiscount = ($originalPrice * $discountPercent) / 100;
                        }

                        $courseDiscount = min($courseDiscount, $originalPrice);
                        $priceAfterDiscount = max(0, $originalPrice - $courseDiscount);
                        $totalDiscountAmount += $courseDiscount;

                        // Track applied promo codes
                        if (!isset($appliedPromoCodes[$promo->id])) {
                            $appliedPromoCodes[$promo->id] = [
                                'code' => $promo->promo_code,
                                'discount' => $courseDiscount,
                            ];
                        } else {
                            $appliedPromoCodes[$promo->id]['discount'] += $courseDiscount;
                        }
                    }
                }

                // Calculate tax amount (always exclusive)
                $tax = 0;

                if (!$isFree && $priceAfterDiscount > 0 && $totalTaxPercentage > 0) {
                    // Tax is exclusive - calculate tax on base price after discount
                    $tax = ($priceAfterDiscount * $totalTaxPercentage) / 100;
                }

                $total += $priceAfterDiscount;
                $totalTax += $tax;

                OrderCourse::create([
                    'order_id' => $order->id,
                    'course_id' => $course->id,
                    'promo_code_id' => $cart->promo_code_id, // Store individual promo code
                    'price' => $priceAfterDiscount,
                    'discount_amount' => $courseDiscount, // Store discount amount for this course
                    'tax_price' => $tax,
                ]);
            }

            // 6. Update order with promo code information
            if (!empty($appliedPromoCodes) && !$allCoursesAreFree) {
                // If multiple promo codes, store as comma-separated
                $promoCodeNames = array_column($appliedPromoCodes, 'code');
                $order->update([
                    'discount_amount' => $totalDiscountAmount,
                    'promo_code' => implode(', ', $promoCodeNames),
                ]);
            }

            // 7. Update order totals (tax is always exclusive)
            // Final price = base price + tax
            $finalPrice = $total + $totalTax;

            $order->update([
                'total_price' => $total,
                'tax_price' => $totalTax,
                'final_price' => $finalPrice,
            ]);

            // 8. If all courses are free, complete order directly
            if ($allCoursesAreFree) {
                // Create curriculum tracking entries for all curriculum items
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                // Clear cart
                Cart::where('user_id', $user->id)->delete();

                DB::commit();

                // Dispatch FCM Job
                dispatch(new SendOrderNotifications($order, $user));

                return ApiResponseService::successResponse('Free courses enrolled successfully', [
                    'order' => $order->fresh(),
                    'is_free' => true,
                ]);
            }

            // 8.5. Handle 100% coupon discount (final price is 0 after promo code)
            if ($finalPrice <= 0) {
                // Generate transaction ID
                $transactionId = 'FREE-' . strtoupper(uniqid());

                // Update order with free payment method and complete status
                $order->update([
                    'payment_method' => 'free',
                    'status' => 'completed',
                    'is_payment' => 1,
                    'transaction_id' => $transactionId,
                ]);

                // Create transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => 0,
                    'payment_method' => 'free',
                    'status' => 'completed',
                    'message' => 'Payment successful via 100% discount coupon',
                ]);

                // Decrement promo code usage for cart orders (per-course promo codes)
                $usedPromoCodeIds = OrderCourse::where('order_id', $order->id)
                    ->whereNotNull('promo_code_id')
                    ->pluck('promo_code_id')
                    ->unique();

                foreach ($usedPromoCodeIds as $promoCodeId) {
                    PromoCode::where('id', $promoCodeId)
                        ->whereNotNull('no_of_users')
                        ->where('no_of_users', '>', 0)
                        ->decrement('no_of_users', 1);
                }

                // Create curriculum tracking entries
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                // Calculate and create commission records (even for 0 amount, for tracking)
                try {
                    CommissionService::calculateCommissions($order);
                    CommissionService::markCommissionsAsPaid($order);
                    Log::info("Commission processing completed for 100% coupon Order: {$order->id}");
                } catch (Exception $e) {
                    Log::error("Commission processing failed for Order: {$order->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                // Clear cart
                Cart::where('user_id', $user->id)->delete();

                DB::commit();

                // Dispatch notification
                dispatch(new SendOrderNotifications($order->fresh(), $user));

                return ApiResponseService::successResponse('Courses enrolled successfully with 100% discount', [
                    'order' => $order->fresh(),
                    'payment_method' => 'free',
                    'transaction_id' => $transactionId,
                ]);
            }

            // 9. Handle wallet payment
            $paymentMethod = (string) $request->payment_method;
            if ($paymentMethod === 'wallet') {
                // Check wallet balance
                $walletBalance = WalletService::getWalletBalance($user->id);
                if ($walletBalance < $finalPrice) {
                    DB::rollBack();

                    return ApiResponseService::validationError(
                        'Insufficient wallet balance. Required: '
                        . number_format($finalPrice, 2)
                        . ', Available: '
                        . number_format($walletBalance, 2),
                    );
                }

                // Deduct from wallet
                WalletService::debitWallet(
                    $user->id,
                    $finalPrice,
                    'order',
                    "Order payment for order: {$order->order_number}",
                    $order->id,
                    Order::class,
                );

                // Generate transaction ID
                $transactionId = 'WLT-' . strtoupper(uniqid());

                // Create transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => $finalPrice,
                    'payment_method' => 'wallet',
                    'status' => 'completed',
                    'message' => 'Payment successful via Wallet',
                ]);

                // Update order status
                $order->update([
                    'status' => 'completed',
                    'is_payment' => 1,
                    'transaction_id' => $transactionId,
                ]);

                // Decrement promo code usage for cart orders (per-course promo codes)
                $usedPromoCodeIds = OrderCourse::where('order_id', $order->id)
                    ->whereNotNull('promo_code_id')
                    ->pluck('promo_code_id')
                    ->unique();

                foreach ($usedPromoCodeIds as $promoCodeId) {
                    PromoCode::where('id', $promoCodeId)
                        ->whereNotNull('no_of_users')
                        ->where('no_of_users', '>', 0)
                        ->decrement('no_of_users', 1);
                }

                // Create curriculum tracking entries
                OrderTrackingService::createCurriculumTrackingEntries($order, $user);

                // Calculate and create commission records
                // FIXME: giving `commission` to instructor instead of admin.
                // instructor should receive `purchase` transaction rather than commision
                try {
                    CommissionService::calculateCommissions($order);
                    CommissionService::markCommissionsAsPaid($order);
                    Log::info("Commission processing completed for Order: {$order->id}");
                } catch (Exception $e) {
                    Log::error("Commission processing failed for Order: {$order->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                // Clear cart for wallet payment
                Cart::where('user_id', $user->id)->delete();

                DB::commit();

                // Dispatch notification
                dispatch(new SendOrderNotifications($order->fresh(), $user));

                return ApiResponseService::successResponse('Order placed and paid successfully using wallet', [
                    'order' => $order->fresh(),
                    'payment_method' => 'wallet',
                    'transaction_id' => $transactionId,
                ]);
            }

            // 10. Payment init for other payment methods
            $currency = HelperService::systemSettings(['currency_code']);
            $paymentService = app(PaymentFactory::class)->for($paymentMethod);

            // Get type parameter (web/app) from request
            $type = $request->input('type', 'web');

            // Refresh order to get updated prices (after promo code and tax calculations)
            $order->refresh();

            $paymentInit = $paymentService->initiate($order, [
                'currency' => $currency,
                'customer' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'type' => $type, // Pass type to payment service
            ]);

            // 11. Don't clear cart for payment gateways - cart will be cleared after payment success
            // Cart clearing is skipped for all payment gateways (stripe, razorpay, flutterwave, etc.)

            // 12. Initialize course tracking for enrolled courses
            $this->initializeCourseTracking($order, $user);

            DB::commit();

            return ApiResponseService::successResponse('Order placed successfully', [
                'order' => $order->fresh(),
                'payment' => $paymentInit,
            ]);
        } catch (Throwable $th) {
            DB::rollBack();

            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    /**
     * Apply promo code to order (for Buy Now)
     */
    private function applyPromoCodeToOrder($order, $course, $promoCodeId, $total)
    {
        // Get promo code with its details and relationships
        $promoCode = PromoCode::where('id', $promoCodeId)
            ->where('status', 1)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->with(['user.roles', 'courses'])
            ->first();

        if (!$promoCode) {
            return $total; // No valid promo code
        }

        // Check usage limit (no_of_users)
        if ($promoCode->no_of_users !== null && $promoCode->no_of_users <= 0) {
            Log::warning("Promo code usage limit exceeded for promo code: {$promoCode->promo_code}");

            return $total; // Skip promo code - usage limit reached
        }

        // Validate promo code is applicable to this course
        $isAdmin = $promoCode->user->roles->contains('name', 'Admin');
        $isInstructor = $promoCode->user->roles->contains('name', 'Instructor');
        $isValidForCourse = false;

        if ($isAdmin) {
            // Admin codes apply to all courses
            $isValidForCourse = true;
        } elseif ($isInstructor) {
            // Check if instructor owns this course
            $instructorCourses = $promoCode->courses->pluck('id')->toArray();
            $isValidForCourse = in_array($course->id, $instructorCourses);
        }

        if (!$isValidForCourse) {
            return $total; // No applicable promo code
        }

        // Get base price
        $basePrice = $course->discount_price && $course->discount_price > 0
            ? $course->discount_price
            : $course->price ?? 0;

        if ($basePrice <= 0) {
            return $total; // No price to discount
        }

        // For promo calculation, use the display price (which is passed as $total)
        // This matches cart API logic where promo applies on display price
        $priceForPromo = $total;

        // Calculate discount based on type (apply on display price)
        $discountAmount = 0;
        if ($promoCode->discount_type === 'amount') {
            // Fixed amount discount
            $discountAmount = min($promoCode->discount, $priceForPromo);
        } elseif ($promoCode->discount_type === 'percentage') {
            // Clamp discount percentage to 100% max
            $discount = min($promoCode->discount, 100);
            $discountAmount = ($priceForPromo * $discount) / 100;
        }

        // Ensure discount doesn't exceed the price we're applying promo on
        $discountAmount = min($discountAmount, $priceForPromo);
        $priceAfterDiscount = max(0, $priceForPromo - $discountAmount);

        // Store promo code in order
        $order->update([
            'promo_code_id' => $promoCode->id,
            'promo_code' => $promoCode->promo_code,
            'discount_amount' => $discountAmount,
        ]);

        // Return new total after discount
        return $priceAfterDiscount;
    }

    /**
     * Initialize course tracking for enrolled courses
     */
    private function initializeCourseTracking($order, $user)
    {
        // Only initialize tracking for completed orders
        if ($order->status !== 'completed') {
            return;
        }

        // Create curriculum tracking entries for all curriculum items
        OrderTrackingService::createCurriculumTrackingEntries($order, $user);
    }

    /**
     * Purchase certificate for a free course
     */
    public function purchaseCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'payment_method' => 'required|in:stripe,razorpay,flutterwave,wallet',
            'type' => 'nullable|in:web,app',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;

            // Get course
            $course = Course::find($courseId);
            if (!$course) {
                return ApiResponseService::validationError('Course not found');
            }

            // Check if course is free
            if ($course->course_type !== 'free') {
                return ApiResponseService::validationError('Certificate purchase is only available for free courses');
            }

            // Check if certificate is enabled for this course
            if (!$course->certificate_enabled) {
                return ApiResponseService::validationError('Certificate is not available for this course');
            }

            // Check if certificate fee is set
            if (!$course->certificate_fee || $course->certificate_fee <= 0) {
                return ApiResponseService::validationError('Certificate fee is not set for this course');
            }

            // Check if user has completed the free course
            $hasCompletedCourse = Order::where('user_id', $user?->id)
                ->whereHas('orderCourses', static function ($query) use ($courseId): void {
                    $query->where('course_id', $courseId);
                })
                ->where('status', 'completed')
                ->exists();

            if (!$hasCompletedCourse) {
                return ApiResponseService::validationError('You must enroll and complete the free course first');
            }

            // Check if user has already purchased certificate
            $alreadyPurchased = OrderCourse::where('course_id', $courseId)
                ->whereHas('order', static function ($query) use ($user): void {
                    $query->where('user_id', $user?->id)->where('status', 'completed');
                })
                ->where('certificate_purchased', true)
                ->exists();

            if ($alreadyPurchased) {
                return ApiResponseService::validationError(
                    'You have already purchased the certificate for this course',
                );
            }

            DB::beginTransaction();

            $certificateFee = $course->certificate_fee;
            // Get tax info for free courses
            $pricingService = app(PricingCalculationService::class);
            $certificateTaxPercentage = $pricingService->getTaxPercentageFromRequest($request);
            $certificateTaxAmount = round(($certificateFee * $certificateTaxPercentage) / 100, 2);
            $certificateFinalPrice = round($certificateFee + $certificateTaxAmount, 2);

            try {
                // Create order for certificate
                $order = Order::create([
                    'user_id' => $user->id,
                    'order_number' => 'CERT-' . strtoupper(uniqid()),
                    'payment_method' => $request->payment_method,
                    'total_price' => $certificateFee,
                    'tax_price' => $certificateTaxAmount,
                    'final_price' => $certificateFinalPrice,
                    'promo_code_id' => null,
                    'discount_amount' => 0,
                    'promo_code' => null,
                    'status' => 'pending',
                ]);

                // Create an order course with a certificate purchase flag
                OrderCourse::create([
                    'order_id' => $order->id,
                    'course_id' => $course->id,
                    'price' => 0, // Course is free
                    'tax_price' => 0,
                    'certificate_purchased' => true,
                    'certificate_fee' => $certificateFinalPrice,
                    'certificate_purchased_at' => now(),
                ]);

                // Handle wallet payment
                if ($request->payment_method === 'wallet') {
                    // Check wallet balance
                    $walletBalance = WalletService::getWalletBalance($user->id);
                    if ($walletBalance < $certificateFinalPrice) {
                        DB::rollBack();

                        return ApiResponseService::validationError(
                            'Insufficient wallet balance. Required: '
                            . number_format($certificateFinalPrice, 2)
                            . ', Available: '
                            . number_format($walletBalance, 2),
                        );
                    }

                    // Deduct from wallet
                    WalletService::debitWallet(
                        $user->id,
                        $certificateFinalPrice,
                        'certificate',
                        "Certificate purchase for course: {$course->title}",
                        $order->id,
                        Order::class,
                    );

                    // Generate transaction ID
                    $transactionId = 'WLT-' . strtoupper(uniqid());

                    // Create a transaction record
                    Transaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'transaction_id' => $transactionId,
                        'amount' => $certificateFinalPrice,
                        'payment_method' => 'wallet',
                        'status' => 'completed',
                        'message' => 'Certificate purchase payment successful via Wallet',
                    ]);

                    // Update order status
                    $order->update([
                        'status' => 'completed',
                        'is_payment' => 1,
                        'transaction_id' => $transactionId,
                    ]);

                    DB::commit();

                    // Dispatch notification
                    dispatch(new SendOrderNotifications($order->fresh(), $user));

                    // Build response
                    $response = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'course_id' => $course->id,
                        'course_title' => $course->title,
                        'certificate_fee' => (float) $certificateFinalPrice,
                        'payment_method' => 'wallet',
                        'transaction_id' => $transactionId,
                        'status' => 'completed',
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    ];

                    return ApiResponseService::successResponse(
                        'Certificate purchased successfully using wallet',
                        $response,
                    );
                }

                DB::commit();

                // Get type parameter (web/app) from request, default to 'web'
                $type = $request->input('type', 'web');

                // Generate payment URL for other payment methods
                $paymentInit = null;
                try {
                    $paymentService = app(PaymentFactory::class)->for($order->payment_method);

                    // Get currency based on payment method
                    $currencySettings = HelperService::systemSettings([
                        'stripe_currency',
                        'razorpay_currency',
                        'flutterwave_currency',
                        'currency_code',
                    ]);

                    // Format currency based on payment method requirements
                    if ($order->payment_method === 'razorpay') {
                        // Razorpay expects currency in array format with currency_code
                        // First try payment method specific, then fallback to currency_code, then default
                        $currencyCode = !empty($currencySettings['razorpay_currency'])
                            ? $currencySettings['razorpay_currency']
                            : (!empty($currencySettings['currency_code']) ? $currencySettings['currency_code'] : 'INR');
                        $currency = ['currency_code' => $currencyCode];
                    } else {
                        // Stripe and Flutterwave expect currency as string
                        if ($order->payment_method === 'stripe') {
                            $currency = !empty($currencySettings['stripe_currency'])
                                ? $currencySettings['stripe_currency']
                                : (
                                    !empty($currencySettings['currency_code'])
                                        ? $currencySettings['currency_code']
                                        : 'USD'
                                );
                        } else {
                            $currency = !empty($currencySettings['flutterwave_currency'])
                                ? $currencySettings['flutterwave_currency']
                                : (
                                    !empty($currencySettings['currency_code'])
                                        ? $currencySettings['currency_code']
                                        : 'NGN'
                                );
                        }
                    }

                    $paymentInit = $paymentService->initiate($order, [
                        'currency' => $currency,
                        'customer' => [
                            'id' => $user->id,
                            'email' => $user?->email,
                            'name' => $user?->name,
                            'phone' => $user->phone ?? '',
                        ],
                        'type' => $type, // Pass type to payment service
                    ]);
                } catch (Exception $e) {
                    Log::error('Payment URL generation failed for certificate purchase: ' . $e->getMessage());

                    return ApiResponseService::errorResponse('Failed to initialize payment: ' . $e->getMessage());
                }

                // Build response based on type
                if ($type === 'app') {
                    // For app: return JSON with payment data
                    $response = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'course_id' => $course->id,
                        'course_title' => $course->title,
                        'certificate_fee' => (float) $course->certificate_fee,
                        'tax_percentage' => (float) $certificateTaxPercentage,
                        'tax_price' => (float) $order->tax_price,
                        'final_price' => (float) $order->final_price,
                        'payment_method' => $order->payment_method,
                        'status' => $order->status,
                        'payment' => $paymentInit, // Full payment data for app
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    ];
                } else {
                    // For web: return redirect URL
                    $response = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'course_id' => $course->id,
                        'course_title' => $course->title,
                        'certificate_fee' => (float) $course->certificate_fee,
                        'tax_percentage' => (float) $certificateTaxPercentage,
                        'tax_price' => (float) $order->tax_price,
                        'final_price' => (float) $order->final_price,
                        'payment_method' => $order->payment_method,
                        'status' => $order->status,
                        'payment_url' => $paymentInit['url'] ?? null, // Redirect URL for web
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    ];
                }

                return ApiResponseService::successResponse(
                    'Certificate purchase order created successfully',
                    $response,
                );
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to purchase certificate: ' . $th->getMessage());
        }
    }
}
