<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\Payment\StoreBillingManager;
use App\Services\SubscriptionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class BillingApiController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly StoreBillingManager $storeBillingManager,
    ) {
    }

    /**
     * Get active Store products mapped to Skillso subscription plans
     * GET /api/billing/products
     */
    public function getProducts(Request $request): void
    {
        if (! config('store_billing.enabled', false)) {
            ApiResponseService::successResponse('Billing products retrieved successfully', []);
            return;
        }

        try {
            $plans = SubscriptionPlan::where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $products = [];
            foreach ($plans as $plan) {
                $billingPeriod = match ($plan->billing_cycle) {
                    'yearly' => 'P1Y',
                    'semi_annual' => 'P6M',
                    'quarterly' => 'P3M',
                    'lifetime' => 'P100Y',
                    default => 'P1M',
                };

                // Google Play product mapping
                $products[] = [
                    'id' => (string) $plan->id,
                    'backend_product_id' => (string) $plan->id,
                    'store_product_id' => $this->storeBillingManager->getStoreProductId($plan, 'google_play'),
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
                    'store_product_id' => $this->storeBillingManager->getStoreProductId($plan, 'app_store'),
                    'title' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'localized_price' => (string) ($plan->usd_price ?? $plan->price),
                    'currency_code' => $plan->usd_price ? 'USD' : 'EGP',
                    'billing_period' => $billingPeriod,
                    'provider' => 'app_store',
                    'is_active' => (bool) $plan->is_active,
                ];
            }

            ApiResponseService::successResponse('Billing products retrieved successfully', $products);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('BillingApiController@getProducts failed: ' . $e->getMessage());
            ApiResponseService::errorResponse('Failed to retrieve billing products: ' . $e->getMessage());
        }
    }

    /**
     * Server-side verify native in-app store purchase (StoreKit 2 / Google Play)
     * POST /api/billing/purchase/verify
     */
    public function verifyPurchase(Request $request): void
    {
        if (! config('store_billing.enabled', false)) {
            ApiResponseService::errorResponse(
                'عمليات الشراء والتحقق عبر متجر التطبيقات معطلة حالياً. يرجى إتمام الاشتراك عبر الموقع الإلكتروني.',
                [
                    'reason' => 'STORE_BILLING_DISABLED',
                    'error_code' => 'store_billing_disabled',
                ],
                403
            );
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Unauthenticated', ['reason' => 'UNAUTHENTICATED'], 401);
        }

        $validator = Validator::make($request->all(), [
            'provider' => 'required|string',
            'product_id' => 'required|string',
            'transaction_id' => 'nullable|string',
            'purchase_token' => 'nullable|string',
            'signed_transaction' => 'nullable|string',
            'receipt_data' => 'nullable|string',
            'order_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            ApiResponseService::validationErrorResponse($validator->errors());
        }

        $platform = (string) $request->input('provider');
        $proof = [
            'product_id' => (string) $request->input('product_id'),
            'transaction_id' => $request->input('transaction_id'),
            'purchase_token' => $request->input('purchase_token'),
            'signed_transaction' => $request->input('signed_transaction'),
            'receipt_data' => $request->input('receipt_data'),
            'order_id' => $request->input('order_id'),
        ];

        try {
            $result = $this->storeBillingManager->verifyAndActivatePurchase($user, $platform, $proof);

            if (!$result['success']) {
                ApiResponseService::errorResponse(
                    $result['message'],
                    [
                        'reason' => strtoupper($result['error_code'] ?? 'VERIFICATION_FAILED'),
                        'error_code' => $result['error_code'] ?? 'verification_failed',
                    ],
                    $result['status_code'] ?? 422
                );
            }

            $subscription = $result['subscription'] ?? $this->subscriptionService->getActiveSubscription($user);
            $entitlementPayload = $this->buildEntitlementPayload($subscription);

            ApiResponseService::successResponse(
                $result['message'],
                [
                    'entitlement' => $entitlementPayload,
                    'is_premium' => $entitlementPayload['is_premium'],
                    'subscription_id' => $subscription ? (string) $subscription->id : null,
                    'store_result' => isset($result['result']) ? [
                        'store' => $result['result']->store,
                        'environment' => $result['result']->environment,
                        'transaction_id' => $result['result']->transactionId,
                        'original_transaction_id' => $result['result']->originalTransactionId,
                        'status' => $result['result']->status,
                        'expires_at' => $result['result']->expiresAt?->toIso8601String(),
                    ] : null,
                ]
            );
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('BillingApiController@verifyPurchase exception: ' . $e->getMessage());
            ApiResponseService::errorResponse('فشلت معالجة عملية الشراء: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Restore previous in-app store purchases
     * POST /api/billing/restore
     */
    public function restorePurchases(Request $request): void
    {
        if (! config('store_billing.enabled', false)) {
            ApiResponseService::errorResponse(
                'عمليات الشراء والتحقق عبر متجر التطبيقات معطلة حالياً. يرجى إتمام الاشتراك عبر الموقع الإلكتروني.',
                [
                    'reason' => 'STORE_BILLING_DISABLED',
                    'error_code' => 'store_billing_disabled',
                ],
                403
            );
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Unauthenticated', ['reason' => 'UNAUTHENTICATED'], 401);
        }

        $receipts = $request->input('receipts', []);
        if (!is_array($receipts) || empty($receipts)) {
            // Check if single proof was passed in root request
            if ($request->has('product_id') && ($request->has('purchase_token') || $request->has('receipt_data') || $request->has('signed_transaction'))) {
                $receipts = [$request->all()];
            } else {
                // Return current entitlement if no receipts submitted to restore
                $subscription = $this->subscriptionService->getActiveSubscription($user);
                ApiResponseService::successResponse(
                    'لا توجد مشتريات جديدة للاستعادة.',
                    [
                        'restored_count' => 0,
                        'entitlement' => $this->buildEntitlementPayload($subscription),
                    ]
                );
            }
        }

        $restoredCount = 0;
        $errors = [];

        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }

            $platform = (string) ($receipt['provider'] ?? $receipt['platform'] ?? 'app_store');
            $proof = [
                'product_id' => (string) ($receipt['product_id'] ?? ''),
                'transaction_id' => $receipt['transaction_id'] ?? null,
                'purchase_token' => $receipt['purchase_token'] ?? null,
                'signed_transaction' => $receipt['signed_transaction'] ?? null,
                'receipt_data' => $receipt['receipt_data'] ?? null,
                'order_id' => $receipt['order_id'] ?? null,
            ];

            try {
                $result = $this->storeBillingManager->verifyAndActivatePurchase($user, $platform, $proof);

                if ($result['success']) {
                    $restoredCount++;
                } else {
                    $errors[] = [
                        'product_id' => $proof['product_id'],
                        'error' => $result['message'],
                        'error_code' => $result['error_code'] ?? 'restore_failed',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'product_id' => $proof['product_id'],
                    'error' => $e->getMessage(),
                    'error_code' => 'exception',
                ];
            }
        }

        $subscription = $this->subscriptionService->getActiveSubscription($user);
        $entitlementPayload = $this->buildEntitlementPayload($subscription);

        ApiResponseService::successResponse(
            $restoredCount > 0 ? "تم استعادة {$restoredCount} من المشتريات بنجاح." : 'تمت معالجة طلب الاستعادة.',
            [
                'restored_count' => $restoredCount,
                'errors' => $errors,
                'entitlement' => $entitlementPayload,
                'is_premium' => $entitlementPayload['is_premium'],
            ]
        );
    }

    /**
     * Get current user's authoritative entitlement
     * GET /api/billing/entitlements/me
     */
    public function getMyEntitlement(Request $request): void
    {
        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Unauthorized', [], 401);
        }

        $subscription = $this->subscriptionService->getActiveSubscription($user);
        $payload = $this->buildEntitlementPayload($subscription);

        ApiResponseService::successResponse('Entitlement retrieved successfully', $payload);
    }

    private function buildEntitlementPayload(?Subscription $subscription): array
    {
        if (!$subscription || !$subscription->is_active) {
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
