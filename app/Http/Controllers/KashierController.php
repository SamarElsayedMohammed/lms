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

        $this->kashierLog('Kashier webhook/redirect received', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'payload' => $payload,
            'data' => $data,
        ]);

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
            return $this->respond($request, 'OK', 200, true, null, $orderId);
        }

        $status = $this->resolveKashierStatus($data);
        $isSuccess = $this->isSuccessfulStatus($status);
        $transactionId = $this->extractTransactionId($data);

        // ALWAYS try to verify via API if we have a transactionId (most reliable method).
        if (!empty($transactionId)) {
            Log::info('Kashier: Verifying via API', ['transactionId' => $transactionId]);
            $apiData = $this->kashierService->getPaymentDetails($transactionId);
            $this->applyApiPaymentDetails($apiData, $orderId, $transactionId, $status, $isVerified, $isSuccess, $data);
        }

        // Some Kashier redirects only include merchant order id. Try an order-id lookup before failing.
        if (!$isVerified && !empty($orderId)) {
            Log::info('Kashier: Verifying via merchant order id', ['orderId' => $orderId]);
            $apiData = $this->kashierService->getPaymentDetailsByOrderId($orderId);
            $this->applyApiPaymentDetails($apiData, $orderId, $transactionId, $status, $isVerified, $isSuccess, $data);
        }

        // Fallback to signature verification if API check didn't happen or failed.
        if (!$isVerified) {
            $isVerified = $this->kashierService->verifyPayment($payload);

            if (!$isVerified && $request->isMethod('get')) {
                Log::info('Kashier: GET request without successful API/signature verification');
            }
        }

        if (!$isVerified && !$isSuccess) {
            $this->kashierLog('Kashier verification incomplete', [
                'orderId' => $orderId,
                'transactionId' => $transactionId,
                'status' => $status,
                'is_get' => $request->isMethod('get'),
            ]);

            Log::warning('Kashier webhook: Total verification failed', [
                'orderId' => $orderId,
                'transactionId' => $transactionId,
                'status' => $status,
            ]);

            if ($request->isMethod('get')) {
                $redirectPath = str_starts_with($orderId, 'wlt_') ? '/my-wallet' : '/plans';
                return $this->respond($request, 'Verification failed', 302, false, $redirectPath, $orderId);
            }

            return $this->respond($request, 'Invalid signature', 400, false, null, $orderId);
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
            return $this->handleSuccess($request, $orderId, $user, $plan, $walletAmount, $gatewayAmount, $transactionId, array_merge($data, [
                '_kashier_status_resolved' => $status,
                '_kashier_verified' => $isVerified,
            ]));
        }

        if ($this->isFailedStatus($status)) {
            Log::info('Kashier webhook: payment failed', ['orderId' => $orderId, 'status' => $status]);
            Cache::forget('kashier_pending_' . $orderId);
            
            // Notify user of failed payment
            try {
                $user->notify(new \App\Notifications\PaymentStatusNotification(
                    isSuccess: false,
                    itemName: 'اشتراك باقة ' . ($plan->name ?? ''),
                    amount: $gatewayAmount,
                    transactionId: $transactionId,
                    retryUrl: url('/plans')
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send payment failure notification: ' . $e->getMessage());
            }

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
            return $this->respond($request, 'OK', 200, true, null, $orderId);
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

            $this->kashierLog('Kashier subscription activated', [
                'orderId' => $orderId,
                'userId' => $user->id,
                'planId' => $plan->id,
                'subscriptionId' => $subscription->id,
                'transactionId' => $transactionId,
                'walletAmount' => $walletAmount,
                'gatewayAmount' => $gatewayAmount,
            ]);

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

            return $this->respond($request, 'OK', 200, true, null, $orderId);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->kashierLog('Kashier failed to create subscription', [
                'message' => $e->getMessage(),
                'userId' => $user->id,
                'planId' => $plan->id,
                'orderId' => $orderId,
                'transactionId' => $transactionId,
                'trace' => $e->getTraceAsString(),
            ]);

            Log::error('Kashier webhook: failed to create subscription', [
                'message' => $e->getMessage(),
                'userId' => $user->id,
                'planId' => $plan->id,
            ]);

            return $this->respond($request, 'Internal Server Error', 500, false, null, $orderId);
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
            
            // Notify user of failed payment
            try {
                $user->notify(new \App\Notifications\PaymentStatusNotification(
                    isSuccess: false,
                    itemName: 'شحن رصيد المحفظة',
                    amount: $amount,
                    transactionId: $transactionId,
                    retryUrl: url('/wallet/deposit')
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send payment failure notification: ' . $e->getMessage());
            }
            
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
            
            // Send successful payment notification
            try {
                $user->notify(new \App\Notifications\PaymentStatusNotification(
                    isSuccess: true,
                    itemName: 'شحن المحفظة (Wallet Top-up)',
                    amount: $amount,
                    transactionId: $transactionId,
                    invoiceUrl: url('/wallet')
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send wallet top-up success notification: ' . $e->getMessage());
            }
            
            return $this->respond($request, 'OK', 200, true, null, $orderId);
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
            
            // Notify user of failed payment
            try {
                $amount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? data_get($data, 'queryString.transactionAmount') ?? $webinar->price);
                $user->notify(new \App\Notifications\PaymentStatusNotification(
                    isSuccess: false,
                    itemName: 'حضور ويبينار ' . ($webinar->title ?? ''),
                    amount: $amount,
                    transactionId: $this->extractTransactionId($data) ?: $orderId,
                    retryUrl: url('/webinars/' . $webinar->slug)
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send webinar payment failure notification: ' . $e->getMessage());
            }

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

    private function respond(Request $request, string $message, int $statusCode, bool $isSuccess, string $redirectPath = null, string $orderId = '')
    {
        // Browser redirects are GET requests. Webhooks, including form-encoded POSTs, must receive plain responses.
        if ($request->isMethod('get')) {
            $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://skillso.net')), '/');
            
            if ($redirectPath === null) {
                $orderId = $orderId ?: (string) ($request->input('merchantOrderId') ?? $request->input('merchant_order_id') ?? $request->input('orderId') ?? $request->input('order_id') ?? '');
                if (str_starts_with($orderId, 'wlt_')) {
                    $redirectPath = '/my-wallet';
                } else {
                    $redirectPath = '/plans';
                }
            }
            
            $query = http_build_query(array_filter([
                'payment' => $isSuccess ? 'success' : 'failed',
                'order_id' => $orderId ?: null,
            ]));

            return redirect()->away($frontendUrl . $redirectPath . '?' . $query);
        }

        return response($message, $statusCode);
    }




    private function kashierLog(string $message, array $context = []): void
    {
        $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $written = false;

        try {
            $written = @file_put_contents(storage_path('logs/kashier.log'), $line, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            $written = false;
        }

        if (!$written) {
            @file_put_contents('/tmp/kashier.log', $line, FILE_APPEND | LOCK_EX);
            error_log($line);
        }
    }

    private function applyApiPaymentDetails(?array $apiData, string &$orderId, string &$transactionId, string &$status, bool &$isVerified, bool &$isSuccess, array &$data): void
    {
        if (!$apiData) {
            return;
        }

        $data = array_merge($data, $apiData);

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
            'error',
            'servererror',
        ], true);
    }

    /**
     * Extracts and saves credit card token from the payment gateway response if present.
     */
    private function saveCreditCardIfPresent(User $user, array $data): void
    {
        try {
            $this->kashierLog('Kashier starting saveCreditCardIfPresent', [
                'user_id' => $user->id,
                'data_keys' => array_keys($data),
                'cardData' => $data['cardData'] ?? $data['card_data'] ?? $data['card'] ?? null,
                'sourceOfFund' => $data['sourceOfFund'] ?? $data['source_of_fund'] ?? null,
                'paymentMethod' => $data['paymentMethod'] ?? $data['payment_method'] ?? null,
            ]);

            Log::info('Kashier starting saveCreditCardIfPresent', [
                'user_id' => $user->id,
                'cardData' => $data['cardData'] ?? null,
                'card_data' => $data['card_data'] ?? null,
                'card' => $data['card'] ?? null,
                'sourceOfFund' => $data['sourceOfFund'] ?? null,
                'paymentMethod' => $data['paymentMethod'] ?? null,
            ]);

            $cardData = $data['cardData']
                ?? $data['card_data']
                ?? $data['card']
                ?? $data['sourceOfFund']
                ?? $data['source_of_fund']
                ?? $data['sourceOfFunds']
                ?? $data['source_of_funds']
                ?? $data['paymentMethod']
                ?? $data['payment_method']
                ?? null;

            $flat = is_array($cardData) ? array_merge($data, $cardData) : $data;

            // Also merge any parsed data that might be present directly on $data
            $token = $this->firstFilled($flat, [
                'cardToken', 'card_token', 'token', 'gateway_token', 'paymentToken',
                'payment_token', 'cardId', 'card_id', 'savedCardToken', 'saved_card_token',
            ], recursive: true);

            $maskedCard = $this->firstFilled($flat, [
                'maskedCard', 'masked_card', 'maskedPan', 'masked_pan', 'cardNumber',
                'card_number', 'pan', 'accountNumber', 'account_number', 'card', 'number',
            ], recursive: true);

            $cardHolderName = $this->firstFilled($flat, [
                'cardHolderName', 'card_holder_name', 'cardHolder', 'card_holder',
                'holderName', 'holder_name', 'name', 'customerName',
            ], recursive: true);

            $expMonth = $this->firstFilled($flat, [
                'expiryMonth', 'expiry_month', 'expMonth', 'exp_month',
                'expirationMonth', 'expiration_month', 'month',
            ], recursive: true);

            $expYear = $this->firstFilled($flat, [
                'expiryYear', 'expiry_year', 'expYear', 'exp_year',
                'expirationYear', 'expiration_year', 'year',
            ], recursive: true);

            $brand = $this->firstFilled($flat, [
                'brand', 'cardBrand', 'card_brand', 'scheme', 'cardScheme', 'card_scheme',
                'paymentScheme', 'payment_scheme', 'type',
            ], recursive: true) ?: 'Unknown';

            $lastFour = $this->firstFilled($flat, [
                'last4', 'lastFour', 'last_four', 'lastFourDigits', 'last_four_digits',
            ], recursive: true);

            if (!$lastFour && preg_match('/(\d{4})(?!.*\d)/', (string) $maskedCard, $matches)) {
                $lastFour = $matches[1];
            }

            // Fallback: If we couldn't find last four or masked card, try to extract from ANY string that looks like a masked pan in the data
            if (!$lastFour) {
                array_walk_recursive($flat, function($value) use (&$lastFour) {
                    if (!$lastFour && is_string($value) && preg_match('/^[xX*]{4,}\s*-?\s*(\d{4})$/', $value, $m)) {
                        $lastFour = $m[1];
                    }
                });
            }

            $lastFour = preg_replace('/\D/', '', (string) $lastFour);
            if (strlen($lastFour) > 4) {
                $lastFour = substr($lastFour, -4);
            }

            if (strlen($lastFour) !== 4) {
                $this->kashierLog('Kashier card was not saved: missing last four digits', [
                    'user_id' => $user->id,
                    'available_keys' => array_keys($flat),
                    'extracted_last_four' => $lastFour,
                    'extracted_token' => $token,
                ]);
                
                Log::warning('Kashier card was not saved: missing last four digits', [
                    'user_id' => $user->id,
                    'extracted_last_four' => $lastFour,
                ]);
                return;
            }

            $expMonth = preg_replace('/\D/', '', (string) $expMonth);
            $expMonth = $expMonth !== '' ? str_pad(substr($expMonth, -2), 2, '0', STR_PAD_LEFT) : null;

            $expYear = preg_replace('/\D/', '', (string) $expYear);
            if (strlen($expYear) === 2) {
                $expYear = '20' . $expYear;
            }
            $expYear = strlen($expYear) === 4 ? $expYear : null;

            $brand = ucfirst(strtolower((string) $brand));
            
            $this->kashierLog('Kashier extracted card info', [
                'user_id' => $user->id,
                'lastFour' => $lastFour,
                'expMonth' => $expMonth,
                'expYear' => $expYear,
                'brand' => $brand,
                'token' => $token,
            ]);
            
            Log::info('Kashier extracted card info', [
                'user_id' => $user->id,
                'lastFour' => $lastFour,
                'expMonth' => $expMonth,
                'expYear' => $expYear,
                'brand' => $brand,
            ]);

            $fingerprint = hash('sha256', implode('|', [
                'kashier',
                $user->id,
                $lastFour,
                $expMonth ?: 'xx',
                $expYear ?: 'xxxx',
                strtolower($brand),
            ]));
            $token = $token ?: 'kashier_fingerprint_' . $fingerprint;

            $existingCard = $user->creditCards()
                ->where(function ($query) use ($token, $lastFour, $expMonth, $expYear, $brand) {
                    $query->where('gateway_token', $token)
                        ->orWhere(function ($cardQuery) use ($lastFour, $expMonth, $expYear, $brand) {
                            $cardQuery->where('last_four_digits', $lastFour)
                                ->when($expMonth, fn ($q) => $q->where('exp_month', $expMonth))
                                ->when($expYear, fn ($q) => $q->where('exp_year', $expYear))
                                ->when($brand !== 'Unknown', fn ($q) => $q->where('brand', $brand));
                        });
                })
                ->first();

            if ($existingCard) {
                $existingCard->update([
                    'card_holder_name' => $cardHolderName ?: $existingCard->card_holder_name,
                    'brand' => $brand ?: $existingCard->brand,
                    'exp_month' => $expMonth ?: $existingCard->exp_month,
                    'exp_year' => $expYear ?: $existingCard->exp_year,
                    'gateway_token' => $token,
                    'is_default' => true,
                ]);
                $user->creditCards()->where('id', '!=', $existingCard->id)->update(['is_default' => false]);
                return;
            }

            $user->creditCards()->update(['is_default' => false]);

            $user->creditCards()->create([
                'card_holder_name' => $cardHolderName ?: null,
                'last_four_digits' => $lastFour,
                'brand' => $brand,
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
                'gateway_token' => $token,
                'is_default' => true,
            ]);

            $this->kashierLog('Kashier user credit card saved', [
                'user_id' => $user->id,
                'last_four' => $lastFour,
                'brand' => $brand,
                'has_gateway_token' => !str_starts_with($token, 'kashier_fingerprint_'),
            ]);

            Log::info('Kashier webhook: Successfully saved user credit card', [
                'user_id' => $user->id,
                'last_four' => $lastFour,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->kashierLog('Kashier failed to auto-save credit card', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            Log::error('Kashier webhook: Failed to auto-save credit card', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function firstFilled(array $data, array $keys, bool $recursive = false): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if ($value !== null && $value !== '' && !is_array($value)) {
                return $value;
            }
        }

        if (!$recursive) {
            return null;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            $found = $this->firstFilled($value, $keys, true);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
    }
}
