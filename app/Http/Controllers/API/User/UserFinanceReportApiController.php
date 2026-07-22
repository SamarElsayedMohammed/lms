<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\WalletHistory;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserFinanceReportApiController extends Controller
{
    /**
     * Get unified financial transactions for the authenticated user.
     *
     * Security: regular users can only see their own transactions.
     *           Admins may optionally pass ?user_id=X to see another user's data.
     *
     * Performance: pagination is done at the database level via UNION query
     *              instead of loading every row into memory first.
     */
    public function getFinancialTransactions(Request $request)
    {
        try {
            /** @var User $user */
            $user = Auth::user();
            if (! $user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            $isAdmin = $user->hasRole('Super Admin')
                || $user->hasRole('Admin')
                || $user->hasRole('Supervisor')
                || $user->hasRole('Staff');

            // Security fix: only admins may specify a foreign user_id.
            // Regular users are always scoped to themselves regardless of input.
            $targetUserId = $isAdmin
                ? (int) $request->input('user_id', $user->id)
                : (int) $user->id;

            $perPage = max(1, min((int) $request->input('per_page', 15), 100));
            $page    = max(1, (int) $request->input('page', 1));
            $offset  = ($page - 1) * $perPage;

            // ── Build three typed sub-queries ──────────────────────────────
            // Each branch selects the same 11 columns so UNION works cleanly.

            $ordersSql = DB::table('orders as o')
                ->where('o.user_id', $targetUserId)
                ->where('o.status', 'completed')
                ->selectRaw("
                    o.id                                              AS id,
                    'course_purchase'                                 AS type,
                    CONCAT('Course Purchase: ', COALESCE(o.order_number,''))  AS title,
                    COALESCE(o.final_price, 0)                        AS amount,
                    COALESCE(o.currency,'EGP')                        AS currency,
                    o.status                                          AS status,
                    o.payment_method                                  AS payment_method,
                    o.order_number                                    AS reference_id,
                    NULL                                              AS description,
                    NULL                                              AS entry_type,
                    o.created_at                                      AS created_at
                ");

            $subsSql = DB::table('subscription_payments as sp')
                ->join('subscriptions as s',     's.id',  '=', 'sp.subscription_id')
                ->join('subscription_plans as pl','pl.id', '=', 's.plan_id')
                ->where('sp.user_id', $targetUserId)
                ->where('sp.status', SubscriptionPayment::STATUS_COMPLETED)
                ->selectRaw("
                    sp.id                                                      AS id,
                    'subscription'                                             AS type,
                    CONCAT('Subscription: ', COALESCE(pl.name,'Plan'))         AS title,
                    COALESCE(sp.final_amount, 0)                               AS amount,
                    COALESCE(sp.currency,'EGP')                                AS currency,
                    sp.status                                                   AS status,
                    sp.payment_method                                           AS payment_method,
                    sp.transaction_id                                           AS reference_id,
                    NULL                                                        AS description,
                    NULL                                                        AS entry_type,
                    sp.created_at                                               AS created_at
                ");

            $walletSql = DB::table('wallet_histories as wh')
                ->where('wh.user_id', $targetUserId)
                ->selectRaw("
                    wh.id                                             AS id,
                    CONCAT('wallet_', wh.transaction_type)           AS type,
                    CONCAT('Wallet: ', wh.transaction_type)          AS title,
                    COALESCE(wh.amount, 0)                           AS amount,
                    COALESCE(wh.currency,'EGP')                      AS currency,
                    'completed'                                      AS status,
                    COALESCE(wh.payment_method,'wallet')             AS payment_method,
                    wh.reference_id                                  AS reference_id,
                    wh.description                                   AS description,
                    wh.type                                          AS entry_type,
                    wh.created_at                                    AS created_at
                ");

            // ── Count total (without LIMIT) ────────────────────────────────
            $unionForCount = $ordersSql->union($subsSql)->union($walletSql);

            $totalCount = DB::table(DB::raw("({$unionForCount->toSql()}) as all_txn"))
                ->mergeBindings($unionForCount)
                ->count();

            // ── Fetch the requested page ───────────────────────────────────
            // Rebuild sub-queries (builders are stateful after union)
            $ordersPage = DB::table('orders as o')
                ->where('o.user_id', $targetUserId)
                ->where('o.status', 'completed')
                ->selectRaw("o.id,'course_purchase' AS type,CONCAT('Course Purchase: ',COALESCE(o.order_number,'')) AS title,COALESCE(o.final_price,0) AS amount,COALESCE(o.currency,'EGP') AS currency,o.status,o.payment_method,o.order_number AS reference_id,NULL AS description,NULL AS entry_type,o.created_at");

            $subsPage = DB::table('subscription_payments as sp')
                ->join('subscriptions as s',     's.id',  '=', 'sp.subscription_id')
                ->join('subscription_plans as pl','pl.id', '=', 's.plan_id')
                ->where('sp.user_id', $targetUserId)
                ->where('sp.status', SubscriptionPayment::STATUS_COMPLETED)
                ->selectRaw("sp.id,'subscription' AS type,CONCAT('Subscription: ',COALESCE(pl.name,'Plan')) AS title,COALESCE(sp.final_amount,0) AS amount,COALESCE(sp.currency,'EGP') AS currency,sp.status,sp.payment_method,sp.transaction_id AS reference_id,NULL AS description,NULL AS entry_type,sp.created_at");

            $walletPage = DB::table('wallet_histories as wh')
                ->where('wh.user_id', $targetUserId)
                ->selectRaw("wh.id,CONCAT('wallet_',wh.transaction_type) AS type,CONCAT('Wallet: ',wh.transaction_type) AS title,COALESCE(wh.amount,0) AS amount,COALESCE(wh.currency,'EGP') AS currency,'completed' AS status,COALESCE(wh.payment_method,'wallet') AS payment_method,wh.reference_id,wh.description,wh.type AS entry_type,wh.created_at");

            $unionForPage = $ordersPage->union($subsPage)->union($walletPage);

            $rows = DB::table(DB::raw("({$unionForPage->toSql()}) as all_txn"))
                ->mergeBindings($unionForPage)
                ->orderByDesc('created_at')
                ->skip($offset)
                ->take($perPage)
                ->get();

            // ── Wallet balance ─────────────────────────────────────────────
            if ($targetUserId === (int) $user->id) {
                $walletBalance = (float) ($user->wallet_balance ?? 0);
            } else {
                $walletBalance = (float) (User::find($targetUserId)?->wallet_balance ?? 0);
            }

            return ApiResponseService::successResponse('Financial transactions retrieved successfully', [
                'transactions' => $rows->values(),
                'pagination'   => [
                    'total'        => $totalCount,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => (int) ceil($totalCount / max(1, $perPage)),
                ],
                'summary'      => [
                    'total_transactions' => $totalCount,
                    'wallet_balance'     => $walletBalance,
                ],
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to fetch transactions: ' . $e->getMessage());
        }
    }
}

