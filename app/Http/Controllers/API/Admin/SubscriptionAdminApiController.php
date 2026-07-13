<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\ApiResponseService;
use App\Services\AffiliateService;
use App\Notifications\ManualSubscriptionStatusNotification;
use App\Notifications\SubscriptionActivatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class SubscriptionAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Comprehensive Dashboard for All Subscriptions (Manual & Automatic)
     * Returns statistics and a paginated list of subscriptions.
     */
    public function comprehensiveIndex(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        // --- 1. Calculate Statistics ---
        
        $totalSubscriptions = Subscription::count();
        $activeSubscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)->count();
        
        // Total Revenue from completed payments (Converted to EGP)
        $totalRevenue = SubscriptionPayment::where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
            ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
            ->sum(DB::raw('subscription_payments.final_amount * ' . $this->getEgpExchangeRateSql()));
        
        // Split by payment method (manual vs others/auto)
        // Assuming 'manual' is the specific string used for manual payments, and others like 'kashier', 'wallet', 'stripe' are automatic.
        $manualPaymentsCount = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)
                                ->where('payment_method', 'manual')
                                ->count();
                                
        $automaticPaymentsCount = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)
                                ->where('payment_method', '!=', 'manual')
                                ->count();

        $statistics = [
            'total_subscriptions' => $totalSubscriptions,
            'active_subscriptions' => $activeSubscriptions,
            'total_revenue' => (float) $totalRevenue,
            'manual_subscriptions_count' => $manualPaymentsCount,
            'automatic_subscriptions_count' => $automaticPaymentsCount,
        ];

        // --- 2. Retrieve Paginated List ---
        
        $query = Subscription::with(['user:id,name,email,profile', 'plan', 'payments' => function($q) {
            $q->latest();
        }]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $method = $request->payment_method;
            if ($method === 'manual') {
                $query->whereHas('payments', function ($q) {
                    $q->where('payment_method', 'manual');
                });
            } else {
                $query->whereHas('payments', function ($q) {
                    $q->where('payment_method', '!=', 'manual');
                });
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $subscriptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return ApiResponseService::successResponse('Subscriptions dashboard retrieved successfully', [
            'statistics' => $statistics,
            'subscriptions' => $subscriptions
        ]);
    }

    /**
     * List subscriptions with optional status and search filters
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $query = Subscription::with(['user', 'plan', 'payments.manualDepositMethod']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $subscriptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return ApiResponseService::successResponse('Subscriptions retrieved successfully', $subscriptions);
    }

    /**
     * Approve manual subscription payment and activate subscription
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $this->ensureAdmin();

        try {
            DB::beginTransaction();

            $subscription = Subscription::with(['user', 'plan' => function ($query) {
                $query->withTrashed();
            }])->lockForUpdate()->find($id);

            if (!$subscription) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الاشتراك غير موجود.');
            }

            if (!$subscription->plan) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الباقة المرتبطة بهذا الاشتراك غير موجودة.');
            }

            if ($subscription->status !== Subscription::STATUS_PENDING_APPROVAL) {
                DB::rollBack();
                return ApiResponseService::errorResponse('هذا الاشتراك ليس بانتظار الموافقة.');
            }

            $payment = SubscriptionPayment::where('subscription_id', $subscription->id)
                ->where('status', SubscriptionPayment::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                DB::rollBack();
                return ApiResponseService::errorResponse('لا يوجد عملية دفع معلقة لهذا الاشتراك.');
            }

            $user = $subscription->user;

            // 1. If user used wallet balance, deduct it now
            if ($payment->wallet_amount > 0) {
                if ($user->wallet_balance < $payment->wallet_amount) {
                    return ApiResponseService::errorResponse('رصيد محفظة المستخدم غير كافٍ لإتمام هذه المعاملة.');
                }
                $user->decrement('wallet_balance', $payment->wallet_amount);
            }

            // 2. Mark payment completed
            $payment->status = SubscriptionPayment::STATUS_COMPLETED;
            $payment->paid_at = now();
            if ($request->filled('admin_notes')) {
                $payment->admin_notes = $request->admin_notes;
            }
            $payment->save();

            // 3. Determine starts_at and ends_at (Handles stacking)
            $existingSubscription = Subscription::forUser($subscription->user_id)
                ->active()
                ->where('id', '!=', $subscription->id)
                ->lockForUpdate()
                ->first();

            $startsAt = now();
            $status = Subscription::STATUS_ACTIVE;
            $parentSubscriptionId = null;

            if ($existingSubscription) {
                $startsAt = $existingSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING; // Stacking / Queued
                $parentSubscriptionId = $existingSubscription->id;
            }

            $durationDays = $subscription->plan->getDurationDays();
            $endsAt = $subscription->plan->isLifetime() ? null : ($durationDays !== null ? $startsAt->copy()->addDays($durationDays) : null);

            // 4. Activate subscription
            $subscription->starts_at = $startsAt;
            $subscription->ends_at = $endsAt;
            $subscription->status = $status;
            $subscription->parent_subscription_id = $parentSubscriptionId;
            $subscription->save();

            // 5. Process affiliate referral
            try {
                $affiliateService = app(AffiliateService::class);
                $affiliateService->processReferral($user, $subscription);
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('SubscriptionAdminApiController: Affiliate referral processing failed', [
                    'message' => $e->getMessage(),
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                ]);
            }

            // 6. Facebook / GA4 purchase tracking events
            try {
                if (class_exists(\App\Services\TrackingService::class)) {
                    \App\Services\TrackingService::sendFacebookEvent('Purchase', [
                        'em' => hash('sha256', $user->email),
                    ], [
                        'value' => (float) $subscription->plan->price,
                        'currency' => 'EGP',
                        'content_name' => $subscription->plan->name,
                        'content_ids' => [(string) $subscription->plan->id],
                        'content_type' => 'product',
                    ]);
                    \App\Services\TrackingService::sendGA4Event('purchase', [
                        'transaction_id' => 'SUB-' . $subscription->id,
                        'value' => (float) $subscription->plan->price,
                        'currency' => 'EGP',
                        'items' => [
                            ['item_id' => (string) $subscription->plan->id, 'item_name' => $subscription->plan->name]
                        ]
                    ]);
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('SubscriptionAdminApiController: Tracking events failed', [
                    'message' => $e->getMessage()
                ]);
            }

            DB::commit();

            // 7. Notify User
            if ($subscription->parent_subscription_id && $existingSubscription && $existingSubscription->plan_id === $subscription->plan_id) {
                if (class_exists(\App\Notifications\SubscriptionRenewedNotification::class)) {
                    $user->notify(new \App\Notifications\SubscriptionRenewedNotification($subscription->loadMissing('plan'), $payment->wallet_amount));
                } else {
                    $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                }
            } else {
                $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
            }

            return ApiResponseService::successResponse('تمت الموافقة على طلب الاشتراك بنجاح وتفعيله.', $subscription);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('فشل في إتمام عملية الموافقة: ' . $e->getMessage());
        }
    }

    /**
     * Reject manual subscription payment and cancel request
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $subscription = Subscription::lockForUpdate()->find($id);

            if (!$subscription) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الاشتراك غير موجود.');
            }

            if ($subscription->status !== Subscription::STATUS_PENDING_APPROVAL) {
                DB::rollBack();
                return ApiResponseService::errorResponse('هذا الاشتراك ليس بانتظار الموافقة.');
            }

            $payment = SubscriptionPayment::where('subscription_id', $subscription->id)
                ->where('status', SubscriptionPayment::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                DB::rollBack();
                return ApiResponseService::errorResponse('لا يوجد عملية دفع معلقة لهذا الاشتراك.');
            }

            // 1. Mark payment as failed
            $payment->status = SubscriptionPayment::STATUS_FAILED;
            $payment->admin_notes = $request->admin_notes ?? 'تم رفض طلب الدفع من قبل الإدارة.';
            $payment->save();

            // 2. Cancel subscription
            $subscription->status = Subscription::STATUS_CANCELLED;
            $subscription->cancellation_reason = $request->admin_notes ?? 'Payment rejected by administrator';
            $subscription->cancelled_at = now();
            $subscription->auto_renew = false;
            $subscription->save();

            DB::commit();

            // 3. Notify user
            $subscription->user->notify(new ManualSubscriptionStatusNotification($subscription));

            return ApiResponseService::successResponse('تم رفض طلب الاشتراك وإلغاؤه بنجاح.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('فشل في إتمام عملية الرفض: ' . $e->getMessage());
        }
    }

    /**
     * Subscription Plan Report — عدد المشتركين لكل باقة
     * Super Admin only.
     *
     * Query Params:
     *  - date_from    (Y-m-d)   تصفية من تاريخ
     *  - date_to      (Y-m-d)   تصفية إلى تاريخ
     *  - status       active|expired|cancelled|pending|all   (default: all)
     */
    public function planReport(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('subscription-plans-list');

        $status        = $request->input('status', 'all');
        $dateFrom      = $request->input('date_from');
        $dateTo        = $request->input('date_to');
        $paymentMethod = $request->input('payment_method');
        $country       = $request->input('country');

        // ── جلب الإيرادات بالجنيه المصري لكل باقة ──
        $revenuePerPlanQuery = DB::table('subscription_payments')
            ->join('subscriptions', 'subscription_payments.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('supported_currencies', 'subscription_payments.currency_code', '=', 'supported_currencies.currency_code')
            ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED);

        if ($status !== 'all') {
            $revenuePerPlanQuery->where('subscriptions.status', $status);
        }
        if ($dateFrom) {
            $revenuePerPlanQuery->whereDate('subscriptions.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $revenuePerPlanQuery->whereDate('subscriptions.created_at', '<=', $dateTo);
        }
        if ($paymentMethod) {
            $revenuePerPlanQuery->where('subscription_payments.payment_method', $paymentMethod);
        }
        if ($country) {
            $revenuePerPlanQuery->where('subscription_payments.resolved_country', strtoupper($country));
        }

        $revenuePerPlan = $revenuePerPlanQuery
            ->select('subscriptions.plan_id', DB::raw('SUM(subscription_payments.final_amount * ' . $this->getEgpExchangeRateSql() . ') as total_revenue_egp'))
            ->groupBy('subscriptions.plan_id')
            ->pluck('total_revenue_egp', 'plan_id');

        // ── شامل: كل الباقات حتى اللي عندها صفر مشتركين ──
        $plans = SubscriptionPlan::withTrashed()
            ->select('id', 'name', 'billing_cycle', 'duration_days', 'price', 'is_active', 'deleted_at')
            ->addSelect([
                // كل الاشتراكات
                'total_subscribers' => \App\Models\Subscription::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('plan_id', 'subscription_plans.id')
                    ->where(function ($q) use ($status, $dateFrom, $dateTo, $paymentMethod, $country) {
                        $this->applySubscriptionFilters($q, $status, $dateFrom, $dateTo, $paymentMethod, $country);
                    }),
                // مشتركين فاعلين فقط
                'active_subscribers' => \App\Models\Subscription::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('plan_id', 'subscription_plans.id')
                    ->where('status', \App\Models\Subscription::STATUS_ACTIVE)
                    ->where(function ($q) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    })
                    ->where(function ($q) use ($dateFrom, $dateTo, $paymentMethod, $country) {
                        $this->applyDateFilters($q, $dateFrom, $dateTo);
                        $this->applyPaymentFiltersToSubscriptions($q, $paymentMethod, $country);
                    }),
                // مشتركين منتهين
                'expired_subscribers' => \App\Models\Subscription::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('plan_id', 'subscription_plans.id')
                    ->where('status', \App\Models\Subscription::STATUS_EXPIRED)
                    ->where(function ($q) use ($dateFrom, $dateTo, $paymentMethod, $country) {
                        $this->applyDateFilters($q, $dateFrom, $dateTo);
                        $this->applyPaymentFiltersToSubscriptions($q, $paymentMethod, $country);
                    }),
                // مشتركين ملغيين
                'cancelled_subscribers' => \App\Models\Subscription::selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('plan_id', 'subscription_plans.id')
                    ->where('status', \App\Models\Subscription::STATUS_CANCELLED)
                    ->where(function ($q) use ($dateFrom, $dateTo, $paymentMethod, $country) {
                        $this->applyDateFilters($q, $dateFrom, $dateTo);
                        $this->applyPaymentFiltersToSubscriptions($q, $paymentMethod, $country);
                    }),
            ])
            ->orderByDesc('total_subscribers')
            ->get()
            ->map(function (SubscriptionPlan $plan) use ($revenuePerPlan) {
                return [
                    'plan_id'              => $plan->id,
                    'plan_name'            => $plan->name,
                    'billing_cycle'        => $plan->billing_cycle,
                    'duration_days'        => $plan->duration_days,
                    'price'                => (float) $plan->price, // السعر الافتراضي كمعلومة
                    'is_active'            => (bool) $plan->is_active,
                    'is_deleted'           => $plan->deleted_at !== null,
                    'total_revenue_egp'    => (float) ($revenuePerPlan[$plan->id] ?? 0),
                    'total_subscribers'    => (int) $plan->total_subscribers,
                    'active_subscribers'   => (int) $plan->active_subscribers,
                    'expired_subscribers'  => (int) $plan->expired_subscribers,
                    'cancelled_subscribers' => (int) $plan->cancelled_subscribers,
                ];
            });

        // ── ملخص إجمالي ──
        $summary = [
            'total_plans'               => $plans->count(),
            'total_revenue_egp'         => $plans->sum('total_revenue_egp'),
            'total_subscribers'         => $plans->sum('total_subscribers'),
            'total_active_subscribers'  => $plans->sum('active_subscribers'),
            'total_expired_subscribers' => $plans->sum('expired_subscribers'),
            'total_cancelled_subscribers' => $plans->sum('cancelled_subscribers'),
            'filters_applied' => array_filter([
                'status'         => $status !== 'all' ? $status : null,
                'date_from'      => $dateFrom,
                'date_to'        => $dateTo,
                'payment_method' => $paymentMethod,
                'country'        => $country ? strtoupper($country) : null,
            ]),
        ];

        return $this->jsonSuccess(__('Subscription plan report retrieved'), [
            'summary' => $summary,
            'plans'   => $plans,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function applySubscriptionFilters(\Illuminate\Database\Eloquent\Builder $q, string $status, ?string $dateFrom, ?string $dateTo, ?string $paymentMethod = null, ?string $country = null): void
    {
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        $this->applyDateFilters($q, $dateFrom, $dateTo);
        $this->applyPaymentFiltersToSubscriptions($q, $paymentMethod, $country);
    }

    private function applyDateFilters(\Illuminate\Database\Eloquent\Builder $q, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom) {
            $q->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $q->whereDate('created_at', '<=', $dateTo);
        }
    }

    private function applyPaymentFiltersToSubscriptions(\Illuminate\Database\Eloquent\Builder $q, ?string $paymentMethod, ?string $country): void
    {
        if ($paymentMethod || $country) {
            $q->whereHas('payments', function ($paymentQuery) use ($paymentMethod, $country) {
                if ($paymentMethod) {
                    $paymentQuery->where('payment_method', $paymentMethod);
                }
                if ($country) {
                    $paymentQuery->where('resolved_country', strtoupper($country));
                }
            });
        }
    }

    private function getEgpExchangeRateSql(): string
    {
        return 'COALESCE(IF(supported_currencies.use_manual_rate = 1 AND supported_currencies.manual_exchange_rate_to_egp > 0, supported_currencies.manual_exchange_rate_to_egp, supported_currencies.exchange_rate_to_egp), 1)';
    }
}
