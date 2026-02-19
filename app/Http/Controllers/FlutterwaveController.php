<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\ApiResponseService;
use App\Services\CommissionService;
use App\Services\HelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handle Flutterwave payment callbacks.
 * This is called by Flutterwave after a payment attempt.
 * URL: /flutterwave-callback
 */
class FlutterwaveController extends Controller
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
     * Handle Flutterwave payment success callback.
     * This is called by Flutterwave after a successful payment.
     * URL: /flutterwave-callback
     */
    public function handleFlutterwaveCallback(Request $request)
    {
        $txRef = $request->query('tx_ref');
        $transactionId = $request->query('transaction_id');
        $status = $request->query('status');
        $orderNumber = $request->query('order');
        $type = $request->query('type', 'web'); // Get type parameter, default to 'web'

        Log::info('Flutterwave Callback Received:', [
            'tx_ref' => $txRef,
            'transaction_id' => $transactionId,
            'status' => $status,
            'order' => $orderNumber,
            'type' => $type,
        ]);

        try {
            // Verify the transaction with Flutterwave
            $verificationResult = $this->verifyFlutterwaveTransaction($txRef, $transactionId);

            if (!$verificationResult['success']) {
                Log::error('Flutterwave transaction verification failed:', $verificationResult);

                // Check type for response format
                if ($type === 'app') {
                    return ApiResponseService::errorResponse(
                        'Transaction verification failed',
                        [
                            'status' => 'verification_failed',
                            'message' => $verificationResult['message'] ?? 'Failed to verify transaction',
                            'redirect_url' => $this->getBaseUrl() . '/payment-status?status=verification_failed',
                        ],
                        400,
                    );
                }

                return redirect($this->getBaseUrl() . '/payment-status?status=verification_failed');
            }

            $transactionData = $verificationResult['data'];

            // Find order by order_number or tx_ref
            $order = null;
            if ($orderNumber) {
                $order = Order::with('orderCourses')->where('order_number', $orderNumber)->first();
            }
            if (!$order && $txRef) {
                // Extract order number from tx_ref (format: TXN_order_number_timestamp)
                $txRefParts = explode('_', $txRef);
                if (count($txRefParts) >= 2) {
                    $orderNumber = $txRefParts[1];
                    $order = Order::with('orderCourses')->where('order_number', $orderNumber)->first();
                }
            }

            if (!$order) {
                Log::error('Order not found for Flutterwave callback:', [
                    'tx_ref' => $txRef,
                    'order_number' => $orderNumber,
                ]);

                // Check type for response format
                if ($type === 'app') {
                    return ApiResponseService::errorResponse(
                        'Order not found',
                        [
                            'status' => 'order_not_found',
                            'tx_ref' => $txRef,
                            'order_number' => $orderNumber,
                            'redirect_url' => $this->getBaseUrl() . '/payment-status?status=order_not_found',
                        ],
                        404,
                    );
                }

                return redirect($this->getBaseUrl() . '/payment-status?status=order_not_found');
            }

            // Check if payment was successful
            if ($transactionData['status'] === 'successful' && $transactionData['amount'] >= $order->final_price) {
                // Update order status
                $order->update([
                    'status' => 'completed',
                    'is_payment' => 1,
                    'transaction_id' => $transactionId,
                ]);

                // Create transaction record
                Transaction::create([
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                    'amount' => $transactionData['amount'],
                    'payment_method' => 'flutterwave',
                    'status' => 'completed',
                    'message' => 'Payment successful via Flutterwave',
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
                $order->load('user');
                if ($order->user) {
                    dispatch(new \App\Jobs\SendOrderNotifications($order->fresh(), $order->user));
                }

                Log::info("Flutterwave payment completed successfully for Order: {$order->id}");

                // Check type for response format
                if ($type === 'app') {
                    return ApiResponseService::successResponse('Payment completed successfully', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => 'completed',
                        'transaction_id' => $transactionId,
                        'amount' => $transactionData['amount'],
                        'redirect_url' => $this->getBaseUrl() . '/payment-status?status=completed',
                    ]);
                }

                return redirect($this->getBaseUrl() . '/payment-status?status=completed');
            } else {
                // Payment failed or amount mismatch
                $order->update([
                    'status' => 'failed',
                ]);

                Log::warning("Flutterwave payment failed for Order: {$order->id}", [
                    'transaction_status' => $transactionData['status'],
                    'transaction_amount' => $transactionData['amount'],
                    'order_amount' => $order->final_price,
                ]);

                // Check type for response format
                if ($type === 'app') {
                    return ApiResponseService::errorResponse(
                        'Payment failed',
                        [
                            'status' => 'payment_failed',
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'transaction_status' => $transactionData['status'],
                            'transaction_amount' => $transactionData['amount'],
                            'order_amount' => $order->final_price,
                            'redirect_url' => $this->getBaseUrl() . '/payment-status?status=payment_failed',
                        ],
                        400,
                    );
                }

                return redirect($this->getBaseUrl() . '/payment-status?status=payment_failed');
            }
        } catch (\Throwable $e) {
            Log::error('Flutterwave callback error: ' . $e->getMessage(), [
                'tx_ref' => $txRef,
                'transaction_id' => $transactionId,
                'order' => $orderNumber,
                'trace' => $e->getTraceAsString(),
            ]);
            ApiResponseService::logErrorResponse($e, 'Flutterwave callback error');

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::errorResponse(
                    'Payment processing error occurred',
                    [
                        'status' => 'error',
                        'message' => config('app.debug')
                            ? $e->getMessage()
                            : 'An error occurred while processing payment',
                        'redirect_url' => $this->getBaseUrl() . '/payment-status?status=error',
                    ],
                    500,
                    $e,
                );
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=error');
        }
    }

    /**
     * Verify Flutterwave transaction
     */
    private function verifyFlutterwaveTransaction($txRef, $transactionId)
    {
        try {
            // Get Flutterwave settings
            $flutterwaveSettings = HelperService::systemSettings([
                'flutterwave_secret_key',
                'flutterwave_status',
            ]);

            if (empty($flutterwaveSettings['flutterwave_status']) || $flutterwaveSettings['flutterwave_status'] != 1) {
                return ['success' => false, 'message' => 'Flutterwave is not enabled'];
            }

            if (empty($flutterwaveSettings['flutterwave_secret_key'])) {
                return ['success' => false, 'message' => 'Flutterwave secret key not configured'];
            }

            // Make API call to verify transaction
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $flutterwaveSettings['flutterwave_secret_key'],
                'Content-Type' => 'application/json',
            ])->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to verify transaction with Flutterwave'];
            }

            $responseData = $response->json();

            if ($responseData['status'] !== 'success') {
                return [
                    'success' => false,
                    'message' => 'Flutterwave verification failed: ' . ($responseData['message'] ?? 'Unknown error'),
                ];
            }

            $data = $responseData['data'];

            // Verify tx_ref matches
            if ($data['tx_ref'] !== $txRef) {
                return ['success' => false, 'message' => 'Transaction reference mismatch'];
            }

            return [
                'success' => true,
                'data' => [
                    'status' => $data['status'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'tx_ref' => $data['tx_ref'],
                    'customer' => $data['customer'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave transaction verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Verification error: ' . $e->getMessage()];
        }
    }

    /**
     * Handle Flutterwave payment cancellation.
     * URL: /flutterwave-cancel (if needed)
     */
    public function handleFlutterwaveCancel(Request $request)
    {
        $type = $request->query('type', 'web'); // Get type parameter, default to 'web'

        try {
            $orderNumber = $request->query('order');
            $order = null;

            if ($orderNumber) {
                $order = Order::where('order_number', $orderNumber)->first();
                if ($order) {
                    $order->update([
                        'status' => 'cancelled',
                    ]);
                }
            }

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::successResponse('Payment cancelled', [
                    'status' => 'cancelled',
                    'order_id' => $order ? $order->id : null,
                    'order_number' => $orderNumber,
                    'redirect_url' => $this->getBaseUrl() . '/payment-status?status=cancelled',
                ]);
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=cancelled');
        } catch (\Throwable $e) {
            Log::error('Flutterwave cancel error: ' . $e->getMessage());
            ApiResponseService::logErrorResponse($e, 'Flutterwave cancel error');

            // Check type for response format
            if ($type === 'app') {
                return ApiResponseService::errorResponse(
                    'Error processing cancellation',
                    [
                        'status' => 'error',
                        'message' => config('app.debug')
                            ? $e->getMessage()
                            : 'An error occurred while processing cancellation',
                        'redirect_url' => $this->getBaseUrl() . '/payment-status?status=error',
                    ],
                    500,
                    $e,
                );
            }

            return redirect($this->getBaseUrl() . '/payment-status?status=error');
        }
    }
}
