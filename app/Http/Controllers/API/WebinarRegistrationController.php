<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\WebinarRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebinarRegistrationController extends Controller
{
    protected WebinarRegistrationService $registrationService;

    public function __construct(WebinarRegistrationService $registrationService)
    {
        $this->registrationService = $registrationService;
    }

    /**
     * Register for a webinar (Free or initiates gateway)
     * POST /api/webinars/{slug}/register
     */
    public function register(Request $request, Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            // Paid webinar via Gateway flow
            if (!$webinar->is_free && $webinar->price > 0) {
                // Calculate localized pricing from server authority
                $pricingService = app(\App\Services\PricingCalculationService::class);
                $currencyInfo = $pricingService->resolveDisplayCurrency($user, $request);
                $exchangeRate = $currencyInfo['exchange_rate'] ?? 1.0;
                $currency = $currencyInfo['code'] ?? 'EGP';

                $totalAmount = round($webinar->price * $exchangeRate, 2);
                
                try {
                    $kashierService = app(\App\Services\Payment\KashierCheckoutService::class);
                    $checkout = $kashierService->createWebinarCheckoutSession($webinar->id, $user, $totalAmount, $currency);
                } catch (\RuntimeException $e) {
                    return ApiResponseService::errorResponse('Payment gateway is not configured.', [], 503);
                }

                \Illuminate\Support\Facades\Cache::put('kashier_pending_' . $checkout['order_id'], [
                    'wallet_amount' => 0,
                    'webinar_id' => $webinar->id,
                    'user_id' => $user->id,
                    'expected_amount' => $totalAmount,
                    'currency' => $currency,
                    'created_at' => now()->toIso8601String(),
                ], 3600); // 1 hour TTL

                // Reserve temporary seat with 1 hour expiration
                $this->registrationService->register(
                    $webinar,
                    $user,
                    'pending',
                    0.00,
                    now()->addHour()
                );

                return ApiResponseService::successResponse('Please complete payment via Kashier.', [
                    'requires_checkout' => true,
                    'checkout_url' => $checkout['url'],
                    'order_id' => $checkout['order_id'],
                    'payment' => [
                        'total_amount' => $totalAmount,
                        'wallet_amount' => 0,
                        'gateway_amount' => $totalAmount,
                        'currency' => $currency,
                    ],
                ]);
            }

            // Free webinar registration
            $this->registrationService->register($webinar, $user, 'free', 0.00);

            return ApiResponseService::successResponse('Successfully registered for the webinar.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return ApiResponseService::errorResponse($e->getMessage(), ['error_code' => 'conflict'], 409);
            }
            if ($code === 404) {
                return ApiResponseService::errorResponse($e->getMessage(), ['error_code' => 'not_found'], 404);
            }
            if ($code === 400) {
                return ApiResponseService::errorResponse($e->getMessage(), ['error_code' => 'bad_request'], 400);
            }
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Cancel registration for a webinar
     * DELETE /api/webinars/{slug}/register
     */
    public function cancelRegistration(Request $request, Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration) {
                return ApiResponseService::errorResponse('Not registered for this webinar.', [], 400);
            }

            // Guard: If already refunded, return 400 Bad Request
            if ($registration->payment_status === 'refunded') {
                return ApiResponseService::errorResponse(
                    'تم استرداد رسوم هذا التسجيل مسبقاً ولا يمكن إلغاؤه مرة أخرى.',
                    ['error_code' => 'already_refunded'],
                    400
                );
            }

            // Guard: If already cancelled, return 400 Bad Request
            if ($registration->payment_status === 'cancelled') {
                return ApiResponseService::errorResponse(
                    'التسجيل ملغى مسبقاً.',
                    ['error_code' => 'already_cancelled'],
                    400
                );
            }

            // If paid registration: process atomic refund to wallet if webinar has not started
            if ($registration->payment_status === 'paid') {
                if ($webinar->start_at && $webinar->start_at->isPast()) {
                    return ApiResponseService::errorResponse(
                        'لا يمكن إلغاء التسجيل واسترداد المبلغ بعد بدء الندوة.',
                        ['error_code' => 'webinar_already_started'],
                        400
                    );
                }

                \Illuminate\Support\Facades\DB::transaction(function () use ($user, $webinar, $registration) {
                    $lockedReg = WebinarRegistration::where('id', $registration->id)->lockForUpdate()->first();
                    if ($lockedReg && $lockedReg->payment_status === 'paid') {
                        $refundAmount = (float) $lockedReg->paid_amount;
                        if ($refundAmount > 0) {
                            \App\Services\WalletService::creditWallet(
                                $user->id,
                                $refundAmount,
                                'refund',
                                "استرداد رسوم التسجيل في ندوة: {$webinar->title}",
                                $webinar->id,
                                Webinar::class,
                                'user'
                            );
                        }
                        $lockedReg->update([
                            'payment_status' => 'refunded',
                        ]);
                    }
                });

                return ApiResponseService::successResponse('تم إلغاء التسجيل واسترداد المبلغ إلى المحفظة بنجاح.');
            }

            // For non-paid / free registrations, transition to cancelled to preserve audit history
            $registration->update([
                'payment_status' => 'cancelled',
            ]);

            return ApiResponseService::successResponse('تم إلغاء التسجيل بنجاح.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to cancel registration: ' . $e->getMessage());
        }
    }
}
