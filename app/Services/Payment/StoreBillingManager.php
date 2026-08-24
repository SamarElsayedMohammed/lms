<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Payment\DTO\StorePurchaseResult;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StoreBillingManager
{
    public function __construct(
        private readonly AppleStoreBillingService $appleService,
        private readonly GooglePlayBillingService $googleService,
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Resolve Skillso SubscriptionPlan from Store Product ID
     */
    public function resolvePlan(string $platform, string $storeProductId): ?SubscriptionPlan
    {
        $cleanProductId = strtolower(trim($storeProductId));
        $normalizedPlatform = strtolower(trim($platform));
        $productMap = (array) config('store_billing.product_map', []);

        foreach ($productMap as $cycle => $mapping) {
            $appleId = strtolower((string) ($mapping['apple_product_id'] ?? ''));
            $googleId = strtolower((string) ($mapping['google_product_id'] ?? ''));
            $planSlug = (string) ($mapping['plan_slug'] ?? $cycle);

            if (
                $cleanProductId === $appleId ||
                $cleanProductId === $googleId ||
                $cleanProductId === strtolower($planSlug) ||
                str_contains($cleanProductId, $cycle)
            ) {
                // Find active plan by slug or billing_cycle
                $plan = SubscriptionPlan::where('slug', $planSlug)
                    ->orWhere('billing_cycle', $cycle)
                    ->where('is_active', true)
                    ->first();

                if ($plan) {
                    return $plan;
                }
            }
        }

        // Fallback matching by billing_cycle if not explicitly found in map
        $cycles = ['monthly', 'quarterly', 'semi_annual', 'yearly'];
        foreach ($cycles as $cycle) {
            if (str_contains($cleanProductId, str_replace('_', '', $cycle)) || str_contains($cleanProductId, $cycle)) {
                $plan = SubscriptionPlan::where('billing_cycle', $cycle)->where('is_active', true)->first();
                if ($plan) {
                    return $plan;
                }
            }
        }

        return null;
    }

    /**
     * Get configured store product ID for a given Skillso plan
     */
    public function getStoreProductId(SubscriptionPlan $plan, string $platform): string
    {
        $cycle = $plan->billing_cycle ?? $plan->slug ?? 'monthly';
        $normalizedPlatform = in_array(strtolower($platform), ['apple', 'app_store', 'appstore', 'ios'], true) ? 'apple' : 'google';
        $mapping = config("store_billing.product_map.{$cycle}");

        if ($mapping) {
            $key = $normalizedPlatform === 'apple' ? 'apple_product_id' : 'google_product_id';
            if (!empty($mapping[$key])) {
                return (string) $mapping[$key];
            }
        }

        return "skillso_{$cycle}_sub";
    }

    /**
     * Verify store purchase proof, guard against cross-account theft, enforce idempotency, and activate canonical subscription.
     *
     * @param User $user Authenticated Skillso user
     * @param string $platform 'app_store' | 'google_play'
     * @param array<string, mixed> $proof Minimal client proof
     * @return array{success: bool, error_code?: string, message: string, status_code: int, result?: StorePurchaseResult, subscription?: Subscription}
     */
    public function verifyAndActivatePurchase(User $user, string $platform, array $proof): array
    {
        $normalizedPlatform = in_array(strtolower($platform), ['apple', 'app_store', 'appstore', 'ios'], true)
            ? StoreTransaction::STORE_APPLE
            : StoreTransaction::STORE_GOOGLE;

        // 1. Execute platform-specific store verification
        $verificationResult = $normalizedPlatform === StoreTransaction::STORE_APPLE
            ? $this->appleService->verify($proof, $user)
            : $this->googleService->verify($proof, $user);

        if (!$verificationResult->isVerified || $verificationResult->errorMessage !== null) {
            Log::warning('Store purchase verification rejected by store service', [
                'user_id' => $user->id,
                'platform' => $normalizedPlatform,
                'error' => $verificationResult->errorMessage,
            ]);

            return [
                'success' => false,
                'error_code' => 'store_verification_failed',
                'message' => $verificationResult->errorMessage ?? 'فشل التحقق من صحة عملية الشراء مع المتجر.',
                'status_code' => 422,
                'result' => $verificationResult,
            ];
        }

        // 2. Validate product mapping to Skillso Plan
        $plan = $this->resolvePlan($normalizedPlatform, $verificationResult->storeProductId);
        if (!$plan) {
            Log::error('Store product not mapped to any active Skillso plan', [
                'user_id' => $user->id,
                'store_product_id' => $verificationResult->storeProductId,
                'platform' => $normalizedPlatform,
            ]);

            return [
                'success' => false,
                'error_code' => 'unknown_store_product',
                'message' => 'لم يتم العثور على باقة اشتراك مطابقة لمنتج المتجر.',
                'status_code' => 422,
                'result' => $verificationResult,
            ];
        }

        // 3. Check for expired or revoked state
        if ($verificationResult->isRevoked) {
            return [
                'success' => false,
                'error_code' => 'revoked_purchase',
                'message' => 'تم إلغاء أو استرداد عملية الشراء هذه من قبل المتجر.',
                'status_code' => 422,
                'result' => $verificationResult,
            ];
        }

        if ($verificationResult->isExpired()) {
            return [
                'success' => false,
                'error_code' => 'expired_purchase',
                'message' => 'انتهت صلاحية هذا الاشتراك في المتجر.',
                'status_code' => 422,
                'result' => $verificationResult,
            ];
        }

        $tokenHash = StoreTransaction::hashToken($verificationResult->purchaseToken);

        // 4. DB Transaction: Cross-Account Protection (P0) & Durable Idempotency (P0) & Activation
        return DB::transaction(function () use ($user, $normalizedPlatform, $verificationResult, $plan, $tokenHash) {
            // Lock relevant store transaction rows for update
            $existingTx = StoreTransaction::where('store', $normalizedPlatform)
                ->where(function ($q) use ($verificationResult, $tokenHash) {
                    $q->where('transaction_id', $verificationResult->transactionId);
                    if (!empty($verificationResult->originalTransactionId)) {
                        $q->orWhere('original_transaction_id', $verificationResult->originalTransactionId);
                    }
                    if ($tokenHash !== null) {
                        $q->orWhere('purchase_token_hash', $tokenHash);
                    }
                })
                ->lockForUpdate()
                ->first();

            // P0: Cross-Account Purchase Theft Protection
            if ($existingTx && $existingTx->user_id !== $user->id) {
                Log::alert('SECURITY ALERT: Cross-account store purchase claim attempt blocked', [
                    'attempted_by_user_id' => $user->id,
                    'original_owner_user_id' => $existingTx->user_id,
                    'store' => $normalizedPlatform,
                    'transaction_id' => $verificationResult->transactionId,
                    'original_transaction_id' => $verificationResult->originalTransactionId,
                ]);

                return [
                    'success' => false,
                    'error_code' => 'transaction_already_owned',
                    'message' => 'هذا الاشتراك مرتبط بحساب آخر بالفعل.',
                    'status_code' => 409,
                    'result' => $verificationResult,
                ];
            }

            // P0: Durable Idempotency
            // If the exact transaction_id was already processed for this user and linked to an active subscription
            $exactTx = StoreTransaction::where('store', $normalizedPlatform)
                ->where('transaction_id', $verificationResult->transactionId)
                ->first();

            if ($exactTx && $exactTx->user_id === $user->id && $exactTx->subscription_id) {
                $existingSubscription = Subscription::find($exactTx->subscription_id);
                if ($existingSubscription && $existingSubscription->is_active) {
                    Log::info('Store purchase verification idempotent replay for user', [
                        'user_id' => $user->id,
                        'store' => $normalizedPlatform,
                        'transaction_id' => $verificationResult->transactionId,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'تم التحقق من الاشتراك بنجاح (معاملة مسجلة مسبقاً).',
                        'status_code' => 200,
                        'result' => $verificationResult,
                        'subscription' => $existingSubscription,
                    ];
                }
            }

            // 5. Persist Store Transaction Record
            $storeTx = StoreTransaction::updateOrCreate(
                [
                    'store' => $normalizedPlatform,
                    'transaction_id' => $verificationResult->transactionId,
                ],
                [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'environment' => $verificationResult->environment,
                    'store_product_id' => $verificationResult->storeProductId,
                    'original_transaction_id' => $verificationResult->originalTransactionId,
                    'purchase_token' => $verificationResult->purchaseToken,
                    'purchase_token_hash' => $tokenHash,
                    'status' => $verificationResult->status,
                    'purchased_at' => $verificationResult->purchasedAt,
                    'expires_at' => $verificationResult->expiresAt,
                    'auto_renew' => $verificationResult->autoRenew,
                    'is_verified' => true,
                    'is_revoked' => $verificationResult->isRevoked,
                    'is_refunded' => $verificationResult->isRefunded,
                    'amount' => $verificationResult->amount,
                    'currency' => $verificationResult->currency,
                    'raw_payload' => $verificationResult->rawPayload,
                ]
            );

            // 6. Canonical Subscription Activation via SubscriptionService
            $subscription = $this->subscriptionService->activateVerifiedStoreSubscription(
                $user,
                $plan,
                $verificationResult,
                $storeTx
            );

            return [
                'success' => true,
                'message' => 'تم تفعيل اشتراكك بنجاح.',
                'status_code' => 200,
                'result' => $verificationResult,
                'subscription' => $subscription,
            ];
        });
    }
}
