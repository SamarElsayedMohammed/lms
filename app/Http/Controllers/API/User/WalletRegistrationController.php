<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\Payment\WalletPaymentIntegrationService;
use App\Services\WebinarRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletRegistrationController extends Controller
{
    protected WebinarRegistrationService $registrationService;
    protected WalletPaymentIntegrationService $walletIntegrationService;

    public function __construct(
        WebinarRegistrationService $registrationService,
        WalletPaymentIntegrationService $walletIntegrationService
    ) {
        $this->registrationService = $registrationService;
        $this->walletIntegrationService = $walletIntegrationService;
    }

    /**
     * Wallet Register
     * POST /api/user/wallet/webinars/{slug}/register
     */
    public function register(Request $request, Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            if (!$request->boolean('use_wallet')) {
                return ApiResponseService::errorResponse('use_wallet parameter must be true for this endpoint.', [], 400);
            }

            $existing = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'You are already registered for this webinar.',
                    'transaction_id' => null,
                ]);
            }

            $transaction = DB::transaction(function () use ($user, $webinar) {
                $transaction = $this->walletIntegrationService->payForWebinar($user, $webinar);
                $this->registrationService->register(
                    $webinar,
                    $user,
                    $webinar->is_free || $webinar->price <= 0 ? 'free' : 'paid',
                    $webinar->is_free ? 0.00 : $webinar->price,
                );
                return $transaction;
            });

            return response()->json([
                'success' => true,
                'transaction_id' => is_object($transaction) ? $transaction->id : null,
                'message' => 'Successfully registered for the webinar using wallet.'
            ], 200);
            
        } catch (\Exception $e) {
            if ($e->getCode() === 409 && $e->getMessage() === 'webinar_is_full') {
                return response()->json(['message' => 'webinar_is_full'], 409);
            }
            if ($e->getCode() === 409 && $e->getMessage() === 'already_registered') {
                return response()->json(['message' => 'already_registered'], 409);
            }
            if ($e->getCode() === 400 && $e->getMessage() === 'insufficient_funds') {
                return response()->json(['message' => 'insufficient_funds'], 400);
            }
            if ($e->getCode() === 400) {
                return response()->json(['message' => $e->getMessage()], 400);
            }
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage());
        }
    }
}
