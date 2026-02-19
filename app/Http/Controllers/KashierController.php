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
    public function handleWebhook(Request $request): Response
    {
        $data = $request->all();

        if (empty($data)) {
            Log::warning('Kashier webhook: empty payload');
            return response('Bad Request', 400);
        }

        if (!$this->kashierService->verifyPayment($data)) {
            Log::warning('Kashier webhook: signature verification failed');
            return response('Invalid signature', 400);
        }

        $orderId = $data['orderId'] ?? $data['order_id'] ?? '';
        $status = strtolower((string) ($data['status'] ?? $data['transactionStatus'] ?? ''));

        if (empty($orderId)) {
            Log::warning('Kashier webhook: empty orderId');
            return response('Invalid order', 400);
        }

        // Wallet top-up (T095)
        if (str_starts_with($orderId, 'wlt_')) {
            return $this->handleWalletTopUp($orderId, $status, $data);
        }

        // Subscription payment
        if (!str_starts_with($orderId, 'sub_')) {
            Log::warning('Kashier webhook: invalid orderId', ['orderId' => $orderId]);
            return response('Invalid order', 400);
        }

        $parts = explode('_', $orderId);
        if (count($parts) < 4) {
            Log::warning('Kashier webhook: cannot parse orderId', ['orderId' => $orderId]);
            return response('Invalid order format', 400);
        }

        $planId = (int) $parts[1];
        $userId = (int) $parts[2];

        $plan = SubscriptionPlan::find($planId);
        $user = User::find($userId);

        if (!$plan || !$user) {
            Log::warning('Kashier webhook: plan or user not found', ['planId' => $planId, 'userId' => $userId]);
            return response('Order not found', 404);
        }

        $gatewayAmount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? $plan->price);
        $transactionId = $data['transactionId'] ?? $data['transaction_id'] ?? $orderId;

        // Retrieve pending wallet amount from cache (split payment)
        $pending = Cache::pull('kashier_pending_' . $orderId);
        $walletAmount = $pending['wallet_amount'] ?? 0;
        $totalAmount = $gatewayAmount + (float) $walletAmount;

        if (in_array($status, ['success', 'completed', 'captured', 'paid'], true)) {
            return $this->handleSuccess($user, $plan, $walletAmount, $gatewayAmount, $transactionId, $data);
        }

        if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
            Log::info('Kashier webhook: payment failed', ['orderId' => $orderId, 'status' => $status]);
            return response('OK', 200);
        }

        Log::info('Kashier webhook: unhandled status', ['orderId' => $orderId, 'status' => $status]);
        return response('OK', 200);
    }

    private function handleSuccess(User $user, SubscriptionPlan $plan, float $walletAmount, float $gatewayAmount, string $transactionId, array $data): Response
    {
        $existingSubscription = $this->subscriptionService->getActiveSubscription($user);
        if ($existingSubscription) {
            Log::info('Kashier webhook: user already has active subscription', ['userId' => $user->id]);
            return response('OK', 200);
        }

        $existingPayment = SubscriptionPayment::where('transaction_id', $transactionId)->first();
        if ($existingPayment) {
            Log::info('Kashier webhook: payment already processed', ['transactionId' => $transactionId]);
            return response('OK', 200);
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

            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: failed to create subscription', [
                'message' => $e->getMessage(),
                'userId' => $user->id,
                'planId' => $plan->id,
            ]);

            return response('Internal Server Error', 500);
        }
    }

    private function handleWalletTopUp(string $orderId, string $status, array $data): Response
    {
        $parts = explode('_', $orderId);
        if (count($parts) < 3) {
            Log::warning('Kashier webhook: invalid wallet orderId', ['orderId' => $orderId]);
            return response('Invalid order', 400);
        }

        $userId = (int) $parts[1];
        $user = User::find($userId);
        if (!$user) {
            Log::warning('Kashier webhook: user not found for wallet top-up', ['userId' => $userId]);
            return response('Order not found', 404);
        }

        $amount = (float) ($data['amount'] ?? $data['transactionAmount'] ?? 0);
        $transactionId = $data['transactionId'] ?? $data['transaction_id'] ?? $orderId;

        if ($amount <= 0) {
            Log::warning('Kashier webhook: invalid wallet top-up amount', ['orderId' => $orderId, 'amount' => $amount]);
            return response('Invalid amount', 400);
        }

        if (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
            Log::info('Kashier webhook: wallet top-up failed', ['orderId' => $orderId, 'status' => $status]);
            return response('OK', 200);
        }

        if (!in_array($status, ['success', 'completed', 'captured', 'paid'], true)) {
            return response('OK', 200);
        }

        // Idempotency: check if already processed
        $existing = \App\Models\WalletHistory::where('reference_type', 'wallet_topup')
            ->where('reference_id', $transactionId)
            ->exists();
        if ($existing) {
            Log::info('Kashier webhook: wallet top-up already processed', ['orderId' => $orderId]);
            return response('OK', 200);
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
            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('Kashier webhook: wallet top-up failed', ['message' => $e->getMessage(), 'orderId' => $orderId]);
            return response('Internal Server Error', 500);
        }
    }
}
