<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\OrderTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        // 1. Query course orders
        $ordersQuery = Order::with(['user', 'orderCourses.course', 'promoCode'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $s = $request->search;
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('id', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            }));

        // 2. Query subscription payments
        $subStatus = $request->status;
        if ($subStatus === 'cancelled') {
            $subStatus = 'failed';
        }
        
        $subQuery = SubscriptionPayment::with(['user', 'subscription.plan'])
            ->when($subStatus, fn ($q) => $q->where('status', $subStatus))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $s = $request->search;
                $q->where('transaction_id', 'like', "%{$s}%")
                    ->orWhere('id', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            }));

        $orders = $ordersQuery->get();
        $subPayments = $subQuery->get();

        // Map subscription payments to look like orders
        $mappedSubs = $subPayments->map(fn($p) => $this->mapSubscriptionPaymentToOrder($p));

        // Combine and sort by created_at desc
        $combined = $orders->concat($mappedSubs)->sortByDesc('created_at')->values();

        // Paginate manually
        $perPage = min((int) $request->input('per_page', 15), 100);
        $currentPage = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $combined->slice(($currentPage - 1) * $perPage, $perPage)->all();

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentItems,
            $combined->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath()]
        );
        $paginated->appends($request->query());

        $stats = [
            'total_orders' => Order::count() + SubscriptionPayment::count(),
            'pending_orders' => Order::where('status', 'pending')->count() + SubscriptionPayment::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count() + SubscriptionPayment::where('status', 'completed')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count() + SubscriptionPayment::whereIn('status', ['failed', 'refunded'])->count(),
            'total_revenue' => (float) Order::where('status', 'completed')->sum('final_price') + (float) SubscriptionPayment::where('status', 'completed')->sum('amount'),
            'today_orders' => Order::whereDate('created_at', today())->count() + SubscriptionPayment::whereDate('created_at', today())->count(),
        ];

        return $this->jsonSuccess(__('Orders retrieved'), [
            'orders' => $paginated,
            'stats' => $stats,
        ]);
    }

    public function show($id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        if (is_string($id) && str_starts_with($id, 'sub_')) {
            $paymentId = (int) str_replace('sub_', '', $id);
            $payment = SubscriptionPayment::with(['user', 'subscription.plan', 'manualDepositMethod'])->find($paymentId);
            if (!$payment) {
                return $this->jsonError(__('Order not found'), 404);
            }
            return $this->jsonSuccess(__('Order retrieved'), $this->mapSubscriptionPaymentToOrder($payment));
        }

        $order = Order::with(['user', 'orderCourses.course.user', 'promoCode', 'paymentTransaction'])->find((int)$id);
        if (!$order) {
            return $this->jsonError(__('Order not found'), 404);
        }

        return $this->jsonSuccess(__('Order retrieved'), $order);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,completed,cancelled,failed',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        if (is_string($id) && str_starts_with($id, 'sub_')) {
            $paymentId = (int) str_replace('sub_', '', $id);
            $payment = SubscriptionPayment::with(['subscription.user', 'subscription.plan'])->find($paymentId);
            if (!$payment) {
                return $this->jsonError(__('Order not found'), 404);
            }

            $subscription = $payment->subscription;
            if (!$subscription) {
                return $this->jsonError(__('Subscription not found'), 404);
            }

            $oldStatus = $payment->status;
            
            if ($request->status === 'completed' && $oldStatus !== 'completed') {
                try {
                    DB::beginTransaction();

                    $user = $subscription->user;

                    if ($payment->wallet_amount > 0) {
                        if ($user->wallet_balance < $payment->wallet_amount) {
                            return $this->jsonError('رصيد محفظة المستخدم غير كافٍ لإتمام هذه المعاملة.', 400);
                        }
                        $user->decrement('wallet_balance', $payment->wallet_amount);
                    }

                    $payment->status = SubscriptionPayment::STATUS_COMPLETED;
                    $payment->paid_at = now();
                    $payment->save();

                    $existingSubscription = Subscription::forUser($subscription->user_id)
                        ->active()
                        ->where('id', '!=', $subscription->id)
                        ->first();

                    $startsAt = now();
                    $status = Subscription::STATUS_ACTIVE;
                    $parentSubscriptionId = null;

                    if ($existingSubscription) {
                        $startsAt = $existingSubscription->ends_at ?? now();
                        $status = Subscription::STATUS_PENDING;
                        $parentSubscriptionId = $existingSubscription->id;
                    }

                    $durationDays = $subscription->plan->getDurationDays();
                    $endsAt = $durationDays !== null ? $startsAt->copy()->addDays($durationDays) : null;

                    $subscription->starts_at = $startsAt;
                    $subscription->ends_at = $endsAt;
                    $subscription->status = $status;
                    $subscription->parent_subscription_id = $parentSubscriptionId;
                    $subscription->save();

                    try {
                        $affiliateService = app(\App\Services\AffiliateService::class);
                        $affiliateService->processReferral($user, $subscription);
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        Log::error('OrderAdminApiController: Affiliate referral processing failed', [
                            'message' => $e->getMessage()
                        ]);
                    }

                    DB::commit();

                    try {
                        $user->notify(new \App\Notifications\SubscriptionActivatedNotification($subscription->loadMissing('plan')));
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        Log::error('OrderAdminApiController: User notification failed', [
                            'message' => $e->getMessage()
                        ]);
                    }

                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    DB::rollBack();
                    return $this->jsonError('فشل في إتمام عملية الموافقة: ' . $e->getMessage(), 500);
                }
            } elseif (in_array($request->status, ['cancelled', 'failed']) && $oldStatus === 'pending') {
                try {
                    DB::beginTransaction();

                    $payment->status = SubscriptionPayment::STATUS_FAILED;
                    $payment->save();

                    $subscription->status = Subscription::STATUS_CANCELLED;
                    $subscription->cancelled_at = now();
                    $subscription->auto_renew = false;
                    $subscription->save();

                    DB::commit();

                    try {
                        $subscription->user->notify(new \App\Notifications\ManualSubscriptionStatusNotification($subscription));
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        Log::error('OrderAdminApiController: User notification failed', [
                            'message' => $e->getMessage()
                        ]);
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    DB::rollBack();
                    return $this->jsonError('فشل في إتمام عملية الرفض: ' . $e->getMessage(), 500);
                }
            } else {
                $payment->update(['status' => $request->status]);
            }

            return $this->jsonSuccess(__('Order status updated successfully'), $this->mapSubscriptionPaymentToOrder($payment->fresh()));
        }

        $order = Order::find((int)$id);
        if (!$order) {
            return $this->jsonError(__('Order not found'), 404);
        }

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        if ($request->status === 'completed' && $oldStatus !== 'completed') {
            $order->load('user');
            if ($order->user) {
                OrderTrackingService::createCurriculumTrackingEntries($order, $order->user);
            }
        }

        return $this->jsonSuccess(__('Order status updated successfully'), $order->fresh());
    }

    private function mapSubscriptionPaymentToOrder(SubscriptionPayment $payment): array
    {
        $plan = $payment->subscription?->plan;
        return [
            'id' => 'sub_' . $payment->id,
            'order_number' => $payment->transaction_id ?? ('SUB-' . $payment->id),
            'total_price' => $payment->original_amount ?? $payment->amount,
            'tax_price' => '0.00',
            'final_price' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'status' => $payment->status === 'failed' ? 'cancelled' : $payment->status,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
            'user' => $payment->user,
            'order_courses' => [
                [
                    'id' => null,
                    'order_id' => 'sub_' . $payment->id,
                    'course_id' => null,
                    'price' => $payment->amount,
                    'tax_price' => '0.00',
                    'course' => [
                        'id' => null,
                        'title' => __('Subscription Plan: :name', ['name' => $plan?->name ?? __('Plan')]),
                        'slug' => $plan?->slug ?? '',
                        'description' => $plan?->description ?? '',
                        'image' => null,
                    ]
                ]
            ],
            'promo_code' => $payment->promo_code ? [
                'code' => $payment->promo_code,
            ] : null,
            'discount_amount' => $payment->discount_amount,
            'is_subscription' => true,
        ];
    }
}

