<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\ApiResponseService;
use App\Services\AffiliateService;
use App\Notifications\ManualSubscriptionStatusNotification;
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
        
        // Total Revenue from completed payments
        $totalRevenue = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)->sum('final_amount');
        
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

        $subscription = Subscription::with(['user', 'plan'])->findOrFail($id);

        if ($subscription->status !== Subscription::STATUS_PENDING_APPROVAL) {
            return ApiResponseService::errorResponse('هذا الاشتراك ليس بانتظار الموافقة.');
        }

        $payment = SubscriptionPayment::where('subscription_id', $subscription->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->first();

        if (!$payment) {
            return ApiResponseService::errorResponse('لا يوجد عملية دفع معلقة لهذا الاشتراك.');
        }

        try {
            DB::beginTransaction();

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
            } catch (\Throwable $e) {
                Log::error('SubscriptionAdminApiController: Tracking events failed', [
                    'message' => $e->getMessage()
                ]);
            }

            DB::commit();

            // 7. Notify User
            $user->notify(new ManualSubscriptionStatusNotification($subscription));

            return ApiResponseService::successResponse('تمت الموافقة على طلب الاشتراك بنجاح وتفعيله.', $subscription);
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

        $subscription = Subscription::findOrFail($id);

        if ($subscription->status !== Subscription::STATUS_PENDING_APPROVAL) {
            return ApiResponseService::errorResponse('هذا الاشتراك ليس بانتظار الموافقة.');
        }

        $payment = SubscriptionPayment::where('subscription_id', $subscription->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->first();

        if (!$payment) {
            return ApiResponseService::errorResponse('لا يوجد عملية دفع معلقة لهذا الاشتراك.');
        }

        try {
            DB::beginTransaction();

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
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('فشل في إتمام عملية الرفض: ' . $e->getMessage());
        }
    }
}
