<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\Payment\WalletPaymentIntegrationService;
use App\Services\WebinarAccessService;
use App\Services\WebinarRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletRegistrationController extends Controller
{
    protected WebinarRegistrationService $registrationService;
    protected WalletPaymentIntegrationService $walletIntegrationService;
    protected WebinarAccessService $accessService;

    public function __construct(
        WebinarRegistrationService $registrationService,
        WalletPaymentIntegrationService $walletIntegrationService,
        WebinarAccessService $accessService
    ) {
        $this->registrationService = $registrationService;
        $this->walletIntegrationService = $walletIntegrationService;
        $this->accessService = $accessService;
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

            $alreadyRegistered = WebinarRegistration::query()
                ->where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->get()
                ->first(static fn (WebinarRegistration $row): bool => $row->isConfirmed());

            if ($alreadyRegistered) {
                return response()->json([
                    'success' => false,
                    'message' => 'أنت مسجّل بالفعل في هذا الويبنار.',
                    'error_code' => 'already_registered',
                ], 409);
            }

            // Preliminary check using canonical access service
            $check = $this->accessService->canRegister($webinar, $user);
            if (!$check['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => $check['reason'],
                    'error_code' => $check['error_code'],
                ], $check['code']);
            }

            $transaction = DB::transaction(function () use ($user, $webinar) {
                $confirmed = WebinarRegistration::query()
                    ->where('user_id', $user->id)
                    ->where('webinar_id', $webinar->id)
                    ->lockForUpdate()
                    ->get()
                    ->first(static fn (WebinarRegistration $row): bool => $row->isConfirmed());

                if ($confirmed) {
                    throw new \Exception('already_registered', 409);
                }

                $transaction = null;
                if (!$webinar->is_free && $webinar->price > 0) {
                    $transaction = $this->walletIntegrationService->payForWebinar($user, $webinar);
                }

                $this->registrationService->register(
                    $webinar,
                    $user,
                    $webinar->is_free || $webinar->price <= 0 ? 'free' : 'paid',
                    $webinar->is_free ? 0.00 : (float) $webinar->price
                );

                return $transaction;
            });

            return response()->json([
                'success' => true,
                'transaction_id' => is_object($transaction) ? $transaction->id : null,
                'message' => 'Successfully registered for the webinar using wallet.'
            ], 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'أنت مسجّل بالفعل في هذا الويبنار.',
                    'error_code' => 'already_registered',
                ], 409);
            }
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            $code = (int) $e->getCode();
            $msg = $e->getMessage();

            if ($code === 409 || $msg === 'webinar_is_full' || $msg === 'already_registered' || str_contains($msg, 'already registered')) {
                return response()->json([
                    'success' => false,
                    'message' => $msg === 'already_registered' ? 'أنت مسجّل بالفعل في هذا الويبنار.' : $msg,
                    'error_code' => 'already_registered',
                ], 409);
            }
            if ($code === 400 || $msg === 'insufficient_funds') {
                return response()->json(['success' => false, 'message' => $msg], 400);
            }
            if ($code === 404) {
                return response()->json(['success' => false, 'message' => $msg], 404);
            }
            return response()->json(['success' => false, 'message' => $msg], 500);
        }
    }
}
