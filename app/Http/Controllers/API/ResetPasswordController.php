<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\EmailPasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResetPasswordController extends Controller
{
    private const GENERIC_FORGOT_MESSAGE = 'If this email exists, a verification code has been sent.';

    public function __construct(
        private readonly EmailPasswordResetService $emailPasswordResetService,
    ) {}

    public function forgotPassword(Request $request)
    {
        try {
            ApiService::validateRequest($request, [
                'email' => 'required|email|max:255',
            ]);

            $user = $this->emailPasswordResetService->findEligibleUser($request->email);

            if ($user !== null) {
                $this->emailPasswordResetService->sendOtp($user);
            } else {
                Log::info('Password reset requested for unknown or ineligible email', [
                    'email' => strtolower(trim((string) $request->email)),
                ]);
            }

            ApiResponseService::successResponse(self::GENERIC_FORGOT_MESSAGE);
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function verifyResetCode(Request $request)
    {
        try {
            ApiService::validateRequest($request, [
                'email' => 'required|email|max:255',
                'code' => 'required|string|size:' . EmailPasswordResetService::OTP_LENGTH,
            ]);

            if (!$this->emailPasswordResetService->verifyOtp($request->email, $request->code)) {
                ApiResponseService::validationError('Invalid or expired verification code');
            }

            ApiResponseService::successResponse('Verification code is valid', [
                'verified' => true,
                'expires_in_seconds' => $this->emailPasswordResetService->remainingSeconds($request->email),
            ]);
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            ApiService::validateRequest($request, [
                'email' => 'required|email|max:255',
                'code' => 'required|string|size:' . EmailPasswordResetService::OTP_LENGTH,
                'password' => 'required|string|min:6|confirmed',
            ]);

            if (!$this->emailPasswordResetService->resetPassword(
                $request->email,
                $request->code,
                $request->password,
            )) {
                ApiResponseService::validationError('Invalid or expired verification code');
            }

            ApiResponseService::successResponse('Password reset successfully');
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }
}
