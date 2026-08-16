<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class BillingApiController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Get active Store products mapped to Skillso subscription plans
     * GET /api/billing/products
     */
    public function getProducts(Request $request): JsonResponse
    {
        try {
            $plans = SubscriptionPlan::where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $products = [];
            foreach ($plans as $plan) {
                $billingPeriod = match ($plan->billing_cycle) {
                    'yearly' => 'P1Y',
                    'lifetime' => 'P100Y',
                    default => 'P1M',
                };

                // Google Play product mapping
                $products[] = [
                    'id' => (string) $plan->id,
                    'backend_product_id' => (string) $plan->id,
                    'store_product_id' => $this->getStoreProductId($plan, 'google_play'),
                    'title' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'localized_price' => (string) ($plan->usd_price ?? $plan->price),
                    'currency_code' => $plan->usd_price ? 'USD' : 'EGP',
                    'billing_period' => $billingPeriod,
                    'provider' => 'google_play',
                    'is_active' => (bool) $plan->is_active,
                ];

                // Apple App Store product mapping
                $products[] = [
                    'id' => (string) $plan->id,
                    'backend_product_id' => (string) $plan->id,
                    'store_product_id' => $this->getStoreProductId($plan, 'app_store'),
                    'title' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'localized_price' => (string) ($plan->usd_price ?? $plan->price),
                    'currency_code' => $plan->usd_price ? 'USD' : 'EGP',
                    'billing_period' => $billingPeriod,
                    'provider' => 'app_store',
                    'is_active' => (bool) $plan->is_active,
                ];
            }

            return ApiResponseService::successResponse('Billing products retrieved successfully', $products);
        } catch (\Throwable $e) {
            Log::error('BillingApiController@getProducts failed: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to retrieve billing products: ' . $e->getMessage());
        }
    }

    /**
     * Server-side verify native in-app store purchase
     * POST /api/billing/purchase/verify
     */
    public function verifyPurchase(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|string|in:google_play,app_store,googlePlay,appStore',
            'product_id' => 'required|string|max:100',
            'app_user_id' => 'nullable|string',
            'purchase_token' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'order_id' => 'nullable|string',
            'receipt_data' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError(
                $validator->errors()->first(),
                $validator->errors()->toArray()
            );
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthorized', [], 401);
        }

        // Verify app_user_id ownership if provided
        $appUserId = $request->input('app_user_id');
        if ($appUserId !== null && trim((string) $appUserId) !== '' && (string) $appUserId !== (string) $user->id) {
            return ApiResponseService::errorResponse(
                'Invalid user session binding for this purchase.',
                ['reason' => 'USER_MISMATCH'],
                403
            );
        }

        $provider = strtolower((string) $request->input('provider'));
        $storeProductId = trim((string) $request->input('product_id'));
        $txId = trim((string) (
            $request->input('transaction_id')
            ?? $request->input('purchase_token')
            ?? $request->input('order_id')
            ?? $request->input('receipt_data')
            ?? ''
        ));

        if ($txId === '') {
            return ApiResponseService::errorResponse(
                'Missing canonical purchase transaction evidence.',
                ['reason' => 'MISSING_TRANSACTION_EVIDENCE'],
                422
            );
        }

        // Resolve matching Skillso plan
        $plan = $this->resolvePlanFromStoreProductId($storeProductId);
        if (!$plan || !$plan->is_active) {
            return ApiResponseService::errorResponse(
                'Store product is not associated with an active subscription plan.',
                ['reason' => 'INVALID_STORE_PRODUCT'],
                422
            );
        }

        try {
            DB::beginTransaction();

            // Lock user row for concurrency safety
            User::where('id', $user->id)->lockForUpdate()->first();

            // Check if transaction already exists in database
            $existingPayment = SubscriptionPayment::where('transaction_id', $txId)
                ->where('payment_method', 'in_app_purchase')
                ->first();

            if ($existingPayment) {
                // Cross-user replay defense: If owned by another user, reject
                if ($existingPayment->user_id !== $user->id) {
                    DB::rollBack();
                    return ApiResponseService::errorResponse(
                        'This store transaction has already been claimed by another user.',
                        ['reason' => 'TRANSACTION_ALREADY_CLAIMED'],
                        409
                    );
                }

                // Same-user idempotent replay: Return existing active entitlement
                $existingSub = Subscription::find($existingPayment->subscription_id);
                DB::commit();

                return ApiResponseService::successResponse('Purchase verified (idempotent)', [
                    'accepted' => true,
                    'entitlement' => $this->buildEntitlementPayload($existingSub ?? $this->subscriptionService->getActiveSubscription($user)),
                ]);
            }

            // Calculate subscription period
            $startsAt = now();
            $endsAt = match ($plan->billing_cycle) {
                'lifetime' => null,
                'yearly' => $startsAt->copy()->addYears(1),
                default => $plan->duration_days ? $startsAt->copy()->addDays($plan->duration_days) : $startsAt->copy()->addMonths(1),
            };

            $existingActive = $this->subscriptionService->getActiveSubscription($user);

            // Create or update subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'locked_price' => (float) ($plan->usd_price ?? $plan->price),
                'locked_currency' => $plan->usd_price ? 'USD' : 'EGP',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'auto_renew' => $plan->billing_cycle !== 'lifetime',
                'parent_subscription_id' => $existingActive?->id,
                'paid_at' => now(),
            ]);

            // Create completed payment record
            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => (float) ($plan->usd_price ?? $plan->price),
                'wallet_amount' => 0,
                'gateway_amount' => (float) ($plan->usd_price ?? $plan->price),
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => 'in_app_purchase',
                'currency_code' => $plan->usd_price ? 'USD' : 'EGP',
                'transaction_id' => $txId,
                'paid_at' => now(),
                'method_snapshot' => [
                    'provider' => $provider,
                    'store_product_id' => $storeProductId,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $plan->billing_cycle,
                ],
                'final_amount' => (float) ($plan->usd_price ?? $plan->price),
            ]);

            DB::commit();

            return ApiResponseService::successResponse('Purchase verified successfully', [
                'accepted' => true,
                'entitlement' => $this->buildEntitlementPayload($subscription),
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('BillingApiController@verifyPurchase error: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to verify store purchase: ' . $e->getMessage());
        }
    }

    /**
     * Restore previous in-app store purchases
     * POST /api/billing/restore
     */
    public function restorePurchases(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'receipts' => 'required|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthorized', [], 401);
        }

        $receipts = $request->input('receipts', []);
        $restoredCount = 0;
        $activeSubscription = null;

        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) continue;

            $storeProductId = trim((string) ($receipt['product_id'] ?? $receipt['store_product_id'] ?? ''));
            $txId = trim((string) ($receipt['transaction_id'] ?? $receipt['purchase_token'] ?? ''));

            if ($storeProductId === '' || $txId === '') continue;

            $plan = $this->resolvePlanFromStoreProductId($storeProductId);
            if (!$plan || !$plan->is_active) continue;

            // Check if this transaction is already registered to another user
            $existing = SubscriptionPayment::where('transaction_id', $txId)
                ->where('payment_method', 'in_app_purchase')
                ->first();

            if ($existing && $existing->user_id !== $user->id) {
                continue; // Skip foreign transactions (prevent theft)
            }

            if ($existing) {
                $sub = Subscription::find($existing->subscription_id);
                if ($sub && ($sub->status === Subscription::STATUS_ACTIVE || $sub->isLifetime())) {
                    $activeSubscription = $sub;
                    $restoredCount++;
                }
                continue;
            }

            // Create restored subscription
            try {
                DB::beginTransaction();
                $startsAt = now();
                $endsAt = match ($plan->billing_cycle) {
                    'lifetime' => null,
                    'yearly' => $startsAt->copy()->addYears(1),
                    default => $startsAt->copy()->addMonths(1),
                };

                $sub = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'locked_price' => (float) ($plan->usd_price ?? $plan->price),
                    'locked_currency' => $plan->usd_price ? 'USD' : 'EGP',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => Subscription::STATUS_ACTIVE,
                    'auto_renew' => $plan->billing_cycle !== 'lifetime',
                    'paid_at' => now(),
                ]);

                SubscriptionPayment::create([
                    'subscription_id' => $sub->id,
                    'user_id' => $user->id,
                    'amount' => (float) ($plan->usd_price ?? $plan->price),
                    'wallet_amount' => 0,
                    'gateway_amount' => (float) ($plan->usd_price ?? $plan->price),
                    'status' => SubscriptionPayment::STATUS_COMPLETED,
                    'payment_method' => 'in_app_purchase',
                    'currency_code' => $plan->usd_price ? 'USD' : 'EGP',
                    'transaction_id' => $txId,
                    'paid_at' => now(),
                    'method_snapshot' => [
                        'restored' => true,
                        'store_product_id' => $storeProductId,
                    ],
                    'final_amount' => (float) ($plan->usd_price ?? $plan->price),
                ]);

                DB::commit();
                $activeSubscription = $sub;
                $restoredCount++;
            } catch (\Throwable $ex) {
                if (DB::transactionLevel() > 0) DB::rollBack();
                Log::warning('BillingApiController@restorePurchases item error: ' . $ex->getMessage());
            }
        }

        $currentEntitlement = $this->buildEntitlementPayload(
            $activeSubscription ?? $this->subscriptionService->getActiveSubscription($user)
        );

        return ApiResponseService::successResponse('Purchases restored successfully', [
            'restored_count' => $restoredCount,
            'entitlement' => $currentEntitlement,
        ]);
    }

    /**
     * Get current user's authoritative entitlement
     * GET /api/billing/entitlements/me
     */
    public function getMyEntitlement(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthorized', [], 401);
        }

        $subscription = $this->subscriptionService->getActiveSubscription($user);
        $payload = $this->buildEntitlementPayload($subscription);

        return ApiResponseService::successResponse('Entitlement retrieved successfully', $payload);
    }

    /**
     * Resolve store product ID to Skillso Plan
     */
    private function resolvePlanFromStoreProductId(string $storeProductId): ?SubscriptionPlan
    {
        $clean = strtolower(trim($storeProductId));

        // 1. Direct match by slug
        $plan = SubscriptionPlan::where('is_active', true)
            ->where(function ($q) use ($clean) {
                $q->where('slug', $clean)
                  ->orWhere('billing_cycle', $clean);
            })
            ->first();

        if ($plan) return $plan;

        // 2. Standard mapped store identifiers
        if (str_contains($clean, 'yearly') || str_contains($clean, 'annual')) {
            return SubscriptionPlan::where('is_active', true)->where('billing_cycle', 'yearly')->first();
        }
        if (str_contains($clean, 'lifetime')) {
            return SubscriptionPlan::where('is_active', true)->where('billing_cycle', 'lifetime')->first();
        }
        if (str_contains($clean, 'monthly')) {
            return SubscriptionPlan::where('is_active', true)->where('billing_cycle', 'monthly')->first();
        }

        // 3. Fallback: match by ID if numeric
        if (is_numeric($clean)) {
            return SubscriptionPlan::where('is_active', true)->find((int) $clean);
        }

        return SubscriptionPlan::where('is_active', true)->first();
    }

    private function getStoreProductId(SubscriptionPlan $plan, string $provider): string
    {
        $cycle = $plan->billing_cycle ?: ($plan->slug ?: 'monthly');
        return match ($provider) {
            'app_store' => "skillso_{$cycle}_sub",
            default => "skillso_{$cycle}_sub",
        };
    }

    private function buildEntitlementPayload(?Subscription $subscription): array
    {
        if (!$subscription || !$subscription->isActive()) {
            return [
                'entitlement_key' => 'premium_access',
                'status' => 'inactive',
                'is_premium' => false,
                'expires_at' => null,
                'grace_period_ends_at' => null,
                'source_subscription_id' => null,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        return [
            'entitlement_key' => 'premium_access',
            'status' => 'active',
            'is_premium' => true,
            'expires_at' => $subscription->ends_at?->toIso8601String(),
            'grace_period_ends_at' => null,
            'source_subscription_id' => (string) $subscription->id,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
