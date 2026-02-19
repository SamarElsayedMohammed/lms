<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Transaction;
use App\Services\ApiResponseService;
use App\Services\CommissionService;
use App\Services\HelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class RazorpayController extends Controller
{
    /**
     * Get the base URL from settings or fallback to APP_URL
     */
    private function getBaseUrl()
    {
        $websiteUrl = HelperService::systemSettings('website_url');
        return $websiteUrl ?: env('APP_URL');
    }

    /**
     * Show Razorpay payment page
     */
    public function showPaymentPage(Request $request)
    {
        try {
            // Get Razorpay settings
            $razorpaySettings = HelperService::systemSettings([
                'razorpay_api_key',
                'razorpay_secret_key',
                'razorpay_status',
            ]);

            if (empty($razorpaySettings['razorpay_status']) || $razorpaySettings['razorpay_status'] != 1) {
                return view('errors.payment', ['message' => 'Razorpay payment gateway is not enabled']);
            }

            // Get parameters from URL
            $orderId = $request->query('order_id');
            $amount = $request->query('amount');
            $currency = $request->query('currency', 'INR');
            $name = $request->query('name', config('app.name'));
            $description = $request->query('description', 'Course Payment');
            $prefillName = $request->query('prefill_name');
            $prefillEmail = $request->query('prefill_email');

            if (!$orderId || !$amount) {
                return view('errors.payment', ['message' => 'Invalid payment parameters']);
            }

            return view('payment.razorpay', compact(
                'razorpaySettings',
                'orderId',
                'amount',
                'currency',
                'name',
                'description',
                'prefillName',
                'prefillEmail',
            ));
        } catch (\Exception $e) {
            return view('errors.payment', ['message' => 'Payment initialization failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle Razorpay callback
     */
    public function handleCallback(Request $request)
    {
        $type = $request->input('type', $request->query('type', 'web')); // Get type parameter, default to 'web'

        try {
            $razorpaySettings = HelperService::systemSettings([
                'razorpay_api_key',
                'razorpay_secret_key',
            ]);

            $api = new Api($razorpaySettings['razorpay_api_key'], $razorpaySettings['razorpay_secret_key']);

            $attributes = [
                'razorpay_order_id' => $request->razorpay_order_id,
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'razorpay_signature' => $request->razorpay_signature,
            ];

            $api->utility->verifyPaymentSignature($attributes);

            // Payment successful
            $order = Order::with('orderCourses', 'user')->where('order_number', $request->order_number)->first();
            if (!$order) {
                // Check type for response format
                if ($type === 'app') {
                    return ApiResponseService::errorResponse(
                        'Order not found',
                        [
                            'status' => 'order_not_found',
                            'order_number' => $request->order_number,
                            'redirect_url' => $this->getBaseUrl() . '/payment-status?status=order_not_found',
                        ],
                        404,
                    );
                }
                return redirect($this->getBaseUrl() . '/payment-status?status=order_not_found');
            }

            $order->update([
                'status' => 'completed',
                'payment_id' => $request->razorpay_payment_id,
                'payment_status' => 'completed',
                'is_payment' => 1,
                'transaction_id' => $request->razorpay_payment_id,
            ]);

            // Create transaction record
            Transaction::create([
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'transaction_id' => $request->razorpay_payment_id,
                'amount' => $order->final_price,
                'payment_method' => 'razorpay',
                'status' => 'completed',
                'message' => 'Payment successful via Razorpay',
            ]);

            // Decrement promo code usage if promo code was used
            if ($order->promo_code_id) {
                \App\Models\PromoCode::where('id', $order->promo_code_id)
                    ->whereNotNull('no_of_users')
                    ->where('no_of_users', '>', 0)
                    ->decrement('no_of_users', 1);
            }

            // Decrement promo codes from order courses (cart orders)
            $usedPromoCodeIds = \App\Models\OrderCourse::where('order_id', $order->id)
                ->whereNotNull('promo_code_id')
                ->pluck('promo_code_id')
                ->unique();

            foreach ($usedPromoCodeIds as $promoCodeId) {
                \App\Models\PromoCode::where('id', $promoCodeId)
                    ->whereNotNull('no_of_users')
                    ->where('no_of_users', '>', 0)
                    ->decrement('no_of_users', 1);
            }

            // Create curriculum tracking entries for all curriculum items
            $order->load('user');
            if ($order->user) {
                \App\Services\OrderTrackingService::createCurriculumTrackingEntries($order, $order->user);
            }

            // Calculate and create commission records
            try {
                CommissionService::calculateCommissions($order);
                CommissionService::markCommissionsAsPaid($order);
                Log::info("Commission processing completed for Order: {$order->id}");
            } catch (\Exception $e) {
                Log::error("Commission processing failed for Order: {$order->id}", [
                    'error' => $e->getMessage(),
                ]);

                // Don't fail the entire payment process if commission calculation fails
            }

            // Cart clearing removed - cart should not be cleared for payment gateways

            // Dispatch notification for completed order
            if ($order->user) {
                dispatch(new \App\Jobs\SendOrderNotifications($order->fresh(), $order->user));
            }

            Log::info("Razorpay payment completed successfully for Order: {$order->id}");

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::successResponse('Payment completed successfully', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => 'completed',
                    'transaction_id' => $request->razorpay_payment_id,
                    'amount' => $order->final_price,
                    'redirect_url' => $this->getBaseUrl() . '/payment-status?status=completed',
                ]);
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=completed');
        } catch (SignatureVerificationError $e) {
            Log::error('Razorpay signature verification failed: ' . $e->getMessage());

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::errorResponse(
                    'Payment signature verification failed',
                    [
                        'status' => 'payment_failed',
                        'message' => 'Invalid payment signature',
                        'redirect_url' => $this->getBaseUrl() . '/payment-status?status=payment_failed',
                    ],
                    400,
                );
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=payment_failed');
        } catch (\Throwable $e) {
            Log::error('Razorpay callback error: ' . $e->getMessage(), [
                'order_number' => $request->order_number ?? null,
                'razorpay_payment_id' => $request->razorpay_payment_id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            ApiResponseService::logErrorResponse($e, 'Razorpay callback error');

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::errorResponse(
                    'Payment processing error occurred',
                    [
                        'status' => 'error',
                        'message' => config('app.debug')
                            ? $e->getMessage()
                            : 'An error occurred while processing payment',
                        'redirect_url' => $this->getBaseUrl() . '/payment-status?status=payment_failed',
                    ],
                    500,
                    $e,
                );
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=payment_failed');
        }
    }

    /**
     * Handle successful payment
     */
    public function handleSuccess(Request $request)
    {
        $orderNumber = $request->query('order_number');
        $paymentId = $request->query('payment_id');

        return view('payment.success', compact('orderNumber', 'paymentId'));
    }

    /**
     * Handle cancelled payment
     */
    public function handleCancel(Request $request)
    {
        $error = $request->query('error', 'Payment was cancelled');

        return view('payment.cancel', compact('error'));
    }
}
