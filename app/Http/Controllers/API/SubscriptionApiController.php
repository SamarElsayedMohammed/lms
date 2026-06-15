<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\AdminNewSubscriptionRequestNotification;
use App\Services\ApiResponseService;
use App\Services\Payment\KashierCheckoutService;
use App\Services\PricingService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
                    'price' => (float) $plan->price,
                    'currency_code' => 'EGP', // Base currency
                    'old_price' => null, // Base old price
                    'display_price' => $localized['price'],
                    'display_currency' => $localized['currency_code'],
                    'display_old_price' => $localized['old_price'],
                    'display_symbol' => $localized['currency_symbol'],
                    'resolved_country' => $countryCode,
                    'resolved_currency' => $localized['currency_code'],
                    'price_source' => $localized['price_source'],
                    'can_subscribe' => $localized['can_subscribe'],
                    'is_available' => $plan->is_active && $localized['can_subscribe'],
                    // Legacy/Extra fields for backward compatibility
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'billing_cycle' => $plan->billing_cycle,
                    'billing_cycle_label' => $plan->billing_cycle_label,
                    'duration_days' => $plan->getDurationDays(),
                    'formatted_price' => number_format((float) $localized['price'], 2) . ' ' . $localized['currency_symbol'],
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
            ])->header('Cache-Control', 'private, no-store');
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

            // Fetch all active, pending and pending approval subscriptions
            $subscriptions = Subscription::with('plan')
                ->where('user_id', $user->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                ->orderBy('starts_at', 'asc')
                ->get();

            if ($subscriptions->isEmpty()) {
                return ApiResponseService::successResponse('No active subscription found', null);
            }

            $hasAccess = $subscriptions->contains('status', Subscription::STATUS_ACTIVE);
            
            $formattedSubscriptions = $subscriptions->map(function ($subscription) {
                $statusLabel = match($subscription->status) {
                    Subscription::STATUS_ACTIVE => 'Active',
                    Subscription::STATUS_PENDING => 'Pending (Queued)',
                    Subscription::STATUS_PENDING_APPROVAL => 'Pending Admin Approval',
                    default => ucfirst($subscription->status),
                };
                return [
                    'id' => $subscription->id,
                    'plan' => $subscription->plan ? [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'billing_cycle' => $subscription->plan->billing_cycle,
                        'billing_cycle_label' => $subscription->plan->billing_cycle_label,
                    ] : null,
                    'plan_name' => $subscription->plan->name ?? 'Unknown Plan',
                    'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'days_remaining' => $subscription->days_remaining,
                    'is_lifetime' => $subscription->isLifetime(),
                    'auto_renew' => (bool)$subscription->auto_renew,
                    'status' => $subscription->status,
                    'status_label' => $statusLabel,
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                    'renewal_date' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'payment_method' => 'wallet', // default or from latest payment if needed
                    'next_payment_amount' => $subscription->plan ? (float)$subscription->plan->price : 0,
                    'currency' => \App\Services\HelperService::systemSettings('currency_code') ?: 'EGP',
                ];
            });

            return ApiResponseService::successResponse('Subscription status retrieved successfully', [
                'has_access' => $hasAccess,
                'subscriptions' => $formattedSubscriptions,
                // Backward compatibility for single subscription clients
                'subscription' => $formattedSubscriptions->first() 
            ]);
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
                'promo_code' => 'nullable|string|max:50',
                'manual_deposit_method_id' => 'required_if:payment_method,manual|exists:manual_deposit_methods,id',
                'receipt' => 'required_if:payment_method,manual|image|mimes:jpeg,png,jpg,webp|max:5120',
                'transaction_id' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            // Allow subscription stacking: the SubscriptionService will carry over remaining days.

            $plan = SubscriptionPlan::findOrFail($request->plan_id);

            $countryCode = $this->pricingService->detectUserCountry($request);
            if ($countryCode === '') {
                $countryCode = 'EG';
            }

            $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);

            if (!$plan->is_active || !$countryPricing['can_subscribe']) {
                return ApiResponseService::errorResponse('هذه الخطة غير متاحة حالياً.', [], 400);
            }

            $originalAmount = (float) $countryPricing['price'];
            $discountAmount = 0;
            $appliedPromoCode = null;

            // Apply promo code discount from active popup campaigns
            if ($request->filled('promo_code')) {
                $campaign = \App\Models\PopupCampaign::where('is_active', true)
                    ->where('promo_code', $request->promo_code)
                    ->first();

                if (!$campaign) {
                    return ApiResponseService::validationError('كود الخصم غير صالح.');
                }

                // Expiry Check
                if (
                    ($campaign->starts_at && $campaign->starts_at > now()) ||
                    ($campaign->ends_at && $campaign->ends_at < now())
                ) {
                    return ApiResponseService::validationError('كود الخصم منتهي الصلاحية أو لم يبدأ بعد.');
                }

                // In a fully featured system we'd check usage limits and plan restrictions here if they were added to PopupCampaign.
                // For now we enforce currency compatibility.
                if ($campaign->discount_type === 'percentage') {
                    $discountAmount = round($originalAmount * ($campaign->discount_value / 100), 2);
                } else {
                    // Fixed amount discount is typically in the base currency (EGP).
                    // We must convert it to the user's resolved currency to ensure compatibility.
                    $resolvedCurrency = $countryPricing['currency_code'] ?? 'EGP';
                    $discountAmount = $this->pricingService->convertFromEgp($campaign->discount_value, $resolvedCurrency);
                    $discountAmount = min($discountAmount, $originalAmount);
                }

                $appliedPromoCode = $campaign->promo_code;
            }

            $totalAmount = max($originalAmount - $discountAmount, 0);

            $split = $this->subscriptionService->walletAndGatewayPayment(
                $user,
                $plan,
                $totalAmount,
                $request->boolean('use_wallet')
            );
            $walletAmount = $split['wallet_amount'];
            $gatewayAmount = $split['gateway_amount'];

            // Build discount metadata for payment record
            $discountMeta = [
                'promo_code' => $appliedPromoCode,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'resolved_country' => $countryCode,
                'currency_code' => $countryPricing['currency_code'] ?? 'EGP',
                'price_source' => $countryPricing['price_source'] ?? 'default',
            ];

            // Full wallet payment: create subscription immediately
            if ($gatewayAmount <= 0) {
                $subscription = $this->subscriptionService->createSubscription(
                    $user,
                    $plan,
                    $request->payment_method ?? 'wallet',
                    $walletAmount,
                    0,
                    $discountMeta
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
                        'original_amount' => $originalAmount,
                        'discount_amount' => $discountAmount,
                        'promo_code' => $appliedPromoCode,
                        'total_amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => 0,
                    ],
                    'requires_checkout' => false,
                ]);
            }

            // Manual payment flow
            if ($request->payment_method === 'manual') {
                $method = \App\Models\ManualDepositMethod::find($request->manual_deposit_method_id);
                if (!$method || !$method->is_active) {
                    return ApiResponseService::errorResponse('طريقة الدفع اليدوية هذه غير متوفرة حالياً.');
                }

                try {
                    $receiptPath = \App\Services\FileService::compressAndUpload(
                        $request->file('receipt'),
                        'subscriptions/receipts'
                    );

                    // Create subscription with pending_approval status
                    $subscription = Subscription::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'starts_at' => now(), // Placeholders, will be updated upon admin approval
                        'ends_at' => null,   // Will be updated upon admin approval
                        'status' => Subscription::STATUS_PENDING_APPROVAL,
                        'auto_renew' => true,
                    ]);

                    // Create payment record in pending status
                    \App\Models\SubscriptionPayment::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $user->id,
                        'amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => $gatewayAmount,
                        'status' => \App\Models\SubscriptionPayment::STATUS_PENDING,
                        'payment_method' => 'manual',
                        'resolved_country' => $countryCode,
                        'currency_code' => $countryPricing['currency_code'] ?? 'EGP',
                        'price_source' => $countryPricing['price_source'] ?? 'default',
                        'manual_deposit_method_id' => $request->manual_deposit_method_id,
                        'receipt' => $receiptPath,
                        'transaction_id' => $request->transaction_id,
                        'promo_code' => $appliedPromoCode,
                        'original_amount' => $appliedPromoCode ? $originalAmount : null,
                        'discount_amount' => $discountAmount,
                        'paid_at' => null,
                        'tax' => 0,
                        'final_amount' => $totalAmount,
                    ]);

                    // Notify all super-admins about the new manual subscription request
                    try {
                        $subscription->load('plan');
                        $admins = User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->get();
                        foreach ($admins as $admin) {
                            $admin->notify(new AdminNewSubscriptionRequestNotification($subscription, $user));
                        }
                    } catch (\Throwable $e) {
                        Log::error('SubscriptionApiController: Failed to notify admins of new manual subscription request', [
                            'subscription_id' => $subscription->id,
                            'user_id'         => $user->id,
                            'error'           => $e->getMessage(),
                        ]);
                    }

                    return ApiResponseService::successResponse('تم إنشاء طلب الدفع بنجاح وجاري مراجعة الطلب من قبل الإدارة.', [
                        'requires_checkout' => false,
                        'subscription' => [
                            'id' => $subscription->id,
                            'plan_name' => $plan->name,
                            'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                            'ends_at' => null,
                            'status' => $subscription->status,
                        ],
                        'payment' => [
                            'original_amount' => $originalAmount,
                            'discount_amount' => $discountAmount,
                            'promo_code' => $appliedPromoCode,
                            'total_amount' => $totalAmount,
                            'wallet_amount' => $walletAmount,
                            'gateway_amount' => $gatewayAmount,
                            'payment_method' => 'manual',
                        ]
                    ]);
                } catch (\Exception $e) {
                    return ApiResponseService::errorResponse('فشل في إرسال طلب الاشتراك اليدوي: ' . $e->getMessage());
                }
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
                'promo_code' => $appliedPromoCode,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'resolved_country' => $countryCode,
                'currency_code' => $countryPricing['currency_code'] ?? 'EGP',
                'price_source' => $countryPricing['price_source'] ?? 'default',
            ], self::KASHIER_PENDING_TTL);

            return ApiResponseService::successResponse('يرجى إكمال الدفع عبر Kashier.', [
                'requires_checkout' => true,
                'checkout_url' => $checkout['url'],
                'order_id' => $checkout['order_id'],
                'payment' => [
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'promo_code' => $appliedPromoCode,
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
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $perPage = $request->input('per_page', 10);
            $paginator = $this->subscriptionService->getPaymentHistory($user, $perPage);

            $pricingService = app(\App\Services\PricingService::class);
            $countryCode = $pricingService->detectUserCountry($request);
            $currencyObj = $pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code : 'EGP';
            $displaySymbol = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $formattedPayments = $paginator->getCollection()->map(fn($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'local_amount' => $pricingService->convertFromEgp((float) $payment->amount, $displayCurrency),
                'wallet_amount' => (float) $payment->wallet_amount,
                'local_wallet_amount' => $pricingService->convertFromEgp((float) $payment->wallet_amount, $displayCurrency),
                'gateway_amount' => (float) $payment->gateway_amount,
                'local_gateway_amount' => $pricingService->convertFromEgp((float) $payment->gateway_amount, $displayCurrency),
                'promo_code' => $payment->promo_code,
                'original_amount' => $payment->original_amount ? (float) $payment->original_amount : null,
                'local_original_amount' => $payment->original_amount ? $pricingService->convertFromEgp((float) $payment->original_amount, $displayCurrency) : null,
                'discount_amount' => (float) $payment->discount_amount,
                'local_discount_amount' => $pricingService->convertFromEgp((float) $payment->discount_amount, $displayCurrency),
                'currency' => $displayCurrency,
                'currency_symbol' => $displaySymbol,
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

            $totalPaid = \App\Models\SubscriptionPayment::where('user_id', $user->id)
                ->where('status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->sum('amount');
            
            $transactionsCount = \App\Models\SubscriptionPayment::where('user_id', $user->id)
                ->where('status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->count();

            return ApiResponseService::successResponse('Payment history retrieved successfully', [
                'total_paid' => (float) $totalPaid,
                'local_total_paid' => $pricingService->convertFromEgp((float) $totalPaid, $displayCurrency),
                'currency' => $displayCurrency,
                'currency_symbol' => $displaySymbol,
                'transactions_count' => $transactionsCount,
                'payments' => $formattedPayments,
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
