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

        $isVerified = false;
        if ($request->isMethod('get')) {
            $transactionId = $data['transactionId'] ?? $data['transaction_id'] ?? $data['queryString']['transactionId'] ?? '';
            if (!empty($transactionId)) {
                $apiStatus = $this->kashierService->getPaymentStatus($transactionId);
                if ($apiStatus !== 'unknown') {
                    $isVerified = true;
                    $data['paymentStatus'] = $apiStatus; // Trust the API status
                }
            }
        } else {
            // Use the original nested payload for signature verification
            $isVerified = $this->kashierService->verifyPayment($payload);
        }

        $orderId = (string)($data['merchantOrderId'] ?? $data['merchant_order_id'] ?? $data['orderId'] ?? $data['order_id'] ?? '');
        $status = strtolower((string) ($data['paymentStatus'] ?? $data['status'] ?? $data['transactionStatus'] ?? ''));
        $isSuccess = in_array($status, ['success', 'completed', 'captured', 'paid'], true);

        if (!$isVerified) {
            Log::warning('Kashier webhook: signature/API verification failed', [
                'orderId' => $orderId,
                'transactionId' => $transactionId ?? 'none'
            ]);
            
            if ($request->isMethod('get')) {
                $redirectPath = '/plans';
                if (str_starts_with($orderId, 'wlt_')) {
                    $redirectPath = '/my-wallet';
                }
                
                return $this->respond($request, 'Redirecting...', 302, $isSuccess, $redirectPath);
            }
            return $this->respond($request, 'Invalid signature', 400, false);
        }

        $orderId = $data['merchantOrderId'] ?? $data['merchant_order_id'] ?? $data['orderId'] ?? $data['order_id'] ?? '';
        $status = strtolower((string) ($data['paymentStatus'] ?? $data['status'] ?? $data['transactionStatus'] ?? ''));

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

        $gatewayAmount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? $plan->price);
        $transactionId = $data['transactionId'] ?? $data['transaction_id'] ?? $orderId;

        // Retrieve pending wallet amount from cache (split payment)
        $pending = Cache::pull('kashier_pending_' . $orderId);
        $walletAmount = $pending['wallet_amount'] ?? 0;
        $totalAmount = $gatewayAmount + (float) $walletAmount;

        if (in_array($status, ['success', 'completed', 'captured', 'paid'], true)) {
            return $this->handleSuccess($request, $user, $plan, $walletAmount, $gatewayAmount, $transactionId, $data);
        }

        if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
            Log::info('Kashier webhook: payment failed', ['orderId' => $orderId, 'status' => $status]);
            return $this->respond($request, 'OK', 200, false);
        }

        Log::info('Kashier webhook: unhandled status', ['orderId' => $orderId, 'status' => $status]);
        return $this->respond($request, 'OK', 200, false);
    }

    private function handleSuccess(Request $request, User $user, SubscriptionPlan $plan, float $walletAmount, float $gatewayAmount, string $transactionId, array $data)
    {
        $existingPayment = SubscriptionPayment::where('transaction_id', $transactionId)->first();
        if ($existingPayment) {
            Log::info('Kashier webhook: payment already processed', ['transactionId' => $transactionId]);
            return $this->respond($request, 'OK', 200, true);
        }

        $paymentMethod = $walletAmount > 0 ? 'wallet_and_kashier' : 'kashier';

        try {
            DB::transaction(function () use ($user, $plan, $walletAmount, $gatewayAmount, $paymentMethod, $transactionId, $data) {
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
            });

            Log::info('Kashier webhook: subscription activated', [
                'userId' => $user->id,
                'planId' => $plan->id,
                'transactionId' => $transactionId,
            ]);

            return $this->respond($request, 'OK', 200, true);
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
        $amount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? $data['queryString']['transactionAmount'] ?? 0);
        $transactionId = (string) ($data['transactionId'] ?? $data['transaction_id'] ?? $data['queryString']['transactionId'] ?? $orderId);

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
            return $this->respond($request, 'Amount missing for processing', 200, $isSuccess);
        }

        if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
            Log::info('Kashier webhook: wallet top-up failed', ['orderId' => $orderId, 'status' => $status]);
            return $this->respond($request, 'OK', 200, false);
        }

        if (!in_array($status, ['success', 'completed', 'captured', 'paid'], true)) {
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
            return $this->respond($request, 'OK', 200, true);
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

        if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
            Log::info('Kashier webhook: webinar payment failed', ['orderId' => $orderId, 'status' => $status]);
            return $this->respond($request, 'OK', 200, false);
        }

        if (!in_array($status, ['success', 'completed', 'captured', 'paid'], true)) {
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
                $pending = Cache::pull('kashier_pending_' . $orderId);
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
            return $this->respond($request, 'OK', 200, true);

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
        // If it's a GET request, or a non-JSON POST request (like an HTML form redirect from Kashier), redirect the user
        if ($request->isMethod('get') || !$request->isJson()) {
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
}
