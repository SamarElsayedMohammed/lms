<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SubscriptionPayment;
use App\Models\WalletHistory;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UserFinanceReportApiController extends Controller
{
    /**
     * Get unified financial transactions for the user
     */
    public function getFinancialTransactions(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            $perPage = $request->input('per_page', 15);

            // 1. Get Course Orders
            $orders = Order::where('user_id', $user->id)
                ->where('status', 'completed')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'type' => 'course_purchase',
                        'title' => 'Course Purchase: ' . $order->order_number,
                        'amount' => (float) $order->final_price,
                        'currency' => $order->currency ?? 'USD',
                        'status' => $order->status,
                        'date' => $order->created_at->toDateTimeString(),
                        'timestamp' => $order->created_at->timestamp,
                        'reference_id' => $order->order_number,
                        'payment_method' => $order->payment_method,
                    ];
                });

            // 2. Get Subscription Payments
            // Check if SubscriptionPayment model exists, if not, skip
            $subscriptions = [];
            if (class_exists(SubscriptionPayment::class)) {
                $subscriptions = SubscriptionPayment::whereHas('subscription', function($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->where('status', 'paid')
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'type' => 'subscription',
                        'title' => 'Subscription: ' . ($payment->subscription->plan->name ?? 'Plan'),
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency ?? 'USD',
                        'status' => $payment->status,
                        'date' => $payment->created_at->toDateTimeString(),
                        'timestamp' => $payment->created_at->timestamp,
                        'reference_id' => $payment->transaction_id,
                        'payment_method' => $payment->payment_method,
                    ];
                });
            }

            // 3. Get Wallet History (Deposits, Withdrawals, Refunds)
            $wallet = WalletHistory::where('user_id', $user->id)
                ->get()
                ->map(function ($history) {
                    $type = $history->transaction_type; // deposit, withdrawal, refund, commission
                    $title = ucfirst(str_replace('_', ' ', $type));
                    
                    return [
                        'id' => $history->id,
                        'type' => 'wallet_' . $type,
                        'title' => $title,
                        'amount' => (float) $history->amount,
                        'currency' => $history->currency ?? 'USD',
                        'status' => $history->status ?? 'completed',
                        'date' => $history->created_at->toDateTimeString(),
                        'timestamp' => $history->created_at->timestamp,
                        'reference_id' => $history->transaction_id ?? null,
                        'payment_method' => $history->payment_method ?? 'wallet',
                        'description' => $history->description,
                    ];
                });

            // Merge and Sort
            $allTransactions = collect($orders)
                ->concat($subscriptions)
                ->concat($wallet)
                ->sortByDesc('timestamp')
                ->values();

            // Pagination manually
            $page = $request->input('page', 1);
            $paginated = $allTransactions->forPage($page, $perPage);

            $data = [
                'transactions' => $paginated->values(),
                'pagination' => [
                    'total' => $allTransactions->count(),
                    'per_page' => (int) $perPage,
                    'current_page' => (int) $page,
                    'last_page' => ceil($allTransactions->count() / $perPage),
                ],
                'summary' => [
                    'total_spent' => round($orders->sum('amount') + collect($subscriptions)->sum('amount'), 2),
                    'wallet_balance' => round($user->wallet_balance ?? 0, 2),
                ]
            ];

            return ApiResponseService::successResponse('Financial transactions retrieved successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to fetch transactions: ' . $e->getMessage());
        }
    }
}
