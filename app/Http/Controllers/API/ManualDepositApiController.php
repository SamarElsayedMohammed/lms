<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\ManualDepositMethod;
use App\Models\User;
use App\Notifications\AdminNewManualDepositNotification;
use App\Services\ApiResponseService;
use App\Services\FileService;
use App\Services\GeoLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ManualDepositApiController extends Controller
{
    private $receiptFolder = 'manual_deposits/receipts';
    private $geoLocationService;

    public function __construct(GeoLocationService $geoLocationService)
    {
        $this->geoLocationService = $geoLocationService;
    }

    /**
     * Get active manual deposit methods for user
     */
    public function getMethods(Request $request)
    {
        $user = Auth::user();
        $countryCode = $user?->country_code ?? $this->geoLocationService->getCountryCodeFromRequest($request);

        $query = ManualDepositMethod::where('is_active', true);

        $query = ManualDepositMethod::where('is_active', true);

        $methods = $query->get();
        return ApiResponseService::successResponse('Manual deposit methods retrieved successfully', $methods);
    }

    /**
     * Submit a manual deposit request
     */
    public function submitDeposit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|exists:manual_deposit_methods,id',
            'amount' => 'required|numeric|min:1',
            'transaction_id' => 'nullable|string|max:255',
            'receipt' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        
        $hasPending = ManualDeposit::where('user_id', $user->id)
            ->where('manual_deposit_method_id', $request->method_id)
            ->where('status', 'pending')
            ->exists();
        
        if ($hasPending) {
            return ApiResponseService::errorResponse('You already have a pending deposit request. Please wait for it to be processed.');
        }

        $method = ManualDepositMethod::find($request->method_id);
        if (!$method || !$method->is_active) {
            return ApiResponseService::errorResponse('This payment method is not available.');
        }

        try {
            $countryCode = $user->country_code ?? app(\App\Services\GeoLocationService::class)->getCountryCodeFromRequest($request);
            $pricingService = app(\App\Services\PricingService::class);
            $currencyObj = $pricingService->getCurrencyForCountry($countryCode);
            $currencyCode = $currencyObj ? $currencyObj->currency_code : 'EGP';
            
            $currencyConversionService = app(\App\Services\CurrencyConversionService::class);
            $amountEgp = $currencyConversionService->convertToEgp($request->amount, $currencyCode);
            $exchangeRate = $currencyConversionService->getExchangeRateToEgp($currencyCode);

            $receiptPath = null;
            if ($request->hasFile('receipt')) {
                $receiptPath = FileService::compressAndUpload($request->file('receipt'), $this->receiptFolder, 'public');
            }

            $deposit = ManualDeposit::create([
                'user_id' => $user->id,
                'manual_deposit_method_id' => $request->method_id,
                'amount' => $request->amount,
                'amount_egp' => $amountEgp,
                'exchange_rate_snapshot' => $exchangeRate,
                'currency_code' => $currencyCode,
                'transaction_id' => $request->transaction_id,
                'receipt' => $receiptPath,
                'status' => 'pending',
            ]);

            // Notify all super-admins about the new manual deposit request
            try {
                $deposit->load('method');
                $admins = User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->get();
                foreach ($admins as $admin) {
                    $admin->notify(new AdminNewManualDepositNotification($deposit, $user));
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('ManualDepositApiController: Failed to notify admins of new manual deposit request', [
                    'deposit_id' => $deposit->id,
                    'user_id'    => $user->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            return ApiResponseService::successResponse('Deposit request submitted successfully. It will be processed after verification.', $deposit);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to submit deposit request: ' . $e->getMessage());
        }
    }

    /**
     * Get user's manual deposit history
     */
    public function getMyDeposits(Request $request)
    {
        $user = Auth::user();
        $perPage = min((int) $request->input('per_page', 15), 50);
        
        $deposits = ManualDeposit::with('method')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return ApiResponseService::successResponse('Manual deposits history retrieved successfully', $deposits);
    }
}
