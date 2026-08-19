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
        return ApiResponseService::errorResponse(
            'مشتريات المتجر غير مفعّلة. الاشتراك يتم يدويًا فقط.',
            ['reason' => 'IAP_DISABLED'],
            403
        );
    }

    /**
     * Restore previous in-app store purchases
     * POST /api/billing/restore
     */
    public function restorePurchases(Request $request): JsonResponse
    {
        return ApiResponseService::errorResponse(
            'استعادة مشتريات المتجر غير مفعّلة. الاشتراك يتم يدويًا فقط.',
            ['reason' => 'IAP_DISABLED'],
            403
        );
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
