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

            // Admin can filter by user_id or see all if they are in admin context
            $isAdmin = $user->hasRole('Super Admin') || $user->hasRole('Admin') || $user->role === 'admin';
            $targetUserId = $request->input('user_id');
            
            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);

            // Base queries
            $orderQuery = Order::query();
            $subscriptionQuery = SubscriptionPayment::query();
            $walletQuery = WalletHistory::query()->with('user');

            // Apply filters based on role and targetUserId
            if ($targetUserId) {
                $orderQuery->where('user_id', $targetUserId);
                $subscriptionQuery->where('user_id', $targetUserId);
                $walletQuery->where('user_id', $targetUserId);
            } elseif (!$isAdmin) {
                // Regular users only see their own
                $orderQuery->where('user_id', $user->id);
                $subscriptionQuery->where('user_id', $user->id);
                $walletQuery->where('user_id', $user->id);
            }

            // 1. Get Course Orders
            $orders = $orderQuery->where('status', 'completed')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'user_name' => $order->user->name ?? 'Unknown',
                        'type' => 'course_purchase',
                        'title' => 'Course Purchase: ' . $order->order_number,
                        'amount' => (float) $order->final_price,
                        'currency' => $order->currency ?? 'EGP',
                        'status' => $order->status,
                        'date' => $order->created_at->toDateTimeString(),
                        'timestamp' => $order->created_at->timestamp,
                        'reference_id' => $order->order_number,
                        'payment_method' => $order->payment_method,
                    ];
                });

            // 2. Get Subscription Payments
            $subscriptions = $subscriptionQuery->where('status', 'paid')
                ->with(['subscription.plan', 'user'])
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'user_name' => $payment->user->name ?? 'Unknown',
                        'type' => 'subscription',
                        'title' => 'Subscription: ' . ($payment->subscription->plan->name ?? 'Plan'),
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency ?? 'EGP',
                        'status' => $payment->status,
                        'date' => $payment->created_at->toDateTimeString(),
                        'timestamp' => $payment->created_at->timestamp,
                        'reference_id' => $payment->transaction_id,
                        'payment_method' => $payment->payment_method,
                    ];
                });

            // 3. Get Wallet History
            $wallet = $walletQuery->get()
                ->map(function ($history) {
                    $type = $history->transaction_type;
                    $title = ucwords(str_replace('_', ' ', (string)$type));
                    
                    return [
                        'id' => $history->id,
                        'user_name' => $history->user->name ?? 'Unknown',
                        'type' => 'wallet_' . $type,
                        'title' => 'Wallet: ' . $title,
                        'amount' => (float) $history->amount,
                        'currency' => $history->currency ?? 'EGP',
                        'status' => 'completed',
                        'date' => $history->created_at->toDateTimeString(),
                        'timestamp' => $history->created_at->timestamp,
                        'reference_id' => $history->reference_id,
                        'payment_method' => $history->payment_method ?? 'wallet',
                        'description' => $history->description,
                        'entry_type' => $history->type, // credit/debit
                    ];
                });

            // Merge and Sort
            $allTransactions = collect($orders)
                ->concat($subscriptions)
                ->concat($wallet)
                ->sortByDesc('timestamp')
                ->values();

            // Pagination
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
                    'total_transactions' => $allTransactions->count(),
                    'wallet_balance' => $targetUserId ? (User::find($targetUserId)->wallet_balance ?? 0) : ($isAdmin ? null : $user->wallet_balance),
                ]
            ];

            return ApiResponseService::successResponse('Financial transactions retrieved successfully', $data);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to fetch transactions: ' . $e->getMessage());
        }
    }
}
