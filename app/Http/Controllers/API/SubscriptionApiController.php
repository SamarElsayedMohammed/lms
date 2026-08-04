<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\PaymentMethod;
use App\Models\ManualDepositMethod;
use App\Models\User;
use App\Notifications\AdminNewSubscriptionRequestNotification;
use App\Notifications\AdminSubscriptionRenewedNotification;
use App\Notifications\ManualRenewalRequestedNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Notifications\SubscriptionRenewedNotification;
use App\Services\ApiResponseService;
use App\Services\CountryDetectionService;
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
        private readonly KashierCheckoutService $kashierService,
        private readonly CountryDetectionService $countryDetectionService,
        private readonly \App\Services\AffiliateService $affiliateService,
    ) {}

    /**
     * Get all active subscription plans (paginated)
     * Public endpoint - no auth required
     *
     * Query params: page (default 1), per_page (default 15, max 50)
     *
     * Country detection priority:
     * 1. CF-IPCountry (Cloudflare)
     * 2. X-User-Country (Frontend proxy)
     * 3. X-Vercel-IP-Country (Vercel)
     * 4. GeoIP lookup
     * 5. Default (EG)
     */
    public function getPlans(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->input('per_page', 15), 50);
            $perPage = max($perPage, 1);

            Log::info('Subscription Request Received', [
                'endpoint' => request()->path(),
                'method' => request()->method(),
                'headers' => [
                    'cf-ipcountry' => request()->header('cf-ipcountry'),
                    'x-user-country' => request()->header('x-user-country'),
                    'country' => request()->header('country')
                ],
                'ip' => request()->ip()
            ]);

            // Use the new CountryDetectionService for robust country detection
            $countryCode = $this->countryDetectionService->detect($request);



            $paginator = SubscriptionPlan::active()
                ->ordered()
                ->paginate($perPage);

            $plans = $paginator->getCollection()->map(function ($plan) use ($countryCode) {
                $localized = $this->pricingService->getPriceForCountry($plan, $countryCode);



                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $localized['price'],
                    'currency_code' => $localized['currency_code'],
                    'old_price' => $localized['old_price'],
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
                    'duration_label' => $plan->getLocalizedDurationLabelAttribute(),
                    'localized_duration' => $plan->getLocalizedDurationLabelAttribute(),
                    'formatted_price' => number_format($localized['price'], 0) . ' ' . $localized['currency_symbol'],
                    'features' => $plan->features,
                    'is_lifetime' => $plan->isLifetime(),
                ];
            });

            $isAffiliateEnabled = $this->affiliateService->isEnabled();

            $responseData = [
                'plans' => $plans->values()->all(),
                'detected_country' => $countryCode,
                'affiliate_system_enabled' => $isAffiliateEnabled,
                'wallet_payment_enabled' => $isAffiliateEnabled,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ];

            Log::info('Subscription Plans Payload Sent', [
                'endpoint' => $request->path(),
                'detected_country' => $countryCode,
                'plans_count' => $plans->count(),
            ]);

            ApiResponseService::successResponse(
                'Subscription plans retrieved successfully',
                $responseData,
                headers: ['Cache-Control' => 'private, no-store'],
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to retrieve subscription plans');
        }
    }

    /**
     * Get current user's subscription status
     */
    public function getMySubscription(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            // Fetch all active, pending and pending-approval subscriptions
            $subscriptions = Subscription::with('plan')
                ->where('user_id', $user->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                ->orderByRaw('ends_at IS NULL DESC')
                ->orderByDesc('ends_at')
                ->orderByDesc('id')
                ->get();

            if ($subscriptions->isEmpty()) {
                return ApiResponseService::successResponse('No active subscription found', null);
            }

            $hasAccess = $subscriptions->contains('status', Subscription::STATUS_ACTIVE);

            $formatSubscription = function ($subscription) use ($countryCode, $displayCurrency, $displaySymbol): array {
                $isActive    = $subscription->status === Subscription::STATUS_ACTIVE;
                $statusLabel = match($subscription->status) {
                    Subscription::STATUS_ACTIVE           => 'Active',
                    Subscription::STATUS_PENDING          => 'Pending (Queued)',
                    Subscription::STATUS_PENDING_APPROVAL => 'Pending Admin Approval',
                    default                               => ucfirst($subscription->status),
                };

                // Resolve next payment amount in user's local currency
                $localizedPricing    = $subscription->plan
                    ? $this->pricingService->getPriceForCountry($subscription->plan, $countryCode)
                    : ['price' => 0, 'currency_code' => $displayCurrency, 'currency_symbol' => $displaySymbol];
                $nextPaymentAmount   = (float) $localizedPricing['price'];
                $nextPaymentCurrency = $localizedPricing['currency_code'];
                $nextPaymentSymbol   = $localizedPricing['currency_symbol'];

                // Bug 4 fix: days_remaining is only meaningful for ACTIVE subscriptions.
                // For pending/queued plans that have not started yet, return null to avoid
                // showing misleading "32 days remaining" on a future plan.
                // duration_days gives the plan length regardless of activation state.
                $daysRemaining = $isActive ? $subscription->days_remaining : null;
                $durationDays  = $subscription->plan?->getDurationDays();

                return [
                    'id'                  => $subscription->id,
                    'plan' => $subscription->plan ? [
                        'id'                  => $subscription->plan->id,
                        'name'                => $subscription->plan->name,
                        'billing_cycle'       => $subscription->plan->billing_cycle,
                        'billing_cycle_label' => $subscription->plan->billing_cycle_label,
                        'duration_days'       => $durationDays,
                    ] : null,
                    'plan_name'           => $subscription->plan?->name ?? 'Unknown Plan',
                    'starts_at'           => $subscription->starts_at->format('Y-m-d H:i:s'),
                    'ends_at'             => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    // Bug 4 fix: null for non-active subscriptions
                    'days_remaining'      => $daysRemaining,
                    // duration_days: full plan duration (always available, even before activation)
                    'duration_days'       => $durationDays,
                    'is_lifetime'         => $subscription->isLifetime(),
                    'auto_renew'          => (bool) $subscription->auto_renew,
                    'status'              => $subscription->status,
                    'status_label'        => $statusLabel,
                    'created_at'          => $subscription->created_at->format('Y-m-d H:i:s'),
                    'renewal_date'        => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'payment_method'      => 'wallet',
                    'next_payment_amount' => $nextPaymentAmount,
                    'currency'            => $nextPaymentCurrency,
                    'currency_symbol'     => $nextPaymentSymbol,
                    'receipt_url'         => ($latestReceipt = $subscription->payments()->latest()->first())?->getRawOriginal('receipt')
                        ? route('subscription.receipt', ['payment' => $latestReceipt->id])
                        : null,
                ];
            };

            $formattedSubscriptions = $subscriptions->map($formatSubscription);

            $isAffiliateEnabled = $this->affiliateService->isEnabled();

            // Bug 3 fix: always return the ACTIVE subscription as `subscription`.
            // Any pending/queued plan is exposed separately as `upcoming_subscription`
            // so callers always know which plan is current RIGHT NOW.
            $activeFormatted   = null;
            $upcomingFormatted = null;

            foreach ($subscriptions as $sub) {
                $formatted = $formatSubscription($sub);
                if ($sub->status === Subscription::STATUS_ACTIVE && $activeFormatted === null) {
                    $activeFormatted = $formatted;
                } elseif ($sub->status !== Subscription::STATUS_ACTIVE && $upcomingFormatted === null) {
                    $upcomingFormatted = $formatted;
                }
            }

            // If there is no active subscription (all are pending), surface the first pending
            // so the UI can still show something meaningful.
            $primarySubscription = $activeFormatted ?? $formattedSubscriptions->first();

            return ApiResponseService::successResponse('Subscription status retrieved successfully', [
                'has_access'             => $hasAccess,
                'currency'               => $displayCurrency,
                'currency_symbol'        => $displaySymbol,
                'affiliate_system_enabled' => $isAffiliateEnabled,
                'wallet_payment_enabled' => $isAffiliateEnabled,
                'can_renew_with_wallet'  => $isAffiliateEnabled,
                'wallet_balance'         => (float) $user->wallet_balance,
                'subscriptions'          => $formattedSubscriptions,
                // Bug 3 fix: `subscription` is always the currently ACTIVE one
                'subscription'           => $primarySubscription,
                // Bug 3 fix: upcoming/queued plan exposed separately, never as `subscription`
                'upcoming_subscription'  => $upcomingFormatted,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to retrieve subscription status');
        }
    }

    /**
     * Get available payment methods for subscription
     */
    public function getPaymentMethods(Request $request): JsonResponse
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::user();
            $countryCode = strtoupper((string) ($user?->country_code ?? $this->countryDetectionService->detect($request)));

            $manualMethodsQuery = PaymentMethod::query()
                ->where('is_active', true)
                ->whereIn('type', ['instapay', 'mobile_wallet', 'fawry', 'bank_transfer'])
                ->orderBy('sort_order')
                ->orderBy('name');

            $manualMethods = $manualMethodsQuery->get()
                ->filter(function (PaymentMethod $method) use ($countryCode) {
                    if (!empty($method->countries) && is_array($method->countries)) {
                        return in_array($countryCode, array_map('strtoupper', $method->countries), true);
                    }
                    return true;
                })
                ->map(function (PaymentMethod $method) {
                    return [
                        'id' => $method->id,
                        'name' => $method->name,
                        'type' => $method->type,
                        'description' => $method->instructions,
                        'instructions' => $method->instructions,
                        'account_details' => $method->toStructuredAccountDetails(),
                        'account_name' => $method->account_name,
                        'account_number' => $method->account_number,
                        'bank_name' => $method->bank_name,
                        'iban' => $method->iban,
                        'instapay_id' => $method->instapay_id,
                        'merchant_code' => $method->merchant_code,
                        'image' => $method->logo,
                        'logo' => $method->logo,
                        'dynamic_fields' => $method->dynamic_fields ?? [],
                        'require_receipt' => (bool) $method->require_receipt,
                    ];
                });

            $electronicGateways = PaymentMethod::query()
                ->where('is_active', true)
                ->where('type', 'online')
                ->get()
                ->map(function (PaymentMethod $method) {
                    return [
                        'id' => $method->id,
                        'name' => $method->name,
                        'type' => 'online',
                        'code' => 'kashier',
                        'logo_url' => $method->logo,
                        'is_active' => true,
                    ];
                })->values();

            return ApiResponseService::successResponse('Payment methods retrieved successfully', [
                'electronic_gateways' => $electronicGateways,
                'manual_methods' => $manualMethods->values(),
                'online' => true,
                'wallet' => false,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve payment methods: ' . $e->getMessage());
        }
    }

    /**
     * Validate promo code for subscription
     */
    public function validatePromoCode(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'promo_code' => 'required|string|max:50',
            'plan_id' => 'required|exists:subscription_plans,id'
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $promo = \App\Models\PromoCode::with('subscriptionPlans')
            ->where('status', 1)
            ->where('promo_code', $request->promo_code)
            ->first();

        if (!$promo) {
            return ApiResponseService::validationError('كود الخصم غير صالح.');
        }

        if (
            ($promo->start_date && $promo->start_date > now()) ||
            ($promo->end_date && $promo->end_date < now())
        ) {
            return ApiResponseService::validationError('كود الخصم منتهي الصلاحية أو لم يبدأ بعد.');
        }

        if ($promo->subscriptionPlans->isNotEmpty() && !$promo->subscriptionPlans->contains('id', $request->plan_id)) {
            return ApiResponseService::validationError('كود الخصم غير صالح لهذه الباقة.');
        }
        
        if ($promo->no_of_users !== null && $promo->no_of_users <= 0) {
            return ApiResponseService::validationError('كود الخصم وصل للحد الأقصى من المستخدمين.');
        }

        $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        $plan = \App\Models\SubscriptionPlan::find($request->plan_id);

        $countryCode = $user?->country_code ?? $this->countryDetectionService->detect($request);
        $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);

        if (!$plan->is_active || !$countryPricing['can_subscribe']) {
            return ApiResponseService::errorResponse('هذه الخطة غير متاحة حالياً.', [], 400);
        }

        $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');
        $originalAmount = (float) $countryPricing['price'];

        if ($promo->discount_type === 'percentage') {
            $discountAmount = round($originalAmount * ($promo->discount / 100), 2);
        } else {
            $discountAmount = $this->pricingService->convertFromEgp($promo->discount, $resolvedCurrency);
            $discountAmount = min($discountAmount, $originalAmount);
        }

        $totalAmount = max($originalAmount - $discountAmount, 0);

        return ApiResponseService::successResponse('Promo code is valid', [
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'currency' => $resolvedCurrency,
            'discount_type' => $promo->discount_type,
            'discount_value' => $promo->discount
        ]);
    }

    /**
     * Subscribe to a plan
     */
    public function subscribe(Request $request): JsonResponse
    {
        Log::info('Subscription Request Received', [
            'endpoint' => request()->path(),
            'method' => request()->method(),
            'headers' => [
                'cf-ipcountry' => request()->header('cf-ipcountry'),
                'x-user-country' => request()->header('x-user-country'),
                'country' => request()->header('country')
            ],
            'ip' => request()->ip()
        ]);

        try {
            $validator = Validator::make($request->all(), [
                'plan_id' => 'required|exists:subscription_plans,id',
                'payment_method' => 'nullable|string',
                'use_wallet' => 'nullable|boolean',
                'promo_code' => 'nullable|string|max:50',
                'payment_method_id' => 'required_if:payment_method,manual|string|max:64',
                'payment_fields' => 'nullable|array',
                'receipt' => 'required_if:payment_method,manual|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
                'transaction_id' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $plan = SubscriptionPlan::findOrFail($request->plan_id);

            $countryCode = $this->countryDetectionService->detect($request);



            $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);
            $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');



            if (!$plan->is_active || !$countryPricing['can_subscribe']) {
                return ApiResponseService::errorResponse('هذه الخطة غير متاحة حالياً.', [], 400);
            }

            $originalAmount = (float) $countryPricing['price'];
            $discountAmount = 0;
            $appliedPromoCode = null;

            // Apply promo code discount from PromoCode
            if ($request->filled('promo_code')) {
                $promo = \App\Models\PromoCode::with('subscriptionPlans')
                    ->where('status', 1)
                    ->where('promo_code', $request->promo_code)
                    ->first();

                if (!$promo) {
                    return ApiResponseService::validationError('كود الخصم غير صالح.');
                }

                // Expiry Check
                if (
                    ($promo->start_date && $promo->start_date > now()) ||
                    ($promo->end_date && $promo->end_date < now())
                ) {
                    return ApiResponseService::validationError('كود الخصم منتهي الصلاحية أو لم يبدأ بعد.');
                }
                
                if ($promo->subscriptionPlans->isNotEmpty() && !$promo->subscriptionPlans->contains('id', $request->plan_id)) {
                    return ApiResponseService::validationError('كود الخصم غير صالح لهذه الباقة.');
                }
                
                if ($promo->no_of_users !== null && $promo->no_of_users <= 0) {
                    return ApiResponseService::validationError('كود الخصم وصل للحد الأقصى من المستخدمين.');
                }

                // For now we enforce currency compatibility.
                if ($promo->discount_type === 'percentage') {
                    $discountAmount = round($originalAmount * ($promo->discount / 100), 2);
                } else {
                    // Fixed amount discount is typically in the base currency (EGP).
                    // We must convert it to the user's resolved currency to ensure compatibility.
                    $discountAmount = $this->pricingService->convertFromEgp($promo->discount, $resolvedCurrency);
                    $discountAmount = min($discountAmount, $originalAmount);
                }

                $appliedPromoCode = $promo->promo_code;
                
                if ($promo->no_of_users !== null) {
                    $promo->decrement('no_of_users');
                }
            }

            $totalAmount = max($originalAmount - $discountAmount, 0);

            if ($request->boolean('use_wallet') && !$this->affiliateService->isEnabled()) {
                return ApiResponseService::errorResponse('الدفع من المحفظة متاح فقط عند تفعيل التسويق بالعمولة.', [], 400);
            }

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
                'currency_code' => $resolvedCurrency,
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

                try {
                    $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                } catch (\Throwable $e) {
                    Log::error('Failed to send subscription activation notification to user', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }

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
                $method = $this->findActiveManualPaymentMethod((string) $request->payment_method_id);
                if (!$method) {
                    return ApiResponseService::errorResponse(
                        'طريقة الدفع اليدوية هذه غير متوفرة حالياً.',
                        ['reason' => 'PAYMENT_METHOD_UNAVAILABLE'],
                        422
                    );
                }

                if ($fieldValidation = $this->validateManualPaymentFields($request, $method)) {
                    return $fieldValidation;
                }

                try {
                    $hasPending = Subscription::where('user_id', $user->id)
                        ->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                        ->exists();

                    if ($hasPending) {
                        return ApiResponseService::errorResponse(
                            'لديك بالفعل طلب اشتراك قيد المراجعة. يرجى الانتظار حتى تتم مراجعته.',
                            ['reason' => 'DUPLICATE_SUBSCRIPTION_REQUEST'],
                            409
                        );
                    }

                    $receiptPath = \App\Services\FileService::uploadPrivate(
                        $request->file('receipt'),
                        'subscriptions/receipts'
                    );

                    $existingSubscription = $this->subscriptionService->getActiveSubscription($user);

                    \Illuminate\Support\Facades\DB::beginTransaction();

                    // Create subscription with pending_approval status
                    $subscription = Subscription::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'locked_price' => $originalAmount,
                        'locked_currency' => $resolvedCurrency,
                        'starts_at' => now(), // Placeholders, will be updated upon admin approval
                        'ends_at' => null,   // Will be updated upon admin approval
                        'status' => Subscription::STATUS_PENDING_APPROVAL,
                        'auto_renew' => true,
                        'parent_subscription_id' => $existingSubscription?->id,
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
                        'currency_code' => $resolvedCurrency,
                        'price_source' => $countryPricing['price_source'] ?? 'default',
                        'payment_method_id' => $this->isManualDepositMethodId((string) $request->payment_method_id)
                            ? null : (int) $request->payment_method_id,
                        'manual_deposit_method_id' => $this->manualDepositMethodId((string) $request->payment_method_id),
                        'method_snapshot' => $this->manualPaymentMethodSnapshot($method),
                        'submitted_fields' => $this->submittedManualFields($request, $method),
                        'receipt' => $receiptPath,
                        'transaction_id' => $request->transaction_id,
                        'promo_code' => $appliedPromoCode,
                        'original_amount' => $appliedPromoCode ? $originalAmount : null,
                        'discount_amount' => $discountAmount,
                        'paid_at' => null,
                        'tax' => 0,
                        'final_amount' => $totalAmount,
                    ]);

                    \Illuminate\Support\Facades\DB::commit();

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
                    if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                        \Illuminate\Support\Facades\DB::rollBack();
                    }
                    if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                        throw $e;
                    }
                    return ApiResponseService::errorResponse('فشل في إرسال طلب الاشتراك اليدوي: ' . $e->getMessage());
                }
            }

            // Gateway payment required: create Kashier checkout
            try {
                $checkout = $this->kashierService->createCheckoutSession(
                    $plan,
                    $user,
                    $gatewayAmount,
                    $resolvedCurrency,
                );
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
                'currency_code' => $resolvedCurrency,
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to create subscription');
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
                'payment_method_id' => 'required_if:payment_method,manual|string|max:64',
                'payment_fields' => 'nullable|array',
                'receipt' => 'required_if:payment_method,manual|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
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

            $countryCode = $this->countryDetectionService->detect($request);
            $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);

            $totalAmount = (float) $countryPricing['price'];
            $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');
            $canSubscribe = $plan->is_active && $countryPricing['can_subscribe'];

            if (!$canSubscribe) {
                return ApiResponseService::errorResponse('هذه الخطة غير متاحة حالياً.', [], 400);
            }

            if ($request->boolean('use_wallet') && !$this->affiliateService->isEnabled()) {
                return ApiResponseService::errorResponse('الدفع من المحفظة متاح فقط عند تفعيل التسويق بالعمولة.', [], 400);
            }

            $split = $this->subscriptionService->walletAndGatewayPayment(
                $user,
                $plan,
                $totalAmount,
                $request->boolean('use_wallet')
            );
            $walletAmount = $split['wallet_amount'];
            $gatewayAmount = $split['gateway_amount'];

            // Full wallet payment: renew immediately
            if ($gatewayAmount <= 0) {
                $subscription = $this->subscriptionService->renewWithPayment(
                    $user,
                    $subscription,
                    $request->payment_method ?? 'wallet',
                    $walletAmount,
                    $gatewayAmount
                );

                // Notify user and admins about successful renewal
                try {
                    $user->notify(new SubscriptionRenewedNotification($subscription, $walletAmount));
                } catch (\Throwable $e) {
                    Log::error('Failed to send renewal notification to user', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $admins = User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->get();
                    foreach ($admins as $admin) {
                        $admin->notify(new AdminSubscriptionRenewedNotification($subscription, $user, $walletAmount));
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to send renewal notification to admins', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return ApiResponseService::successResponse('تم تجديد الاشتراك بنجاح!', [
                    'requires_checkout' => false,
                    'subscription' => [
                        'id' => $subscription->id,
                        'plan_name' => $subscription->plan->name,
                        'starts_at' => $subscription->starts_at->format('Y-m-d H:i:s'),
                        'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                        'is_lifetime' => $subscription->isLifetime(),
                        'status' => $subscription->status,
                    ],
                ]);
            }

            // Manual payment flow
            if ($request->payment_method === 'manual') {
                $method = $this->findActiveManualPaymentMethod((string) $request->payment_method_id);
                if (!$method) {
                    return ApiResponseService::errorResponse(
                        'طريقة الدفع اليدوية هذه غير متوفرة حالياً.',
                        ['reason' => 'PAYMENT_METHOD_UNAVAILABLE'],
                        422
                    );
                }

                if ($fieldValidation = $this->validateManualPaymentFields($request, $method)) {
                    return $fieldValidation;
                }

                try {
                    $hasPending = Subscription::where('user_id', $user->id)
                        ->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                        ->exists();

                    if ($hasPending) {
                        return ApiResponseService::errorResponse(
                            'لديك بالفعل طلب اشتراك قيد المراجعة. يرجى الانتظار حتى تتم مراجعته.',
                            ['reason' => 'DUPLICATE_SUBSCRIPTION_REQUEST'],
                            409
                        );
                    }

                    $receiptPath = \App\Services\FileService::uploadPrivate(
                        $request->file('receipt'),
                        'subscriptions/receipts'
                    );

                    \Illuminate\Support\Facades\DB::beginTransaction();

                    // For renewal via manual payment, create a NEW pending_approval subscription
                    $newSubscription = Subscription::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'locked_price' => $totalAmount,
                        'locked_currency' => $resolvedCurrency,
                        'starts_at' => now(), // Placeholder, updated on approval
                        'ends_at' => null,    // Placeholder, updated on approval
                        'status' => Subscription::STATUS_PENDING_APPROVAL,
                        'auto_renew' => true,
                        'parent_subscription_id' => $subscription->id, // Link to previous sub
                    ]);

                    \App\Models\SubscriptionPayment::create([
                        'subscription_id' => $newSubscription->id,
                        'user_id' => $user->id,
                        'amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => $gatewayAmount,
                        'status' => \App\Models\SubscriptionPayment::STATUS_PENDING,
                        'payment_method' => 'manual',
                        'resolved_country' => $countryCode,
                        'currency_code' => $resolvedCurrency,
                        'price_source' => $countryPricing['price_source'] ?? 'default',
                        'payment_method_id' => $this->isManualDepositMethodId((string) $request->payment_method_id)
                            ? null : (int) $request->payment_method_id,
                        'manual_deposit_method_id' => $this->manualDepositMethodId((string) $request->payment_method_id),
                        'method_snapshot' => $this->manualPaymentMethodSnapshot($method),
                        'submitted_fields' => $this->submittedManualFields($request, $method),
                        'receipt' => $receiptPath,
                        'transaction_id' => $request->transaction_id,
                        'paid_at' => null,
                        'tax' => 0,
                        'final_amount' => $totalAmount,
                    ]);

                    \Illuminate\Support\Facades\DB::commit();

                    // Notify admins about the new manual renewal request
                    try {
                        $admins = User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->get();
                        foreach ($admins as $admin) {
                            $admin->notify(new AdminNewSubscriptionRequestNotification($newSubscription, $user));
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to notify admins of manual renewal request', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Notify user that their renewal request is under review
                    try {
                        $user->notify(new ManualRenewalRequestedNotification($newSubscription));
                    } catch (\Throwable $e) {
                        Log::error('Failed to send manual renewal pending notification to user', [
                            'user_id' => $user->id,
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    return ApiResponseService::successResponse('تم إنشاء طلب تجديد الاشتراك بنجاح وجاري مراجعة الإيصال من قبل الإدارة.', [
                        'requires_checkout' => false,
                        'subscription' => [
                            'id' => $newSubscription->id,
                            'plan_name' => $newSubscription->plan->name,
                            'status' => $newSubscription->status,
                        ],
                        'payment' => [
                            'total_amount' => $totalAmount,
                            'wallet_amount' => $walletAmount,
                            'gateway_amount' => $gatewayAmount,
                            'payment_method' => 'manual',
                        ]
                    ]);
                } catch (\Exception $e) {
                    if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                        \Illuminate\Support\Facades\DB::rollBack();
                    }
                    if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                        throw $e;
                    }
                    return ApiResponseService::errorResponse('فشل في إرسال طلب التجديد اليدوي: ' . $e->getMessage());
                }
            }

            // Gateway payment required: create Kashier checkout
            try {
                $checkout = $this->kashierService->createCheckoutSession(
                    $plan,
                    $user,
                    $gatewayAmount,
                    $resolvedCurrency,
                );
            } catch (\RuntimeException $e) {
                return ApiResponseService::errorResponse(
                    'بوابة الدفع غير مهيأة. يرجى التواصل مع الإدارة.',
                    [],
                    503
                );
            }

            // Store pending wallet amount for webhook to apply on success
            \Illuminate\Support\Facades\Cache::put('kashier_pending_' . $checkout['order_id'], [
                'wallet_amount' => $walletAmount,
                'plan_id' => $plan->id,
                'user_id' => $user->id,
            ], \App\Http\Controllers\API\SubscriptionApiController::KASHIER_PENDING_TTL);

            return ApiResponseService::successResponse('يرجى إكمال عملية الدفع عبر Kashier.', [
                'requires_checkout' => true,
                'checkout_url' => $checkout['url'],
                'order_id' => $checkout['order_id'],
                'payment' => [
                    'total_amount' => $totalAmount,
                    'wallet_amount' => $walletAmount,
                    'gateway_amount' => $gatewayAmount,
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            return ApiResponseService::errorResponse($e->getMessage(), [], 400);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to renew subscription');
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to cancel subscription');
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

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $formattedPayments = $paginator->getCollection()->map(fn($payment) => [
                'id'                   => $payment->id,
                'amount'               => (float) $payment->amount,
                'local_amount'         => $this->pricingService->convertFromEgp((float) $payment->amount, $displayCurrency),
                'wallet_amount'        => (float) $payment->wallet_amount,
                'local_wallet_amount'  => $this->pricingService->convertFromEgp((float) $payment->wallet_amount, $displayCurrency),
                'gateway_amount'       => (float) $payment->gateway_amount,
                'local_gateway_amount' => $this->pricingService->convertFromEgp((float) $payment->gateway_amount, $displayCurrency),
                'promo_code'           => $payment->promo_code,
                'original_amount'      => $payment->original_amount ? (float) $payment->original_amount : null,
                'local_original_amount' => $payment->original_amount ? $this->pricingService->convertFromEgp((float) $payment->original_amount, $displayCurrency) : null,
                'discount_amount'      => (float) $payment->discount_amount,
                'local_discount_amount' => $this->pricingService->convertFromEgp((float) $payment->discount_amount, $displayCurrency),
                'currency'             => $displayCurrency,
                'currency_symbol'      => $displaySymbol,
                'status'               => $payment->status,
                'payment_method'       => $payment->payment_method,
                'transaction_id'       => $payment->transaction_id,
                'paid_at'              => $payment->paid_at?->format('Y-m-d H:i:s'),
                'plan' => $payment->subscription?->plan ? [
                    'name'          => $payment->subscription->plan->name,
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
                'total_paid'       => (float) $totalPaid,
                'local_total_paid' => $this->pricingService->convertFromEgp((float) $totalPaid, $displayCurrency),
                'currency'         => $displayCurrency,
                'currency_symbol'  => $displaySymbol,
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to retrieve payment history');
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to update settings');
        }
    }

    /** Return a private manual-payment receipt only to its owner. */
    public function downloadReceipt(Request $request, int $payment): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $user = Auth::user();
        $record = \App\Models\SubscriptionPayment::query()
            ->whereKey($payment)
            ->where('user_id', $user?->id)
            ->first();

        if (!$record || !$record->getRawOriginal('receipt')) {
            return ApiResponseService::errorResponse('Receipt not found.', [], 404);
        }

        $receipt = $record->getRawOriginal('receipt');
        if (!\App\Services\FileService::checkPrivateFileExists($receipt)) {
            return ApiResponseService::errorResponse('Receipt is unavailable.', [], 404);
        }

        return response()->file(\App\Services\FileService::getPrivateFilePath($receipt));
    }

    private function findActiveManualPaymentMethod(string $methodId): ?PaymentMethod
    {
        $cleanId = trim($methodId);
        if ($cleanId === '') {
            return null;
        }

        if ($this->isManualDepositMethodId($cleanId)) {
            $depositId = $this->manualDepositMethodId($cleanId);
            $deposit = ManualDepositMethod::query()->whereKey($depositId)->where('is_active', true)->first();
            if (!$deposit) return null;

            $method = new PaymentMethod([
                'name' => $deposit->name,
                'type' => 'bank_transfer',
                'instructions' => $deposit->instructions ?: $deposit->account_details,
                'account_number' => $deposit->account_details,
                'logo' => $deposit->getRawOriginal('image'),
                'dynamic_fields' => [],
            ]);
            $method->setAttribute('id', $cleanId);
            return $method;
        }

        if (ctype_digit($cleanId)) {
            $idNum = (int) $cleanId;
            $paymentMethod = PaymentMethod::query()
                ->whereKey($idNum)
                ->where('is_active', true)
                ->whereIn('type', ['instapay', 'mobile_wallet', 'fawry', 'bank_transfer'])
                ->first();

            if ($paymentMethod) {
                return $paymentMethod;
            }

            // Fallback: If numeric ID was sent without manual-deposit- prefix, check ManualDepositMethod
            $deposit = ManualDepositMethod::query()->whereKey($idNum)->where('is_active', true)->first();
            if ($deposit) {
                $method = new PaymentMethod([
                    'name' => $deposit->name,
                    'type' => 'bank_transfer',
                    'instructions' => $deposit->instructions ?: $deposit->account_details,
                    'account_number' => $deposit->account_details,
                    'logo' => $deposit->getRawOriginal('image'),
                    'dynamic_fields' => [],
                ]);
                $method->setAttribute('id', "manual-deposit-{$deposit->id}");
                return $method;
            }
        }

        return null;
    }

    private function isManualDepositMethodId(string $methodId): bool
    {
        return preg_match('/^manual-deposit-[1-9][0-9]*$/', $methodId) === 1;
    }

    private function manualDepositMethodId(string $methodId): ?int
    {
        return $this->isManualDepositMethodId($methodId)
            ? (int) substr($methodId, strlen('manual-deposit-'))
            : null;
    }

    private function validateManualPaymentFields(Request $request, PaymentMethod $method): ?JsonResponse
    {
        $definitions = is_array($method->dynamic_fields) ? $method->dynamic_fields : [];
        if ($definitions === []) {
            return null;
        }

        $rules = ['payment_fields' => 'required|array'];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $key = $definition['key'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $typeRule = match ($definition['type'] ?? 'text') {
                'number' => 'numeric',
                'email' => 'email',
                default => 'string',
            };
            $rules["payment_fields.{$key}"] = [
                !empty($definition['required']) ? 'required' : 'nullable',
                $typeRule,
                'max:1000',
            ];

            $validation = $definition['validation'] ?? null;
            if ($validation === 'alphanumeric') {
                $rules["payment_fields.{$key}"][] = 'regex:/^[A-Za-z0-9]+$/';
            } elseif ($validation === 'phone') {
                $rules["payment_fields.{$key}"][] = 'regex:/^[0-9+()\\-\\s]+$/';
            } elseif ($validation === 'reference') {
                $rules["payment_fields.{$key}"][] = 'regex:/^[A-Za-z0-9._\\-\\/]+$/';
            }
        }

        $validator = Validator::make($request->all(), $rules);

        return $validator->fails()
            ? ApiResponseService::validationError($validator->errors()->first())
            : null;
    }

    private function submittedManualFields(Request $request, PaymentMethod $method): array
    {
        $input = $request->input('payment_fields', []);
        if (!is_array($input)) {
            return [];
        }

        $allowedKeys = collect($method->dynamic_fields ?? [])
            ->filter('is_array')
            ->pluck('key')
            ->filter(static fn ($key): bool => is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key))
            ->all();

        return array_intersect_key($input, array_flip($allowedKeys));
    }

    private function manualPaymentMethodSnapshot(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'type' => $method->type,
            'instructions' => $method->instructions,
            'account_name' => $method->account_name,
            'account_number' => $method->account_number,
            'instapay_id' => $method->instapay_id,
            'merchant_code' => $method->merchant_code,
            'dynamic_fields' => $method->dynamic_fields ?? [],
        ];
    }
}
