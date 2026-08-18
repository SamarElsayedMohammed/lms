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
        private readonly \App\Services\SubscriptionPromoService $promoService,
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

            $isEntitledNow = function ($sub): bool {
                if ($sub->status !== Subscription::STATUS_ACTIVE) {
                    return false;
                }
                if ($sub->starts_at && $sub->starts_at->isFuture()) {
                    return false;
                }
                if ($sub->ends_at && $sub->ends_at->isPast()) {
                    return false;
                }
                return true;
            };

            $currentActive = $subscriptions->first($isEntitledNow);
            $hasAccess = $currentActive !== null;

            $formatSubscription = function ($subscription) use ($countryCode, $displayCurrency, $displaySymbol, $isEntitledNow): array {
                $isCurrentlyActive = $isEntitledNow($subscription);
                $statusLabel = match($subscription->status) {
                    Subscription::STATUS_ACTIVE           => $isCurrentlyActive ? 'Active' : ($subscription->ends_at?->isPast() ? 'Expired' : 'Scheduled'),
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

                $daysRemaining = $isCurrentlyActive ? $subscription->days_remaining : null;
                $durationDays  = $subscription->plan?->getDurationDays();

                $latestPayment = $subscription->payments()->latest()->first();
                $paymentMethod = $latestPayment?->payment_method ?? 'manual';
                $amountPaid = (float) ($latestPayment?->final_amount ?? $subscription->locked_price ?? $nextPaymentAmount);
                $originalAmount = (float) ($latestPayment?->original_amount ?? $subscription->locked_price ?? $nextPaymentAmount);
                $discountAmount = (float) ($latestPayment?->discount_amount ?? 0);
                $promoCodeUsed = $latestPayment?->promo_code;

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
                    'starts_at'           => $subscription->starts_at?->format('Y-m-d H:i:s'),
                    'ends_at'             => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'days_remaining'      => $daysRemaining,
                    'duration_days'       => $durationDays,
                    'is_lifetime'         => $subscription->isLifetime(),
                    'auto_renew'          => (bool) $subscription->auto_renew,
                    'can_toggle_auto_renew' => !$isStoreManaged && !$subscription->isLifetime() && $isCurrentlyActive,
                    'is_store_managed'    => $isStoreManaged,
                    'provider'            => $isStoreManaged ? $paymentMethod : 'manual',
                    'status'              => $subscription->status,
                    'status_label'        => $statusLabel,
                    'created_at'          => $subscription->created_at->format('Y-m-d H:i:s'),
                    'renewal_date'        => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'payment_method'      => $paymentMethod,
                    'amount'              => $amountPaid,
                    'paid_amount'         => $amountPaid,
                    'original_amount'     => $originalAmount,
                    'discount_amount'     => $discountAmount,
                    'promo_code'          => $promoCodeUsed,
                    'renewal_price'       => $nextPaymentAmount,
                    'next_payment_amount' => $nextPaymentAmount,
                    'currency'            => $latestPayment?->currency_code ?? $nextPaymentCurrency,
                    'currency_symbol'     => $nextPaymentSymbol,
                    'receipt_url'         => $latestPayment?->getRawOriginal('receipt')
                        ? route('subscription.receipt', ['payment' => $latestPayment->id])
                        : null,
                ];
            };

            $formattedSubscriptions = $subscriptions->map($formatSubscription);
            $isAffiliateEnabled = $this->affiliateService->isEnabled();

            $activeFormatted = $currentActive ? $formatSubscription($currentActive) : null;

            // Separate queued/scheduled future subscriptions from pending approval
            $upcomingSubs = $subscriptions->filter(function ($sub) use ($isEntitledNow) {
                if ($isEntitledNow($sub)) {
                    return false;
                }
                return $sub->status === Subscription::STATUS_PENDING
                    || ($sub->status === Subscription::STATUS_ACTIVE && $sub->starts_at && $sub->starts_at->isFuture());
            })->sortBy(fn($sub) => $sub->starts_at?->timestamp ?? 0);

            $formattedUpcomingList = $upcomingSubs->map($formatSubscription)->values();
            $upcomingFormatted = $formattedUpcomingList->first();

            $pendingApprovalSub = $subscriptions->firstWhere('status', Subscription::STATUS_PENDING_APPROVAL);
            $pendingApprovalFormatted = $pendingApprovalSub ? $formatSubscription($pendingApprovalSub) : null;

            $primarySubscription = $activeFormatted ?? $upcomingFormatted ?? $pendingApprovalFormatted ?? $formattedSubscriptions->first();

            return ApiResponseService::successResponse('Subscription status retrieved successfully', [
                'has_access'                   => $hasAccess,
                'currency'                     => $displayCurrency,
                'currency_symbol'              => $displaySymbol,
                'affiliate_system_enabled'     => $isAffiliateEnabled,
                'wallet_payment_enabled'       => $isAffiliateEnabled,
                'can_renew_with_wallet'        => $isAffiliateEnabled,
                'wallet_balance'               => (float) $user->wallet_balance,
                'subscriptions'                => $formattedSubscriptions,
                'subscription'                 => $primarySubscription,
                'upcoming_subscription'        => $upcomingFormatted,
                'upcoming_subscriptions'       => $formattedUpcomingList,
                'pending_approval_subscription'=> $pendingApprovalFormatted,
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

            $autoEnabledSetting = \App\Models\Setting::where('name', 'automatic_payments_enabled')->value('value');
            $manualEnabledSetting = \App\Models\Setting::where('name', 'manual_payments_enabled')->value('value');

            $automaticPaymentsEnabled = $autoEnabledSetting !== null ? filter_var($autoEnabledSetting, FILTER_VALIDATE_BOOLEAN) : false;
            $manualPaymentsEnabled = $manualEnabledSetting !== null ? filter_var($manualEnabledSetting, FILTER_VALIDATE_BOOLEAN) : true;

            $manualMethods = collect([]);
            if ($manualPaymentsEnabled) {
                $manualMethodsQuery = PaymentMethod::query()
                    ->where('is_active', true)
                    ->where('type', '!=', 'online')
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
            }

            $electronicGateways = collect([]);
            if ($automaticPaymentsEnabled) {
                $kashierSetting = \App\Models\Setting::where('name', 'payment_gateway_kashier')->value('value');
                $kashierConfig = $kashierSetting ? json_decode($kashierSetting, true) : null;
                $kashierActive = $kashierConfig ? (bool) ($kashierConfig['enabled'] ?? true) : true;

                if ($kashierActive) {
                    $electronicGateways->push([
                        'id' => 'kashier',
                        'name' => 'Kashier (بطاقات بنكية ومحافظ)',
                        'type' => 'online',
                        'code' => 'kashier',
                        'logo_url' => null,
                        'is_active' => true,
                    ]);
                }
            }

            return ApiResponseService::successResponse('Payment methods retrieved successfully', [
                'electronic_gateways' => $electronicGateways->values(),
                'manual_methods' => $manualMethods->values(),
                'online' => $automaticPaymentsEnabled && $electronicGateways->isNotEmpty(),
                'wallet' => false,
                'automatic_payments_enabled' => $automaticPaymentsEnabled,
                'manual_payments_enabled' => $manualPaymentsEnabled,
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

        $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        $countryCode = $user?->country_code ?? $this->countryDetectionService->detect($request);

        $result = $this->promoService->validatePromo(
            $request->promo_code,
            (int) $request->plan_id,
            $user,
            $countryCode
        );

        if (!$result['valid']) {
            return ApiResponseService::validationError($result['message']);
        }

        return ApiResponseService::successResponse('Promo code is valid', [
            'valid' => true,
            'is_valid' => true,
            'discount_amount' => $result['discount_amount'],
            'discount_value' => $result['discount_value'],
            'discount' => $result['discount_value'],
            'discount_percent' => $result['discount_percent'],
            'discount_percentage' => $result['discount_percent'],
            'original_amount' => $result['original_amount'],
            'total_amount' => $result['total_amount'],
            'currency' => $result['currency'],
            'discount_type' => $result['discount_type'],
            'message' => $result['message'],
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

        $appliedPromoCode = null;

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
            $discountAmount = 0;            // Authoritative promo validation & reservation (DEF-01, DEF-03, DEF-04)
            if ($request->filled('promo_code')) {
                $promoValidation = $this->promoService->validatePromo(
                    $request->promo_code,
                    $plan->id,
                    $user,
                    $countryCode
                );

                if (!$promoValidation['valid']) {
                    return ApiResponseService::validationError($promoValidation['message']);
                }

                $reserveResult = $this->promoService->reservePromo(
                    $request->promo_code,
                    $user->id,
                    $plan->id,
                    $countryCode
                );

                if (!$reserveResult['success']) {
                    return ApiResponseService::validationError($reserveResult['message'] ?? 'كود الخصم غير متاح.');
                }

                $appliedPromoCode = $reserveResult['promo']->promo_code;
                $discountAmount = (float) $promoValidation['discount_amount'];
                $totalAmount = (float) $promoValidation['total_amount'];
            } else {
                $totalAmount = $originalAmount;
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

            $autoEnabledSetting = \App\Models\Setting::where('name', 'automatic_payments_enabled')->value('value');
            $manualEnabledSetting = \App\Models\Setting::where('name', 'manual_payments_enabled')->value('value');
            $automaticPaymentsEnabled = $autoEnabledSetting !== null ? filter_var($autoEnabledSetting, FILTER_VALIDATE_BOOLEAN) : false;
            $manualPaymentsEnabled = $manualEnabledSetting !== null ? filter_var($manualEnabledSetting, FILTER_VALIDATE_BOOLEAN) : true;

            if ($request->payment_method === 'manual') {
                if (!$manualPaymentsEnabled) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    return ApiResponseService::errorResponse('طرق الدفع اليدوية غير متاحة حالياً.', ['reason' => 'MANUAL_PAYMENTS_DISABLED'], 422);
                }
            } else {
                if ($gatewayAmount > 0 && !$automaticPaymentsEnabled) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    return ApiResponseService::errorResponse('طرق الدفع الإلكترونية التلقائية غير متاحة حالياً.', ['reason' => 'AUTOMATIC_PAYMENTS_DISABLED'], 422);
                }
            }

            // Full wallet payment: create subscription immediately
            if ($gatewayAmount <= 0) {
                try {
                    $discountMeta = [
                        'promo_code' => $appliedPromoCode,
                        'original_amount' => $originalAmount,
                        'discount_amount' => $discountAmount,
                        'total_amount' => $totalAmount,
                        'currency_code' => $resolvedCurrency,
                        'resolved_country' => $countryCode,
                        'price_source' => $countryPricing['price_source'] ?? 'default',
                    ];

                    $subscription = $this->subscriptionService->createSubscription(
                        $user,
                        $plan,
                        'wallet',
                        $walletAmount,
                        0.0,
                        $discountMeta
                    );

                    // Notify user and admins about successful subscription
                    try {
                        $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                    } catch (\Throwable $e) {
                        Log::error('Failed to send activation notification to user', [
                            'user_id' => $user->id,
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    return ApiResponseService::successResponse('تم تفعيل الاشتراك بنجاح!', [
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
                } catch (\Throwable $e) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    throw $e;
                }
            }

            // Manual payment flow
            if ($request->payment_method === 'manual') {
                $method = $this->findActiveManualPaymentMethod((string) $request->payment_method_id);
                if (!$method) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    return ApiResponseService::errorResponse(
                        'طريقة الدفع اليدوية هذه غير متوفرة حالياً.',
                        ['reason' => 'PAYMENT_METHOD_UNAVAILABLE'],
                        422
                    );
                }

                if ($methodAvailabilityError = $this->validateManualPaymentMethodAvailability(
                    $method,
                    $countryCode,
                    $resolvedCurrency,
                    $gatewayAmount,
                )) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    return $methodAvailabilityError;
                }

                if ($fieldValidation = $this->validateManualPaymentFields($request, $method)) {
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
                    }
                    return $fieldValidation;
                }

                $receiptPath = null;
                try {
                    $hasPending = Subscription::where('user_id', $user->id)
                        ->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                        ->exists();

                    if ($hasPending) {
                        if ($appliedPromoCode) {
                            $this->promoService->releasePromo($appliedPromoCode);
                        }
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

                    $newSubscription = \Illuminate\Support\Facades\DB::transaction(function () use (
                        $user,
                        $plan,
                        $totalAmount,
                        $resolvedCurrency,
                        $walletAmount,
                        $gatewayAmount,
                        $countryCode,
                        $countryPricing,
                        $request,
                        $method,
                        $receiptPath,
                        $appliedPromoCode,
                        $originalAmount,
                        $discountAmount
                    ) {
                        $subscription = Subscription::create([
                            'user_id' => $user->id,
                            'plan_id' => $plan->id,
                            'locked_price' => $totalAmount,
                            'locked_currency' => $resolvedCurrency,
                            'starts_at' => now(),
                            'ends_at' => null,
                            'status' => Subscription::STATUS_PENDING_APPROVAL,
                            'auto_renew' => true,
                        ]);

                        $payment = \App\Models\SubscriptionPayment::create([
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
                            'original_amount' => $originalAmount,
                            'discount_amount' => $discountAmount,
                            'final_amount' => $totalAmount,
                            'paid_at' => null,
                            'tax' => 0,
                        ]);

                        if (!empty($appliedPromoCode)) {
                            \App\Models\PromoRedemption::where('user_id', $user->id)
                                ->where(function ($q) use ($appliedPromoCode) {
                                    $q->where('promo_code', $appliedPromoCode)
                                      ->orWhereRaw('UPPER(promo_code) = ?', [strtoupper($appliedPromoCode)]);
                                })
                                ->where('status', \App\Models\PromoRedemption::STATUS_RESERVED)
                                ->whereNull('subscription_payment_id')
                                ->latest('id')
                                ->update([
                                    'subscription_id' => $subscription->id,
                                    'subscription_payment_id' => $payment->id,
                                ]);
                        }

                        // Hold wallet amount if used
                        if ($walletAmount > 0) {
                            \App\Services\WalletService::debitWallet(
                                $user->id,
                                $walletAmount,
                                'subscription',
                                "Hold for manual subscription #{$subscription->id}",
                                $subscription->id,
                                \App\Models\Subscription::class,
                                'user'
                            );
                        }

                        return $subscription;
                    });

                    // Notify super-admins
                    try {
                        $newSubscription->load('plan');
                        $admins = User::query()->get()->filter(static function (User $candidate): bool {
                            return $candidate->hasAnyRole([
                                config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
                                config('constants.SYSTEM_ROLES.SUPERVISOR'),
                                config('constants.SYSTEM_ROLES.STAFF'),
                            ], 'web')
                                && $candidate->can('finance-list')
                                && $candidate->can('finance-edit');
                        });
                        foreach ($admins as $admin) {
                            $admin->notify(new AdminNewSubscriptionRequestNotification($newSubscription, $user));
                        }
                    } catch (\Throwable $e) {
                        Log::error('SubscriptionApiController: Failed to notify admins of new manual subscription request', [
                            'subscription_id' => $newSubscription->id,
                            'user_id'         => $user->id,
                            'error'           => $e->getMessage(),
                        ]);
                    }

                    return ApiResponseService::successResponse('تم إنشاء طلب الدفع بنجاح وجاري مراجعة الطلب من قبل الإدارة.', [
                        'requires_checkout' => false,
                        'subscription' => [
                            'id' => $newSubscription->id,
                            'plan_name' => $plan->name,
                            'starts_at' => $newSubscription->starts_at->format('Y-m-d H:i:s'),
                            'ends_at' => null,
                            'status' => $newSubscription->status,
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
                    if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                        throw $e;
                    }
                    if ($receiptPath) {
                        try {
                            \App\Services\FileService::delete($receiptPath);
                        } catch (\Throwable $fileEx) {
                            Log::warning('Failed to clean up orphan receipt after rollback: ' . $fileEx->getMessage());
                        }
                    }
                    if ($appliedPromoCode) {
                        $this->promoService->releasePromo($appliedPromoCode);
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
                if ($appliedPromoCode) {
                    $this->promoService->releasePromo($appliedPromoCode);
                }
                return ApiResponseService::errorResponse(
                    'بوابة الدفع غير مهيأة. يرجى التواصل مع الإدارة.',
                    [],
                    503
                );
            }

            // Create durable pending subscription and pending SubscriptionPayment (DEF-02)
            try {
                [$pendingSub, $pendingPayment] = \Illuminate\Support\Facades\DB::transaction(function () use (
                    $user,
                    $plan,
                    $totalAmount,
                    $resolvedCurrency,
                    $walletAmount,
                    $gatewayAmount,
                    $countryCode,
                    $countryPricing,
                    $appliedPromoCode,
                    $originalAmount,
                    $discountAmount,
                    $checkout
                ) {
                    $pendingSub = Subscription::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'locked_price' => $totalAmount,
                        'locked_currency' => $resolvedCurrency,
                        'starts_at' => now(),
                        'ends_at' => null,
                        'status' => Subscription::STATUS_PENDING,
                        'auto_renew' => true,
                    ]);

                    $pendingPayment = \App\Models\SubscriptionPayment::create([
                        'subscription_id' => $pendingSub->id,
                        'user_id' => $user->id,
                        'amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => $gatewayAmount,
                        'status' => \App\Models\SubscriptionPayment::STATUS_PENDING,
                        'payment_method' => 'kashier',
                        'resolved_country' => $countryCode,
                        'currency_code' => $resolvedCurrency,
                        'price_source' => $countryPricing['price_source'] ?? 'default',
                        'promo_code' => $appliedPromoCode,
                        'original_amount' => $originalAmount,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $totalAmount,
                        'transaction_id' => $checkout['order_id'],
                        'tax' => 0,
                        'paid_at' => null,
                    ]);

                    return [$pendingSub, $pendingPayment];
                });
            } catch (\Throwable $dbEx) {
                if ($appliedPromoCode) {
                    $this->promoService->releasePromo($appliedPromoCode);
                }
                throw $dbEx;
            }

            // Cache copy for fast lookup
            Cache::put('kashier_pending_' . $checkout['order_id'], [
                'subscription_id' => $pendingSub->id,
                'payment_id' => $pendingPayment->id,
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
                'total_amount' => $totalAmount,
                'plan_id' => $plan->id,
                'user_id' => $user->id,
            ], self::KASHIER_PENDING_TTL);

            return ApiResponseService::successResponse('يرجى إكمال عملية الدفع عبر Kashier.', [
                'requires_checkout' => true,
                'checkout_url' => $checkout['url'],
                'order_id' => $checkout['order_id'],
                'payment' => [
                    'total_amount' => $totalAmount,
                    'wallet_amount' => $walletAmount,
                    'gateway_amount' => $gatewayAmount,
                    'payment_method' => 'kashier',
                ],
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (!empty($appliedPromoCode)) {
                $this->promoService->releasePromo($appliedPromoCode);
            }
            return ApiResponseService::errorResponse('فشل في بدء عملية الدفع: ' . $e->getMessage());
        }
    }

    /**
     * Renew subscription
     */
    public function renew(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.', [], 401);
            }

            $subscription = null;
            if ($request->filled('subscription_id')) {
                $subscription = Subscription::where('id', $request->subscription_id)
                    ->where('user_id', $user->id)
                    ->with('plan')
                    ->first();
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

            $autoEnabledSetting = \App\Models\Setting::where('name', 'automatic_payments_enabled')->value('value');
            $manualEnabledSetting = \App\Models\Setting::where('name', 'manual_payments_enabled')->value('value');
            $automaticPaymentsEnabled = $autoEnabledSetting !== null ? filter_var($autoEnabledSetting, FILTER_VALIDATE_BOOLEAN) : false;
            $manualPaymentsEnabled = $manualEnabledSetting !== null ? filter_var($manualEnabledSetting, FILTER_VALIDATE_BOOLEAN) : true;

            if ($request->payment_method === 'manual') {
                if (!$manualPaymentsEnabled) {
                    return ApiResponseService::errorResponse('طرق الدفع اليدوية غير متاحة حالياً.', ['reason' => 'MANUAL_PAYMENTS_DISABLED'], 422);
                }
            } else {
                if ($gatewayAmount > 0 && !$automaticPaymentsEnabled) {
                    return ApiResponseService::errorResponse('طرق الدفع الإلكترونية التلقائية غير متاحة حالياً.', ['reason' => 'AUTOMATIC_PAYMENTS_DISABLED'], 422);
                }
            }

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

                if ($methodAvailabilityError = $this->validateManualPaymentMethodAvailability(
                    $method,
                    $countryCode,
                    $resolvedCurrency,
                    $gatewayAmount,
                )) {
                    return $methodAvailabilityError;
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

                    \Illuminate\Support\Facades\DB::transaction(function () use (
                        $user,
                        $plan,
                        $totalAmount,
                        $resolvedCurrency,
                        $walletAmount,
                        $gatewayAmount,
                        $countryCode,
                        $countryPricing,
                        $request,
                        $method,
                        $receiptPath,
                        $subscription,
                        &$newSubscription
                    ) {
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
                    });

                    // Notify admins about the new manual renewal request
                    try {
                        $admins = User::query()->get()->filter(static function (User $candidate): bool {
                            return $candidate->hasAnyRole([
                                config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
                                config('constants.SYSTEM_ROLES.SUPERVISOR'),
                                config('constants.SYSTEM_ROLES.STAFF'),
                            ], 'web')
                                && $candidate->can('finance-list')
                                && $candidate->can('finance-edit');
                        });
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
                'gateway_amount' => $gatewayAmount,
                'total_amount' => $totalAmount,
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

            $latestPayment = $subscription->payments()->latest()->first();
            $paymentMethod = $latestPayment?->payment_method ?? '';
            $isStoreManaged = in_array($paymentMethod, ['google', 'apple', 'in_app_purchase', 'play_store', 'app_store'], true);

            if ($isStoreManaged) {
                return ApiResponseService::errorResponse('يتم إلغاء اشتراكات المتاجر حصرياً عبر متجر التطبيقات الخاص بك (Google Play / App Store).', [], 422);
            }

            $result = $this->subscriptionService->cancelSubscription($subscription, $request->reason);

            if ($result) {
                return ApiResponseService::successResponse('تم إيقاف التجديد التلقائي للاشتراك بنجاح، وستظل صلاحية الوصول متاحة حتى نهاية الفترة المدفوعة.', [
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
     * Return the current state of a subscription payment to its owner.
     * This is used after returning from a gateway and must never expose a
     * payment belonging to another account.
     */
    public function getPaymentStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'nullable|string|max:255|required_without_all:subscription_id,checkout_id,payment_id',
            'subscription_id' => 'nullable|integer|required_without_all:transaction_id,checkout_id,payment_id',
            'checkout_id' => 'nullable|string|max:255|required_without_all:transaction_id,subscription_id,payment_id',
            'payment_id' => 'nullable|string|max:255|required_without_all:transaction_id,subscription_id,checkout_id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Authentication required.', [], 401);
        }

        $payment = \App\Models\SubscriptionPayment::query()
            ->with('subscription.plan')
            ->where('user_id', $user->id)
            ->where(function ($query) use ($request): void {
                if ($request->filled('transaction_id')) {
                    $query->orWhere('transaction_id', $request->string('transaction_id')->toString());
                }
                if ($request->filled('payment_id')) {
                    $query->orWhereKey($request->string('payment_id')->toString());
                }
                if ($request->filled('subscription_id')) {
                    $query->orWhere('subscription_id', $request->integer('subscription_id'));
                }
                if ($request->filled('checkout_id')) {
                    $query->orWhere('transaction_id', $request->string('checkout_id')->toString());
                }
            })
            ->latest('id')
            ->first();

        if (!$payment || !$payment->subscription) {
            return ApiResponseService::errorResponse('Payment was not found.', [], 404);
        }

        $subscription = $payment->subscription;

        return ApiResponseService::successResponse('Payment status retrieved successfully.', [
            'payment' => [
                'id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'subscription_status' => $subscription->status,
            ],
            'subscription' => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_name' => $subscription->plan?->name,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
            ],
        ]);
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

            $formattedPayments = $paginator->getCollection()->map(function ($payment) use ($displayCurrency) {
                $histCurrency = $payment->currency_code ?: 'EGP';
                $histSymbol = $this->pricingService->getCurrencySymbol($histCurrency);
                $finalAmt = (float) ($payment->final_amount ?? $payment->amount);
                $receiptRaw = $payment->getRawOriginal('receipt');

                return [
                    'id'                   => $payment->id,
                    'amount'               => $finalAmt,
                    'local_amount'         => $this->pricingService->convertFromEgp((float) ($payment->amount_egp ?? $finalAmt), $displayCurrency),
                    'wallet_amount'        => (float) ($payment->wallet_amount ?? 0),
                    'local_wallet_amount'  => $this->pricingService->convertFromEgp((float) ($payment->wallet_amount ?? 0), $displayCurrency),
                    'gateway_amount'       => (float) ($payment->gateway_amount ?? 0),
                    'local_gateway_amount' => $this->pricingService->convertFromEgp((float) ($payment->gateway_amount ?? 0), $displayCurrency),
                    'promo_code'           => $payment->promo_code,
                    'original_amount'      => $payment->original_amount ? (float) $payment->original_amount : null,
                    'local_original_amount' => $payment->original_amount ? $this->pricingService->convertFromEgp((float) $payment->original_amount, $displayCurrency) : null,
                    'discount_amount'      => (float) ($payment->discount_amount ?? 0),
                    'local_discount_amount' => $this->pricingService->convertFromEgp((float) ($payment->discount_amount ?? 0), $displayCurrency),
                    'currency'             => $histCurrency,
                    'currency_symbol'      => $histSymbol,
                    'status'               => $payment->status,
                    'payment_method'       => $payment->payment_method,
                    'transaction_id'       => $payment->transaction_id,
                    'receipt_url'          => $receiptRaw ? route('subscription.receipt', ['payment' => $payment->id]) : null,
                    'paid_at'              => $payment->paid_at?->format('Y-m-d H:i:s'),
                    'plan' => $payment->subscription?->plan ? [
                        'name'          => $payment->subscription->plan->name,
                        'billing_cycle' => $payment->subscription->plan->billing_cycle_label,
                    ] : null,
                    'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
                ];
            });

            $completedPayments = \App\Models\SubscriptionPayment::where('user_id', $user->id)
                ->where('status', \App\Models\SubscriptionPayment::STATUS_COMPLETED)
                ->get();
            
            $transactionsCount = $completedPayments->count();
            $totalsByCurrency = [];
            $totalEgpEquivalent = 0.0;

            foreach ($completedPayments as $p) {
                $curr = $p->currency_code ?: 'EGP';
                $amt = (float) ($p->final_amount ?? $p->amount);
                $totalsByCurrency[$curr] = ($totalsByCurrency[$curr] ?? 0.0) + $amt;
                $totalEgpEquivalent += (float) ($p->amount_egp ?? ($amt * ($p->exchange_rate_snapshot ?? 1.0)));
            }

            $primaryTotalPaid = count($totalsByCurrency) === 1
                ? array_values($totalsByCurrency)[0]
                : (float) $totalEgpEquivalent;

            return ApiResponseService::successResponse('Payment history retrieved successfully', [
                'total_paid'         => (float) $primaryTotalPaid,
                'totals_by_currency' => $totalsByCurrency,
                'local_total_paid'   => $this->pricingService->convertFromEgp((float) $totalEgpEquivalent, $displayCurrency),
                'currency'           => count($totalsByCurrency) === 1 ? array_keys($totalsByCurrency)[0] : $displayCurrency,
                'currency_symbol'    => count($totalsByCurrency) === 1 ? $this->pricingService->getCurrencySymbol(array_keys($totalsByCurrency)[0]) : $displaySymbol,
                'transactions_count' => $transactionsCount,
                'payments'           => $formattedPayments,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
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

            $activeSub = $this->subscriptionService->getActiveSubscription($user);
            if (!$activeSub) {
                return ApiResponseService::errorResponse('لا يوجد اشتراك نشط لتحديث الإعدادات.', [], 400);
            }

            $latestPayment = $activeSub->payments()->latest()->first();
            $paymentMethod = $latestPayment?->payment_method ?? '';
            $isStoreManaged = in_array($paymentMethod, ['google', 'apple', 'in_app_purchase', 'play_store', 'app_store'], true);

            if ($isStoreManaged) {
                return ApiResponseService::errorResponse('يتم التحكم في التجديد التلقائي لاشتراكات المتاجر حصرياً عبر متجر التطبيقات الخاص بك (Google Play / App Store).', [], 422);
            }

            $subscription = $this->subscriptionService->updateUserSettings($user, $request->only([
                'auto_renew',
            ]));

            return ApiResponseService::successResponse('تم تحديث الإعدادات بنجاح.', [
                'auto_renew' => $subscription->auto_renew,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::fail($e, 'Failed to update settings');
        }
    }

    /** Return a private manual-payment receipt only to its owner or authorized administrator. */
    public function downloadReceipt(Request $request, int $payment): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated.', [], 401);
        }

        $query = \App\Models\SubscriptionPayment::query()->whereKey($payment);

        $role = strtolower((string) ($user->role ?? $user->type ?? ''));
        $isAdmin = in_array($role, ['admin', 'super_admin', 'superadmin'], true)
            || (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super_admin')));

        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        $record = $query->first();

        if (!$record || !$record->getRawOriginal('receipt')) {
            return ApiResponseService::errorResponse('Receipt not found.', [], 404);
        }

        $receipt = $record->getRawOriginal('receipt');
        if (!\App\Services\FileService::checkPrivateFileExists($receipt)) {
            return ApiResponseService::errorResponse('Receipt is unavailable.', [], 404);
        }

        $filePath = \App\Services\FileService::getPrivateFilePath($receipt);
        $mimeType = @mime_content_type($filePath) ?: 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
        ]);
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
                ->where('type', '!=', 'online')
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

    /**
     * Enforce the availability rules configured by finance for a manual method.
     * These checks must run again at submission time; hiding a method in the
     * client is not an authorization or financial control.
     */
    private function validateManualPaymentMethodAvailability(
        PaymentMethod $method,
        string $countryCode,
        string $currencyCode,
        float $amount,
    ): ?JsonResponse {
        $allowedCountries = array_map('strtoupper', is_array($method->countries) ? $method->countries : []);
        if ($allowedCountries !== [] && !in_array(strtoupper($countryCode), $allowedCountries, true)) {
            return ApiResponseService::errorResponse(
                'Payment method is unavailable for this country.',
                ['reason' => 'PAYMENT_METHOD_COUNTRY_UNAVAILABLE'],
                422,
            );
        }

        $allowedCurrencies = array_map('strtoupper', is_array($method->currencies) ? $method->currencies : []);
        if ($allowedCurrencies !== [] && !in_array(strtoupper($currencyCode), $allowedCurrencies, true)) {
            return ApiResponseService::errorResponse(
                'Payment method is unavailable for this currency.',
                ['reason' => 'PAYMENT_METHOD_CURRENCY_UNAVAILABLE'],
                422,
            );
        }

        if ($method->min_amount !== null && $amount < (float) $method->min_amount) {
            return ApiResponseService::errorResponse(
                'Payment amount is below this method minimum.',
                ['reason' => 'PAYMENT_METHOD_MIN_AMOUNT'],
                422,
            );
        }

        if ($method->max_amount !== null && $amount > (float) $method->max_amount) {
            return ApiResponseService::errorResponse(
                'Payment amount exceeds this method maximum.',
                ['reason' => 'PAYMENT_METHOD_MAX_AMOUNT'],
                422,
            );
        }

        return null;
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
            ->filter(static fn ($item): bool => is_array($item))
            ->pluck('key')
            ->filter(static fn ($key): bool => is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key))
            ->all();

        return array_intersect_key($input, array_flip($allowedKeys));
    }

    private function manualPaymentMethodSnapshot(PaymentMethod $method): array
    {
        $instructions = $method->instructions;
        if (is_string($instructions) && str_starts_with(trim($instructions), '{') && str_ends_with(trim($instructions), '}')) {
            $decoded = json_decode($instructions, true);
            if (is_array($decoded)) {
                $nonNull = array_filter($decoded, fn($v) => !empty($v));
                $instructions = count($nonNull) > 0 ? ($decoded['instructions'] ?? null) : null;
            }
        }

        $accountNumber = $method->account_number;
        if (is_string($accountNumber) && str_starts_with(trim($accountNumber), '{') && str_ends_with(trim($accountNumber), '}')) {
            $decoded = json_decode($accountNumber, true);
            if (is_array($decoded)) {
                $accountNumber = $decoded['account_number'] ?? $decoded['instapay_id'] ?? null;
            }
        }

        return [
            'id' => $method->id,
            'name' => $method->name,
            'type' => $method->type,
            'instructions' => $instructions,
            'account_name' => $method->account_name,
            'account_number' => $accountNumber,
            'instapay_id' => $method->instapay_id,
            'merchant_code' => $method->merchant_code,
            'dynamic_fields' => $method->dynamic_fields ?? [],
        ];
    }
}
