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

            // Check if full/exists handled inside service, but we also handle payment logic here
            if (!$webinar->is_free && $webinar->price > 0) {
                // For non-wallet, direct gateway payment
                $totalAmount = $webinar->price;
                
                try {
                    $kashierService = app(\App\Services\Payment\KashierCheckoutService::class);
                    $checkout = $kashierService->createWebinarCheckoutSession($webinar->id, $user, $totalAmount);
                } catch (\RuntimeException $e) {
                    return ApiResponseService::errorResponse('Payment gateway is not configured.', [], 503);
                }

                \Illuminate\Support\Facades\Cache::put('kashier_pending_' . $checkout['order_id'], [
                    'wallet_amount' => 0,
                    'webinar_id' => $webinar->id,
                    'user_id' => $user->id,
                ], 3600); // 1 hour TTL

                // Use service to register as pending
                $this->registrationService->register($webinar, $user, 'pending', 0.00);

                return ApiResponseService::successResponse('Please complete payment via Kashier.', [
                    'requires_checkout' => true,
                    'checkout_url' => $checkout['url'],
                    'order_id' => $checkout['order_id'],
                    'payment' => [
                        'total_amount' => $totalAmount,
                        'wallet_amount' => 0,
                        'gateway_amount' => $totalAmount,
                    ],
                ]);
            }

            // Free webinar registration
            $this->registrationService->register($webinar, $user, 'free', 0.00);

            return ApiResponseService::successResponse('Successfully registered for the webinar.');
        } catch (\Exception $e) {
            if ($e->getCode() === 409 && $e->getMessage() === 'webinar_is_full') {
                return ApiResponseService::errorResponse('This webinar is full. No more registrations allowed.', [], 409);
            }
            if ($e->getCode() === 409 && $e->getMessage() === 'already_registered') {
                return ApiResponseService::successResponse('You are already registered for this webinar.');
            }
            if ($e->getCode() === 400) {
                return ApiResponseService::errorResponse($e->getMessage(), [], 400);
            }
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage());
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

            $registration->delete();

            return ApiResponseService::successResponse('Registration cancelled successfully.');
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to cancel registration: ' . $e->getMessage());
        }
    }
}
