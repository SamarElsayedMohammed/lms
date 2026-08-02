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
use App\Models\User;
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

        $subscriptions->getCollection()->transform(function ($sub) {
            $latestPayment = $sub->payments->first();
            $data = $sub->toArray();
            $data['payment_info'] = $latestPayment ? [
                'original_currency' => $latestPayment->currency_code ?? 'EGP',
                'original_amount' => (float) $latestPayment->final_amount,
                'converted_amount' => (float) ($latestPayment->amount_egp ?? ($latestPayment->final_amount * ($latestPayment->exchange_rate_snapshot ?? 1))),
                'exchange_rate' => (float) ($latestPayment->exchange_rate_snapshot ?? 1),
                'payment_date' => $latestPayment->paid_at ?? $latestPayment->created_at,
                'payment_method' => $latestPayment->payment_method ?? 'Unknown',
                'country' => $latestPayment->resolved_country ?? 'Unknown',
                'status' => $latestPayment->status,
            ] : null;
            return $data;
        });

        return ApiResponseService::successResponse('Subscriptions dashboard retrieved successfully', [
            'statistics' => $statistics,
            'subscriptions' => $subscriptions
        ]);
    }

    /**
     * List ONLY manual subscription requests with filter-aware statistics
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        // 1. Base Query restricted strictly to manual payments
        $baseQuery = Subscription::query()
            ->whereHas('payments', function ($q) {
                $q->where('payment_method', 'manual');
            });

        // Calculate filter-aware summary stats
        $statistics = [
            'total' => (clone $baseQuery)->count(),
            'pending_approval' => (clone $baseQuery)->where('status', Subscription::STATUS_PENDING_APPROVAL)->count(),
            'approved' => (clone $baseQuery)->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])->count(),
            'rejected' => (clone $baseQuery)->where('status', Subscription::STATUS_CANCELLED)->count(),
        ];

        $query = (clone $baseQuery)->with(['user', 'plan', 'payments' => function ($q) {
            $q->latest()->with('manualDepositMethod');
        }]);

        // Filter by Status
        if ($request->filled('status')) {
            $statusInput = strtolower(trim((string) $request->status));
            if (in_array($statusInput, ['approved', 'active'], true)) {
                $query->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING]);
            } elseif (in_array($statusInput, ['rejected', 'cancelled'], true)) {
                $query->where('status', Subscription::STATUS_CANCELLED);
            } elseif (in_array($statusInput, ['pending', 'pending_approval'], true)) {
                $query->where('status', Subscription::STATUS_PENDING_APPROVAL);
            } else {
                $query->where('status', $statusInput);
            }
        }

        // Filter by Search (User name, email, or request ID)
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                if (ctype_digit($search)) {
                    $q->where('id', (int) $search);
                }
                $q->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // Filter by Date Range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by Plan
        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $resources = \App\Http\Resources\ManualSubscriptionAdminResource::collection($paginator->getCollection());

        return ApiResponseService::successResponse('Manual subscriptions retrieved successfully', [
            'summary' => $statistics,
            'data' => $resources,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Display single manual subscription request
     */
    public function show(int|string $id): JsonResponse
    {
        $this->ensureAdmin();

        $subscription = Subscription::with(['user', 'plan', 'payments' => function ($q) {
            $q->latest()->with('manualDepositMethod');
        }])->find($id);

        if (!$subscription) {
            return ApiResponseService::errorResponse('طلب الاشتراك غير موجود.', [], 404);
        }

        return ApiResponseService::successResponse(
            'تفاصيل طلب الاشتراك اليدوي',
            new \App\Http\Resources\ManualSubscriptionAdminResource($subscription)
        );
    }

    /**
     * Approve manual subscription payment and activate subscription
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $this->ensureAdmin();

        try {
            DB::beginTransaction();

            $subscriptionData = Subscription::find($id);

            if (!$subscriptionData) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الاشتراك غير موجود.', [], 404);
            }

            // 1. Lock User first to prevent deadlocks
            $user = User::where('id', $subscriptionData->user_id)->lockForUpdate()->first();

            // 2. Lock Last Subscription in Queue
            $existingSubscription = Subscription::forUser($user->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
                ->where('id', '!=', $id)
                ->whereNotNull('ends_at')
                ->orderByDesc('ends_at')
                ->lockForUpdate()
                ->first();

            // 3. Lock Pending Subscription
            $subscription = Subscription::with(['plan' => function ($query) {
                $query->withTrashed();
            }])->lockForUpdate()->find($id);

            if (!$subscription) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الاشتراك غير موجود.');
            }

            // Idempotency: if already approved, return success
            if (in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING], true)) {
                DB::rollBack();
                return ApiResponseService::successResponse('تم تفعيل هذا الاشتراك مسبقاً.', new \App\Http\Resources\ManualSubscriptionAdminResource($subscription));
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

            // 4. If user used wallet balance, deduct it now
            if ($payment->wallet_amount > 0) {
                if ($user->wallet_balance < $payment->wallet_amount) {
                    DB::rollBack();
                    return ApiResponseService::errorResponse('رصيد محفظة المستخدم غير كافٍ لإتمام هذه المعاملة.');
                }
                $user->decrement('wallet_balance', $payment->wallet_amount);
            }

            // 5. Mark payment completed
            $payment->status = SubscriptionPayment::STATUS_COMPLETED;
            $payment->paid_at = now();
            if ($request->filled('admin_notes')) {
                $payment->admin_notes = trim((string) $request->admin_notes);
            }
            $payment->save();

            // 6. Determine starts_at and ends_at (Handles stacking)
            $startsAt = now();
            $status = Subscription::STATUS_ACTIVE;
            $parentSubscriptionId = null;

            if ($existingSubscription) {
                $startsAt = $existingSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING; // Stacking / Queued
                $parentSubscriptionId = $existingSubscription->id;
            }

            $durationDays = $subscription->plan->getDurationDays();
            $endsAt = $durationDays !== null ? $startsAt->copy()->addDays($durationDays) : null;

            // 7. Activate subscription
            $subscription->starts_at = $startsAt;
            $subscription->ends_at = $endsAt;
            $subscription->status = $status;
            $subscription->parent_subscription_id = $parentSubscriptionId;
            $subscription->save();

            // 8. Process affiliate referral
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

            // 9. Facebook / GA4 purchase tracking events
            try {
                if (class_exists(\App\Services\TrackingService::class)) {
                    $trackingValue = (float) ($payment->amount_egp ?? ($payment->final_amount * ($payment->exchange_rate_snapshot ?? 1)));
                    
                    \App\Services\TrackingService::sendFacebookEvent('Purchase', [
                        'em' => hash('sha256', $user->email),
                    ], [
                        'value' => $trackingValue,
                        'currency' => 'EGP',
                        'content_name' => $subscription->plan->name,
                        'content_ids' => [(string) $subscription->plan->id],
                        'content_type' => 'product',
                    ]);
                    \App\Services\TrackingService::sendGA4Event('purchase', [
                        'transaction_id' => 'SUB-' . $subscription->id,
                        'value' => $trackingValue,
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

            // 10. Notify User (Safely)
            try {
                if ($subscription->parent_subscription_id && $existingSubscription && $existingSubscription->plan_id === $subscription->plan_id) {
                    if (class_exists(\App\Notifications\SubscriptionRenewedNotification::class)) {
                        $user->notify(new \App\Notifications\SubscriptionRenewedNotification($subscription->loadMissing('plan'), (float) $payment->wallet_amount));
                    } else {
                        $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                    }
                } else {
                    $user->notify(new SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send SubscriptionActivatedNotification', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage()
                ]);
            }

            return ApiResponseService::successResponse(
                'تمت الموافقة على طلب الاشتراك بنجاح وتفعيله.',
                new \App\Http\Resources\ManualSubscriptionAdminResource($subscription->fresh(['user', 'plan', 'payments']))
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('فشل في إتمام عملية الموافقة: ' . $e->getMessage());
        }
    }

    /** Expose payment evidence only to authorized administrators. */
    public function downloadReceipt(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $this->ensureAdmin();

        $payment = SubscriptionPayment::query()
            ->where('subscription_id', $id)
            ->whereNotNull('receipt')
            ->latest('id')
            ->first();

        if (!$payment || !$payment->getRawOriginal('receipt')) {
            return ApiResponseService::errorResponse('الإيصال غير موجود.', [], 404);
        }

        $receipt = $payment->getRawOriginal('receipt');
        if (!\App\Services\FileService::checkPrivateFileExists($receipt)) {
            return ApiResponseService::errorResponse('ملف الإيصال غير متاح في التخزين.', [], 404);
        }

        return response()->file(\App\Services\FileService::getPrivateFilePath($receipt));
    }

    /**
     * Reject manual subscription payment and cancel request. Mandatory reason required.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'required|string|min:3|max:500',
        ], [
            'admin_notes.required' => 'يرجى كتابة سبب الرفض.',
            'admin_notes.min' => 'سبب الرفض يجب أن يكون 3 أحرف على الأقل.',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $subscription = Subscription::lockForUpdate()->find($id);

            if (!$subscription) {
                DB::rollBack();
                return ApiResponseService::errorResponse('الاشتراك غير موجود.', [], 404);
            }

            // Idempotency: if already cancelled/rejected, return success
            if ($subscription->status === Subscription::STATUS_CANCELLED) {
                DB::rollBack();
                return ApiResponseService::successResponse('تم رفض هذا الاشتراك مسبقاً.');
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

            $reason = trim((string) $request->admin_notes);

            // 1. Mark payment as failed
            $payment->status = SubscriptionPayment::STATUS_FAILED;
            $payment->admin_notes = $reason;
            $payment->save();

            // 2. Cancel subscription
            $subscription->status = Subscription::STATUS_CANCELLED;
            $subscription->cancellation_reason = $reason;
            $subscription->cancelled_at = now();
            $subscription->auto_renew = false;
            $subscription->save();

            DB::commit();

            // 3. Notify user (Safely)
            try {
                $subscription->user->notify(new ManualSubscriptionStatusNotification($subscription));
            } catch (\Exception $e) {
                Log::error('Failed to send ManualSubscriptionStatusNotification', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage()
                ]);
            }

            return ApiResponseService::successResponse(
                'تم رفض طلب الاشتراك وإلغاؤه بنجاح.',
                new \App\Http\Resources\ManualSubscriptionAdminResource($subscription->fresh(['user', 'plan', 'payments']))
            );
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
