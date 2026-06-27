<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\Payment\KashierCheckoutService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class KashierController extends Controller
{
    public function __construct(
        private readonly KashierCheckoutService $kashierService,
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Handle Kashier payment callback (redirect after payment or webhook).
     * Supports both GET (redirect) and POST (webhook).
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        $data = $payload;
        
        // Flatten data for easier access to common fields
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = array_merge($payload, $payload['data']);
        }

        Log::info('Kashier webhook/redirect received', [
            'method' => $request->method(),
            'data' => $data
        ]);

        // Initialize variables to prevent undefined variable errors
        $isVerified = false;
        
        // Ensure orderId is always extracted (from query string, payload, or API details).
        $orderId = $this->extractOrderId($request, $data);
        
        // If it's a GET request and we already successfully processed this via webhook, just redirect to success.
        if ($request->isMethod('get') && !empty($orderId) && Cache::get('kashier_order_processed_' . $orderId)) {
            Log::info('Kashier GET redirect: order already processed via webhook', ['orderId' => $orderId]);
            return $this->respond($request, 'OK', 200, true);
        }

        $status = $this->resolveKashierStatus($data);
        $isSuccess = $this->isSuccessfulStatus($status);
        $transactionId = $this->extractTransactionId($data);

        // ALWAYS try to verify via API if we have a transactionId (most reliable method).
        if (!empty($transactionId)) {
            Log::info('Kashier: Verifying via API', ['transactionId' => $transactionId]);
            $apiData = $this->kashierService->getPaymentDetails($transactionId);
            $this->applyApiPaymentDetails($apiData, $orderId, $transactionId, $status, $isVerified, $isSuccess);
        }

        // Some Kashier redirects only include merchant order id. Try an order-id lookup before failing.
        if (!$isVerified && !empty($orderId)) {
            Log::info('Kashier: Verifying via merchant order id', ['orderId' => $orderId]);
            $apiData = $this->kashierService->getPaymentDetailsByOrderId($orderId);
            $this->applyApiPaymentDetails($apiData, $orderId, $transactionId, $status, $isVerified, $isSuccess);
        }

        // Fallback to signature verification if API check didn't happen or failed.
        if (!$isVerified) {
            $isVerified = $this->kashierService->verifyPayment($payload);

            if (!$isVerified && $request->isMethod('get')) {
                Log::info('Kashier: GET request without successful API/signature verification');
            }
        }

        if (!$isVerified && !$isSuccess) {
            Log::warning('Kashier webhook: Total verification failed', [
                'orderId' => $orderId,
                'transactionId' => $transactionId
            ]);
            
            if ($request->isMethod('get')) {
                $redirectPath = str_starts_with($orderId, 'wlt_') ? '/my-wallet' : '/plans';
                return $this->respond($request, 'Verification failed', 302, false, $redirectPath);
            }
            return $this->respond($request, 'Invalid signature', 400, false);
        }

        if (empty($orderId)) {
            Log::warning('Kashier webhook: empty orderId');
            return $this->respond($request, 'Invalid order', 400, false);
        }

        // Wallet top-up (T095)
        if (str_starts_with($orderId, 'wlt_')) {
            return $this->handleWalletTopUp($request, $orderId, $status, $data);
        }

        // Webinar registration
        if (str_starts_with($orderId, 'webinar_')) {
            return $this->handleWebinarPayment($request, $orderId, $status, $data);
        }

        // Subscription payment
        if (!str_starts_with($orderId, 'sub_')) {
            Log::warning('Kashier webhook: invalid orderId', ['orderId' => $orderId]);
            return $this->respond($request, 'Invalid order', 400, false);
        }

        $parts = explode('_', $orderId);
        if (count($parts) < 4) {
            Log::warning('Kashier webhook: cannot parse orderId', ['orderId' => $orderId]);
            return $this->respond($request, 'Invalid order format', 400, false);
        }

        $planId = (int) $parts[1];
        $userId = (int) $parts[2];

        $plan = SubscriptionPlan::find($planId);
        $user = User::find($userId);

        if (!$plan || !$user) {
            Log::warning('Kashier webhook: plan or user not found', ['planId' => $planId, 'userId' => $userId]);
            return $this->respond($request, 'Order not found', 404, false);
        }

        $gatewayAmount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? data_get($data, 'queryString.transactionAmount') ?? $plan->price);
        $transactionId = $this->extractTransactionId($data) ?: $orderId;

        // Retrieve pending wallet amount from cache (split payment)
        $pending = Cache::get('kashier_pending_' . $orderId);
        $walletAmount = $pending['wallet_amount'] ?? 0;
        $totalAmount = $gatewayAmount + (float) $walletAmount;

        if ($this->isSuccessfulStatus($status)) {
            return $this->handleSuccess($request, $orderId, $user, $plan, $walletAmount, $gatewayAmount, $transactionId, $data);
        }

        if ($this->isFailedStatus($status)) {
            Log::info('Kashier webhook: payment failed', ['orderId' => $orderId, 'status' => $status]);
            Cache::forget('kashier_pending_' . $orderId);
            return $this->respond($request, 'OK', 200, false);
        }

        Log::info('Kashier webhook: unhandled status', ['orderId' => $orderId, 'status' => $status]);
        return $this->respond($request, 'OK', 200, false);
    }

    private function handleSuccess(Request $request, string $orderId, User $user, SubscriptionPlan $plan, float $walletAmount, float $gatewayAmount, string $transactionId, array $data)
    {
        $existingPayment = SubscriptionPayment::where('transaction_id', $transactionId)->first();
        if ($existingPayment) {
            Log::info('Kashier webhook: payment already processed', ['transactionId' => $transactionId]);
            if (!empty($orderId)) {
                Cache::put('kashier_order_processed_' . $orderId, true, now()->addMinutes(60));
                Cache::forget('kashier_pending_' . $orderId);
            }
            return $this->respond($request, 'OK', 200, true);
        }

        $paymentMethod = $walletAmount > 0 ? 'wallet_and_kashier' : 'kashier';

        try {
            $subscription = DB::transaction(function () use ($user, $plan, $walletAmount, $gatewayAmount, $paymentMethod, $transactionId, $data) {
                $subscription = $this->subscriptionService->createSubscription(
                    $user,
                    $plan,
                    $paymentMethod,
                    $walletAmount,
                    $gatewayAmount
                );

                $payment = $subscription->payments()->latest()->first();
                if ($payment) {
                    $payment->update([
                        'transaction_id' => $transactionId,
                        'gateway_response' => $data,
                    ]);
                }
                
                return $subscription;
            });

            // Send notification to user
            try {
                $user->notify(new \App\Notifications\SubscriptionActivatedNotification($subscription->loadMissing('plan')));
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Failed to send subscription notification: ' . $e->getMessage());
            }

            Log::info('Kashier webhook: subscription activated', [
                'userId' => $user->id,
                'planId' => $plan->id,
                'transactionId' => $transactionId,
            ]);

            // Attempt to save credit card token if available
            $this->saveCreditCardIfPresent($user, $data);

            if (!empty($orderId)) {
                Cache::put('kashier_order_processed_' . $orderId, true, now()->addMinutes(60));
                Cache::forget('kashier_pending_' . $orderId);
            }

            return $this->respond($request, 'OK', 200, true);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: failed to create subscription', [
                'message' => $e->getMessage(),
                'userId' => $user->id,
                'planId' => $plan->id,
            ]);

            return $this->respond($request, 'Internal Server Error', 500, false);
        }
    }

    private function handleWalletTopUp(Request $request, string $orderId, string $status, array $data)
    {
        $parts = explode('_', $orderId);
        if (count($parts) < 3) {
            Log::warning('Kashier webhook: invalid wallet orderId', ['orderId' => $orderId]);
            return $this->respond($request, 'Invalid order', 400, false);
        }

        $userId = (int) $parts[1];
        $user = User::find($userId);
        if (!$user) {
            Log::warning('Kashier webhook: user not found for wallet top-up', ['userId' => $userId]);
            return $this->respond($request, 'Order not found', 404, false);
        }

        // Extract amount - support multiple keys from Kashier
        $amount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? data_get($data, 'queryString.transactionAmount') ?? 0);
        $transactionId = $this->extractTransactionId($data) ?: $orderId;

        // FALLBACK: If amount is missing (common in redirects), fetch it from Kashier API
        if ($amount <= 0 && !empty($transactionId) && $transactionId !== $orderId) {
            Log::info('Kashier handleWalletTopUp: Amount missing, fetching from API', ['transactionId' => $transactionId]);
            $apiData = $this->kashierService->getPaymentDetails($transactionId);
            if ($apiData && isset($apiData['amount'])) {
                $amount = (float) $apiData['amount'];
                Log::info('Kashier handleWalletTopUp: Amount recovered from API', ['amount' => $amount]);
            }
        }

        if ($amount <= 0) {
            Log::warning('Kashier webhook: invalid wallet top-up amount', [
                'orderId' => $orderId, 
                'amount' => $amount,
                'transactionId' => $transactionId,
                'is_get' => $request->isMethod('get')
            ]);
            // If it's a redirect, we might still want to show success UI if the status is success, 
            // even if the balance update happens via webhook.
            return $this->respond($request, 'Amount missing for processing', 200, $this->isSuccessfulStatus($status));
        }

        if ($this->isFailedStatus($status)) {
            Log::info('Kashier webhook: wallet top-up failed', ['orderId' => $orderId, 'status' => $status]);
            return $this->respond($request, 'OK', 200, false);
        }

        if (!$this->isSuccessfulStatus($status)) {
            return $this->respond($request, 'OK', 200, false);
        }

        // Idempotency: check if already processed
        $existing = \App\Models\WalletHistory::where('reference_type', 'wallet_topup')
            ->where('reference_id', $transactionId)
            ->exists();
        if ($existing) {
            Log::info('Kashier webhook: wallet top-up already processed', ['orderId' => $orderId]);
            return $this->respond($request, 'OK', 200, true);
        }

        try {
            WalletService::creditWallet(
                $userId,
                $amount,
                'wallet_topup',
                'Wallet top-up via Kashier',
                $transactionId,
                'wallet_topup',
                'user'
            );
            Log::info('Kashier webhook: wallet top-up completed', ['userId' => $userId, 'amount' => $amount]);
            
            // Attempt to save credit card token if available
            $this->saveCreditCardIfPresent($user, $data);
            
            Cache::put('kashier_order_processed_' . $orderId, true, now()->addMinutes(60));
            
            return $this->respond($request, 'OK', 200, true);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: wallet top-up failed', ['message' => $e->getMessage(), 'orderId' => $orderId]);
            return $this->respond($request, 'Internal Server Error', 500, false);
        }
    }

    private function handleWebinarPayment(Request $request, string $orderId, string $status, array $data)
    {
        $parts = explode('_', $orderId);
        if (count($parts) < 3) {
            Log::warning('Kashier webhook: invalid webinar orderId', ['orderId' => $orderId]);
            return $this->respond($request, 'Invalid order format', 400, false);
        }

        $webinarId = (int) $parts[1];
        $userId = (int) $parts[2];

        $user = User::find($userId);
        $webinar = \App\Models\Webinar::find($webinarId);

        if (!$user || !$webinar) {
            Log::warning('Kashier webhook: user or webinar not found', ['userId' => $userId, 'webinarId' => $webinarId]);
            return $this->respond($request, 'User or Webinar not found', 404, false);
        }

        if ($this->isFailedStatus($status)) {
            Log::info('Kashier webhook: webinar payment failed', ['orderId' => $orderId, 'status' => $status]);
            return $this->respond($request, 'OK', 200, false);
        }

        if (!$this->isSuccessfulStatus($status)) {
            return $this->respond($request, 'OK', 200, false);
        }

        // Get registration
        $registration = \App\Models\WebinarRegistration::where('user_id', $userId)
            ->where('webinar_id', $webinarId)
            ->first();

        if (!$registration) {
            Log::warning('Kashier webhook: webinar registration not found', ['userId' => $userId, 'webinarId' => $webinarId]);
            return $this->respond($request, 'Registration not found', 404, false);
        }

        if ($registration->payment_status === 'paid') {
            Log::info('Kashier webhook: webinar already paid', ['orderId' => $orderId]);
            return $this->respond($request, 'OK', 200, true);
        }

        try {
            DB::transaction(function () use ($registration, $user, $webinar, $orderId) {
                // Deduct wallet if it was a split payment
                $pending = Cache::get('kashier_pending_' . $orderId);
                $walletAmount = (float) ($pending['wallet_amount'] ?? 0);

                if ($walletAmount > 0 && $user->wallet_balance >= $walletAmount) {
                    \App\Services\WalletService::debitWallet(
                        $user->id,
                        $walletAmount,
                        'webinar_payment',
                        'Paid part of webinar via wallet: ' . $webinar->title,
                        (string) $webinar->id,
                        'webinar'
                    );
                }

                $registration->update(['payment_status' => 'paid']);
            });

            $user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar));

            Log::info('Kashier webhook: webinar payment completed', ['userId' => $userId, 'webinarId' => $webinarId]);
            
            // Attempt to save credit card token if available
            $this->saveCreditCardIfPresent($user, $data);

            Cache::put('kashier_order_processed_' . $orderId, true, now()->addMinutes(60));
            Cache::forget('kashier_pending_' . $orderId);

            return $this->respond($request, 'OK', 200, true);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: failed to activate webinar registration', [
                'message' => $e->getMessage(),
                'orderId' => $orderId,
            ]);
            return $this->respond($request, 'Internal Server Error', 500, false);
        }
    }

    private function respond(Request $request, string $message, int $statusCode, bool $isSuccess, string $redirectPath = null)
    {
        // Browser redirects are GET requests. Webhooks, including form-encoded POSTs, must receive plain responses.
        if ($request->isMethod('get')) {
            $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://skillso.net')), '/');
            
            if ($redirectPath === null) {
                $orderId = $request->input('merchantOrderId') ?? $request->input('merchant_order_id') ?? $request->input('orderId') ?? $request->input('order_id') ?? '';
                if (str_starts_with($orderId, 'wlt_')) {
                    $redirectPath = '/my-wallet';
                } else {
                    $redirectPath = '/plans';
                }
            }
            
            if ($isSuccess) {
                return redirect()->away($frontendUrl . $redirectPath . '?payment=success');
            }
            return redirect()->away($frontendUrl . $redirectPath . '?payment=failed');
        }

        return response($message, $statusCode);
    }



    private function applyApiPaymentDetails(?array $apiData, string &$orderId, string &$transactionId, string &$status, bool &$isVerified, bool &$isSuccess): void
    {
        if (!$apiData) {
            return;
        }

        if (!empty($apiData['order_id']) && empty($orderId)) {
            $orderId = (string) $apiData['order_id'];
        }

        if (!empty($apiData['transaction_id']) && empty($transactionId)) {
            $transactionId = (string) $apiData['transaction_id'];
        }

        $apiStatus = $this->normalizeKashierStatus((string) ($apiData['status'] ?? 'unknown'));
        if ($this->isSuccessfulStatus($apiStatus)) {
            $isVerified = true;
            $status = $apiStatus;
            $isSuccess = true;
            Log::info('Kashier: API verification successful', [
                'orderId' => $orderId,
                'transactionId' => $transactionId,
                'status' => $status,
            ]);
        }
    }

    private function extractOrderId(Request $request, array $data): string
    {
        return (string) (
            $data['merchantOrderId']
            ?? $data['merchant_order_id']
            ?? $data['orderId']
            ?? $data['order_id']
            ?? data_get($data, 'queryString.merchantOrderId')
            ?? data_get($data, 'queryString.orderId')
            ?? $request->input('merchantOrderId')
            ?? $request->input('merchant_order_id')
            ?? $request->input('orderId')
            ?? $request->input('order_id')
            ?? ''
        );
    }

    private function extractTransactionId(array $data): string
    {
        return (string) (
            $data['transactionId']
            ?? $data['transaction_id']
            ?? $data['paymentId']
            ?? $data['payment_id']
            ?? data_get($data, 'queryString.transactionId')
            ?? data_get($data, 'queryString.paymentId')
            ?? ''
        );
    }


    private function resolveKashierStatus(array $data): string
    {
        $rawStatus = $data['paymentStatus']
            ?? $data['payment_status']
            ?? $data['transactionStatus']
            ?? $data['transaction_status']
            ?? data_get($data, 'transaction.status')
            ?? data_get($data, 'payment.status')
            ?? data_get($data, 'response.status')
            ?? data_get($data, 'result.status')
            ?? data_get($data, 'queryString.paymentStatus')
            ?? data_get($data, 'queryString.payment_status')
            ?? data_get($data, 'queryString.transactionStatus')
            ?? data_get($data, 'queryString.status')
            ?? $data['status']
            ?? null;

        $status = $this->normalizeKashierStatus((string) ($rawStatus ?? 'unknown'));
        if ($this->isSuccessfulStatus($status) || $this->isFailedStatus($status)) {
            return $status;
        }

        $successFlag = $data['success']
            ?? $data['isSuccess']
            ?? $data['is_success']
            ?? data_get($data, 'queryString.success')
            ?? data_get($data, 'queryString.isSuccess')
            ?? null;

        if ($this->isTruthy($successFlag)) {
            return 'success';
        }

        $responseCode = (string) (
            $data['responseCode']
            ?? $data['response_code']
            ?? $data['code']
            ?? data_get($data, 'response.code')
            ?? data_get($data, 'queryString.responseCode')
            ?? ''
        );

        if (in_array(trim($responseCode), ['0', '00', '000', '200'], true)) {
            return 'success';
        }

        return $status;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'success', 'successful', 'succeeded'], true);
        }

        return false;
    }

    private function normalizeKashierStatus(string $status): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $status)));
    }

    private function isSuccessfulStatus(string $status): bool
    {
        return in_array($this->normalizeKashierStatus($status), [
            'success',
            'successful',
            'succeeded',
            'completed',
            'complete',
            'captured',
            'capture',
            'paid',
            'approved',
            'authorized',
            'authorised',
        ], true);
    }

    private function isFailedStatus(string $status): bool
    {
        return in_array($this->normalizeKashierStatus($status), [
            'failed',
            'failure',
            'rejected',
            'cancelled',
            'canceled',
            'declined',
            'voided',
            'expired',
        ], true);
    }

    /**
     * Extracts and saves credit card token from the payment gateway response if present.
     */
    private function saveCreditCardIfPresent(User $user, array $data): void
    {
        try {
            // Find card data in Kashier payload structure
            $cardData = $data['cardData'] ?? $data['card_data'] ?? $data['card'] ?? null;
            $token = $data['cardToken'] ?? $data['card_token'] ?? $data['token'] ?? null;

            if (is_array($cardData)) {
                $token = $token ?? $cardData['cardToken'] ?? $cardData['card_token'] ?? $cardData['token'] ?? null;
                $maskedCard = $cardData['maskedCard'] ?? $cardData['masked_card'] ?? $cardData['cardNumber'] ?? '';
                $cardHolderName = $cardData['cardHolderName'] ?? $cardData['card_holder_name'] ?? $cardData['cardHolder'] ?? '';
                $expMonth = $cardData['expiryMonth'] ?? $cardData['exp_month'] ?? $cardData['expirationMonth'] ?? '';
                $expYear = $cardData['expiryYear'] ?? $cardData['exp_year'] ?? $cardData['expirationYear'] ?? '';
                $brand = $cardData['brand'] ?? $cardData['cardBrand'] ?? 'Unknown';
            } else {
                // Flat structure fallback
                $maskedCard = $data['maskedCard'] ?? $data['masked_card'] ?? $data['cardNumber'] ?? '';
                $cardHolderName = $data['cardHolderName'] ?? $data['card_holder_name'] ?? $data['cardHolder'] ?? '';
                $expMonth = $data['expiryMonth'] ?? $data['exp_month'] ?? '';
                $expYear = $data['expiryYear'] ?? $data['exp_year'] ?? '';
                $brand = $data['brand'] ?? $data['cardBrand'] ?? 'Unknown';
            }

            if (!$token) {
                // If there's no token, we cannot save it for future use securely
                return;
            }

            // Extract the last 4 digits from the masked card
            $lastFour = '0000';
            if (preg_match('/(\d{4})$/', (string) $maskedCard, $matches)) {
                $lastFour = $matches[1];
            }

            // Clean up month/year
            $expMonth = str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT);
            $expYear = (string) $expYear;
            if (strlen($expYear) == 2) {
                $expYear = '20' . $expYear;
            }

            // Check if card already exists for this user by token or last four
            $existingCard = $user->creditCards()->where('gateway_token', $token)
                ->orWhere(function($query) use ($user, $lastFour, $expMonth, $expYear) {
                    $query->where('user_id', $user->id)
                          ->where('last_four_digits', $lastFour)
                          ->where('exp_month', $expMonth)
                          ->where('exp_year', $expYear);
                })->first();

            if ($existingCard) {
                // If it exists, update token in case it changed, and make it default
                $existingCard->update([
                    'gateway_token' => $token,
                    'is_default' => true
                ]);
                // Ensure others are not default
                $user->creditCards()->where('id', '!=', $existingCard->id)->update(['is_default' => false]);
                return;
            }

            // Ensure others are not default
            $user->creditCards()->update(['is_default' => false]);

            // Save the new card
            $user->creditCards()->create([
                'card_holder_name' => $cardHolderName,
                'last_four_digits' => $lastFour,
                'brand' => $brand,
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
                'gateway_token' => $token,
                'is_default' => true,
            ]);

            Log::info('Kashier webhook: Successfully saved user credit card token', [
                'user_id' => $user->id,
                'last_four' => $lastFour
            ]);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: Failed to auto-save credit card', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
