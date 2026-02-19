<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Services\ApiResponseService;
use App\Services\PricingService;
use App\Services\SubscriptionService;
use App\Services\Payment\KashierCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class SubscriptionApiController extends Controller
{
    private const KASHIER_PENDING_TTL = 14400; // 4 hours

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly PricingService $pricingService,
        private readonly KashierCheckoutService $kashierService
    ) {}

    /**
     * Get all active subscription plans (paginated)
     * Public endpoint - no auth required
     *
     * Query params: page (default 1), per_page (default 15, max 50)
     */
    public function getPlans(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->input('per_page', 15), 50);
            $perPage = max($perPage, 1);

            $countryCode = $this->pricingService->detectUserCountry($request);
            if ($countryCode === '') {
                $countryCode = 'EG';
            }

            $paginator = SubscriptionPlan::active()
                ->ordered()
                ->paginate($perPage);

            $plans = $paginator->getCollection()->map(function ($plan) use ($countryCode) {
                $localized = $this->pricingService->getPriceForCountry($plan, $countryCode);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'billing_cycle' => $plan->billing_cycle,
                    'billing_cycle_label' => $plan->billing_cycle_label,
                    'duration_days' => $plan->getDurationDays(),
                    'price' => (float) $plan->price,
                    'formatted_price' => $plan->formatted_price,
                    'display_price' => $localized['price'],
                    'display_currency' => $localized['currency_code'],
                    'display_symbol' => $localized['currency_symbol'],
                    'features' => $plan->features,
                    'is_lifetime' => $plan->isLifetime(),
                ];
            });

            return ApiResponseService::successResponse('Subscription plans retrieved successfully', [
                'plans' => $plans,
                'detected_country' => $countryCode,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve subscription plans: ' . $e->getMessage());
        }
    }

    /**
     * Get current user's subscription status
     */
    public function getMySubscription(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $status = $this->subscriptionService->getSubscriptionStatus($user);
            
            $response = [
                'has_access' => $status['has_access'],
                'status' => $status['status'],
                'message' => $status['message'] ?? null,
            ];

            if ($status['subscription']) {
                $subscription = $status['subscription'];
                $response['subscription'] = [
                    'id' => $subscription->id,
                    'plan' => [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'billing_cycle' => $subscription->plan->billing_cycle,
                        'billing_cycle_label' => $subscription->plan->billing_cycle_label,
                    ],
                    'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'days_remaining' => $subscription->days_remaining,
                    'is_lifetime' => $subscription->isLifetime(),
                    'auto_renew' => $subscription->auto_renew,
                    'status' => $subscription->status,
                ];
            }

            return ApiResponseService::successResponse('Subscription status retrieved successfully', $response);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve subscription status: ' . $e->getMessage());
        }
    }

    /**
     * Subscribe to a plan
     */
    public function subscribe(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan_id' => 'required|exists:subscription_plans,id',
                'payment_method' => 'nullable|string',
                'use_wallet' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            // Check if user already has active subscription
            $existingSubscription = $this->subscriptionService->getActiveSubscription($user);
            if ($existingSubscription) {
                return ApiResponseService::errorResponse(
                    'لديك اشتراك نشط بالفعل. يمكنك الترقية أو الانتظار حتى انتهاء الاشتراك الحالي.',
                    ['current_subscription' => [
                        'plan_name' => $existingSubscription->plan->name,
                        'ends_at' => $existingSubscription->ends_at?->format('Y-m-d'),
                        'days_remaining' => $existingSubscription->days_remaining,
                    ]],
                    400
                );
            }

            $plan = SubscriptionPlan::findOrFail($request->plan_id);

            if (!$plan->is_active) {
                return ApiResponseService::errorResponse('هذه الخطة غير متاحة حالياً.', [], 400);
            }

            $totalAmount = (float) $plan->price;
            $split = $this->subscriptionService->walletAndGatewayPayment(
                $user,
                $plan,
                $totalAmount,
                $request->boolean('use_wallet')
            );
            $walletAmount = $split['wallet_amount'];
            $gatewayAmount = $split['gateway_amount'];

            // Full wallet payment: create subscription immediately
            if ($gatewayAmount <= 0) {
                $subscription = $this->subscriptionService->createSubscription(
                    $user,
                    $plan,
                    $request->payment_method ?? 'wallet',
                    $walletAmount,
                    0
                );

                return ApiResponseService::successResponse('تم الاشتراك بنجاح!', [
                    'subscription' => [
                        'id' => $subscription->id,
                        'plan_name' => $plan->name,
                        'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                        'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                        'is_lifetime' => $subscription->isLifetime(),
                        'status' => $subscription->status,
                    ],
                    'payment' => [
                        'total_amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => 0,
                    ],
                    'requires_checkout' => false,
                ]);
            }

            // Gateway payment required: create Kashier checkout
            try {
                $checkout = $this->kashierService->createCheckoutSession($plan, $user, $gatewayAmount);
            } catch (\RuntimeException $e) {
                return ApiResponseService::errorResponse(
                    'بوابة الدفع غير مهيأة. يرجى التواصل مع الإدارة.',
                    [],
                    503
                );
            }

            // Store pending wallet amount for webhook to apply on success
            Cache::put('kashier_pending_' . $checkout['order_id'], [
                'wallet_amount' => $walletAmount,
                'plan_id' => $plan->id,
                'user_id' => $user->id,
            ], self::KASHIER_PENDING_TTL);

            return ApiResponseService::successResponse('يرجى إكمال الدفع عبر Kashier.', [
                'requires_checkout' => true,
                'checkout_url' => $checkout['url'],
                'order_id' => $checkout['order_id'],
                'payment' => [
                    'total_amount' => $totalAmount,
                    'wallet_amount' => $walletAmount,
                    'gateway_amount' => $gatewayAmount,
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to create subscription: ' . $e->getMessage());
        }
    }

    /**
     * Renew subscription (pay for next period and extend)
     */
    public function renew(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'subscription_id' => 'nullable|exists:subscriptions,id',
                'payment_method' => 'nullable|string',
                'use_wallet' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $subscription = null;

            if ($request->subscription_id) {
                $subscription = Subscription::with('plan')
                    ->where('user_id', $user->id)
                    ->find($request->subscription_id);
            }

            if (!$subscription) {
                $subscription = $this->subscriptionService->getActiveSubscription($user);
            }

            if (!$subscription) {
                $subscription = Subscription::forUser($user->id)
                    ->with('plan')
                    ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_EXPIRED])
                    ->orderByDesc('ends_at')
                    ->first();
            }

            if (!$subscription) {
                return ApiResponseService::errorResponse('لا يوجد اشتراك للتجديد. يرجى الاشتراك أولاً.', [], 400);
            }

            $plan = $subscription->plan;

            if ($plan->isLifetime()) {
                return ApiResponseService::errorResponse('اشتراك مدى الحياة لا يحتاج تجديداً.', [], 400);
            }

            $totalAmount = (float) $plan->price;
            $walletAmount = 0;
            $gatewayAmount = $totalAmount;

            if ($request->boolean('use_wallet') && $user->wallet_balance > 0) {
                $walletAmount = min($user->wallet_balance, $totalAmount);
                $gatewayAmount = $totalAmount - $walletAmount;
            }

            $subscription = $this->subscriptionService->renewWithPayment(
                $user,
                $subscription,
                $request->payment_method ?? 'wallet',
                $walletAmount,
                $gatewayAmount
            );

            return ApiResponseService::successResponse('تم تجديد الاشتراك بنجاح!', [
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan->name,
                    'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'is_lifetime' => $subscription->isLifetime(),
                    'status' => $subscription->status,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponseService::errorResponse($e->getMessage(), [], 400);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to renew subscription: ' . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $subscription = $this->subscriptionService->getActiveSubscription($user);
            
            if (!$subscription) {
                return ApiResponseService::errorResponse('لا يوجد اشتراك نشط للإلغاء.', [], 400);
            }

            $result = $this->subscriptionService->cancelSubscription($subscription, $request->reason);

            if ($result) {
                return ApiResponseService::successResponse('تم إلغاء الاشتراك بنجاح.', [
                    'subscription_id' => $subscription->id,
                    'cancelled_at' => $subscription->cancelled_at->format('Y-m-d H:i:s'),
                ]);
            }

            return ApiResponseService::errorResponse('فشل إلغاء الاشتراك.', [], 500);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to cancel subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get payment history
     */
    public function getHistory(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:50',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $limit = $request->input('limit', 10);
            $payments = $this->subscriptionService->getPaymentHistory($user, $limit);

            $formattedPayments = $payments->map(fn($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'wallet_amount' => (float) $payment->wallet_amount,
                'gateway_amount' => (float) $payment->gateway_amount,
                'status' => $payment->status,
                'payment_method' => $payment->payment_method,
                'transaction_id' => $payment->transaction_id,
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'plan' => $payment->subscription?->plan ? [
                    'name' => $payment->subscription->plan->name,
                    'billing_cycle' => $payment->subscription->plan->billing_cycle_label,
                ] : null,
                'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
            ]);

            return ApiResponseService::successResponse('Payment history retrieved successfully', [
                'payments' => $formattedPayments,
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve payment history: ' . $e->getMessage());
        }
    }

    /**
     * Update subscription settings (auto-renew toggle)
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'auto_renew' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $subscription = $this->subscriptionService->updateUserSettings($user, $request->only([
                'auto_renew',
            ]));

            if (!$subscription) {
                return ApiResponseService::errorResponse('لا يوجد اشتراك نشط لتحديث الإعدادات.', [], 400);
            }

            return ApiResponseService::successResponse('تم تحديث الإعدادات بنجاح.', [
                'auto_renew' => $subscription->auto_renew,
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to update settings: ' . $e->getMessage());
        }
    }
}
