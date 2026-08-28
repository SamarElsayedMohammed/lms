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

            // Paid webinars use wallet only (no cart / no Kashier checkout from this endpoint).
            if (!$webinar->is_free && $webinar->price > 0) {
                if (!$user) {
                    return ApiResponseService::errorResponse('Unauthorized.', [], 401);
                }
                return ApiResponseService::errorResponse(
                    'التسجيل في الويبنار المدفوع يتم عبر رصيد المحفظة فقط.',
                    ['error_code' => 'wallet_required'],
                    400
                );
            }

            $formResponses = is_array($request->input('form_responses'))
                ? $request->input('form_responses')
                : $request->except(['_token', 'use_wallet', 'utm_source', 'password', 'password_confirmation']);
            $utmSource = $request->input('utm_source');

            $registrant = $this->registrationService->resolveRegistrant($user, is_array($formResponses) ? $formResponses : []);

            $this->registrationService->register($webinar, $registrant, 'free', 0.00, null, is_array($formResponses) ? $formResponses : [], $utmSource);

            return ApiResponseService::successResponse('Successfully registered for the webinar.', [
                'redirect_url' => "/webinar/{$webinar->slug}/thank-you",
                'slug'         => $webinar->slug,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponseService::errorResponse(
                $e->getMessage(),
                $e->errors(),
                422
            );
        } catch (\App\Services\WebinarRegistrationDeniedException $e) {
            return ApiResponseService::errorResponse(
                $e->getMessage(),
                ['error_code' => $e->errorCode],
                $e->getCode() ?: 400
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 422) {
                return ApiResponseService::errorResponse($e->getMessage(), ['error_code' => 'validation_error'], 422);
            }
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
                                $lockedReg->wallet_transaction_id ?: "legacy-webinar-registration-{$lockedReg->id}",
                                'webinar_registration_refund',
                                'user'
                            );
                        }
                        // Keep the unique registration row as an expired pending record.
                        // A future purchase reuses it and stores a new wallet transaction,
                        // while the refund remains idempotently tied to the original debit.
                        $lockedReg->update([
                            'payment_status' => 'pending',
                            'paid_amount' => 0,
                            'expires_at' => now()->subSecond(),
                        ]);
                    }
                });

                return ApiResponseService::successResponse('تم إلغاء التسجيل واسترداد المبلغ إلى المحفظة بنجاح.');
            }

            $registration->delete();

            return ApiResponseService::successResponse('تم إلغاء التسجيل بنجاح.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to cancel registration: ' . $e->getMessage());
        }
    }
}
