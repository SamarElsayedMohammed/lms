<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ApiResponseService;
use App\Services\AffiliateService;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AffiliateApiController extends Controller
{
    public function status(Request $request, AffiliateService $affiliateService)
    {
        $user = $request->user('sanctum') ?: $request->user();
        return ApiResponseService::successResponse('Status retrieved', [
            'enabled' => $affiliateService->isEnabled(),
            'is_affiliate' => $affiliateService->isEnabled() && $user !== null,
            'min_withdrawal_amount' => $affiliateService->getMinimumWithdrawalAmount(),
            'available_balance' => $user ? round($affiliateService->getAvailableBalance($user), 2) : null,
            'pending_withdrawal' => $user
                ? AffiliateWithdrawal::where('affiliate_id', $user->id)->whereIn('status', ['pending', 'processing'])->exists()
                : false,
            'currency' => 'EGP',
        ]);
    }

    public function getMyLink(Request $request, AffiliateService $affiliateService)
    {
        if (!$affiliateService->isEnabled()) {
            return ApiResponseService::errorResponse('Affiliate system is disabled', code: 404);
        }
        $link = $affiliateService->generateAffiliateLink($request->user());
        return ApiResponseService::successResponse('Link retrieved', [
            'code' => $link->code,
            'referral_code' => $link->code,
            'clicks' => $link->total_clicks,
            'conversions' => $link->total_conversions,
        ]);
    }

    public function getStats(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $user = $request->user();
        $link = $affiliateService->generateAffiliateLink($user);
        $totalEarnings = (float) AffiliateCommission::forAffiliate($user->id)->sum('amount');
        $paidWithdrawals = (float) AffiliateWithdrawal::where('affiliate_id', $user->id)
            ->where('status', 'completed')
            ->sum('amount');
        $transferred = (float) AffiliateCommission::forAffiliate($user->id)->sum('transferred_amount');
        $paymentTotals = DB::table('subscription_payments')
            ->select('subscription_id', DB::raw('MAX(COALESCE(amount_egp, amount)) as sale_amount'))
            ->where('status', 'completed')
            ->groupBy('subscription_id');
        $planMetrics = AffiliateCommission::query()
            ->where('affiliate_commissions.affiliate_id', $user->id)
            ->join('subscription_plans', 'subscription_plans.id', '=', 'affiliate_commissions.plan_id')
            ->leftJoinSub($paymentTotals, 'affiliate_payment_totals', static function ($join): void {
                $join->on('affiliate_payment_totals.subscription_id', '=', 'affiliate_commissions.subscription_id');
            })
            ->groupBy('affiliate_commissions.plan_id', 'subscription_plans.name', 'subscription_plans.price')
            ->select([
                'affiliate_commissions.plan_id',
                'subscription_plans.name as plan_name',
                DB::raw('SUM(affiliate_commissions.amount) as total_commission'),
                DB::raw('SUM(COALESCE(affiliate_payment_totals.sale_amount, subscription_plans.price)) as total_sales'),
                DB::raw('COUNT(affiliate_commissions.id) as conversion_count'),
                DB::raw('COUNT(DISTINCT affiliate_commissions.referred_user_id) as customers_count'),
            ])
            ->get()
            ->map(static function ($row): array {
                $totalCommission = (float) $row->total_commission;
                $totalSales = (float) $row->total_sales;
                $customers = (int) $row->customers_count;
                return [
                    'plan_id' => (int) $row->plan_id,
                    'plan_name' => $row->plan_name,
                    'total_commission' => round($totalCommission, 2),
                    'total_sales' => round($totalSales, 2),
                    'conversion_count' => (int) $row->conversion_count,
                    'customers_count' => $customers,
                    'avg_commission_per_customer' => $customers > 0 ? round($totalCommission / $customers, 2) : 0,
                    'commission_rate_percent' => $totalSales > 0 ? round(($totalCommission / $totalSales) * 100, 2) : 0,
                    'currency' => 'EGP',
                ];
            });

        return ApiResponseService::successResponse('Affiliate stats retrieved', [
            'clicks' => (int) $link->total_clicks,
            'referrals' => User::where('referred_by', $user->id)->count(),
            'conversions' => AffiliateCommission::forAffiliate($user->id)->distinct('referred_user_id')->count('referred_user_id'),
            'total_earnings' => round($totalEarnings, 2),
            'pending_earnings' => round($affiliateService->getPendingBalance($user), 2),
            'paid_earnings' => round($paidWithdrawals + $transferred, 2),
            'available_to_withdraw' => round($affiliateService->getAvailableBalance($user), 2),
            'pending_withdrawal' => AffiliateWithdrawal::where('affiliate_id', $user->id)->whereIn('status', ['pending', 'processing'])->exists(),
            'min_withdrawal_amount' => $affiliateService->getMinimumWithdrawalAmount(),
            'currency' => 'EGP',
            'plan_metrics' => $planMetrics,
        ]);
    }

    public function getCommissions(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:pending,available,withdrawn,transferred_to_wallet,cancelled',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) ApiResponseService::validationError($validator->errors()->first());

        $user = $request->user();
        $commissions = AffiliateCommission::forAffiliate($user->id)
            ->with(['referredUser:id,name,email', 'plan:id,name,price', 'subscription.payments'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->orderByDesc('earned_date')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25));

        $commissions->setCollection($commissions->getCollection()->map(static function (AffiliateCommission $commission): array {
            $payment = $commission->subscription?->payments
                ?->firstWhere('status', 'completed');
            return [
                'id' => $commission->id,
                'amount' => $commission->amount,
                'remaining_amount' => $commission->remaining_amount,
                'transferred_amount' => $commission->transferred_amount,
                'currency' => 'EGP',
                'status' => $commission->status,
                'created_at' => $commission->earned_date,
                'available_date' => $commission->available_date,
                'plan_id' => $commission->plan_id,
                'plan_name' => $commission->plan?->name,
                'commission_rate' => $commission->commission_rate,
                'referred_user_id' => $commission->referred_user_id,
                'referred_user_name' => $commission->referredUser?->name,
                'referred_user_email' => $commission->referredUser?->email,
                'subscription_amount' => $payment ? ($payment->amount_egp ?? $payment->amount) : $commission->plan?->price,
            ];
        }));

        return ApiResponseService::successResponse('Commissions retrieved', $commissions);
    }

    public function requestWithdrawal(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|in:EGP',
        ]);
        if ($validator->fails()) ApiResponseService::validationError($validator->errors()->first());

        try {
            $withdrawal = $affiliateService->requestWithdrawal($request->user(), (float) $request->input('amount'));
            return ApiResponseService::successResponse('Withdrawal request submitted', [
                'id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'currency' => 'EGP',
                'status' => $withdrawal->status,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), code: 422);
        }
    }

    public function transferToWallet(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|in:EGP',
        ]);
        if ($validator->fails()) ApiResponseService::validationError($validator->errors()->first());

        try {
            $affiliateService->transferCommissionToWallet($request->user(), (float) $request->input('amount'));
            return ApiResponseService::successResponse('Commission transferred to wallet', [
                'amount' => round((float) $request->input('amount'), 2),
                'currency' => 'EGP',
                'available_balance' => round($affiliateService->getAvailableBalance($request->user()), 2),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), code: 422);
        }
    }

    public function getWithdrawals(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) ApiResponseService::validationError($validator->errors()->first());
        $withdrawals = $affiliateService->getWithdrawals($request->user(), (int) $request->input('per_page', 25));
        $withdrawals->setCollection($withdrawals->getCollection()->map(static fn (AffiliateWithdrawal $withdrawal): array => [
            'id' => $withdrawal->id,
            'amount' => $withdrawal->amount,
            'currency' => 'EGP',
            'status' => $withdrawal->status,
            'created_at' => $withdrawal->requested_at,
            'processed_at' => $withdrawal->processed_at,
            'note' => $withdrawal->rejection_reason,
        ]));
        return ApiResponseService::successResponse('Withdrawals retrieved', $withdrawals);
    }

    public function getReferrals(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) ApiResponseService::validationError($validator->errors()->first());

        $referrals = $affiliateService->getReferredUsers($request->user(), (int) $request->input('per_page', 25));
        $ids = $referrals->getCollection()->pluck('id');
        $totals = AffiliateCommission::where('affiliate_id', $request->user()->id)
            ->whereIn('referred_user_id', $ids)
            ->groupBy('referred_user_id')
            ->select('referred_user_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'referred_user_id');
        $referrals->setCollection($referrals->getCollection()->map(static fn (User $user): array => [
            'id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'joined_at' => $user->created_at,
            'total_commissions' => round((float) ($totals[$user->id] ?? 0), 2),
            'currency' => 'EGP',
            'status' => $user->is_active ? 'active' : 'inactive',
            'completed_orders' => (int) $user->orders_count,
        ]));
        return ApiResponseService::successResponse('Referrals retrieved', $referrals);
    }

    public function getMarketingAssets(Request $request, AffiliateService $affiliateService)
    {
        $this->ensureEnabled($affiliateService);
        return ApiResponseService::successResponse('Marketing assets retrieved', [
            'banners' => [],
            'promotional_texts' => [],
        ]);
    }

    public function trackReferral(Request $request, $code, AffiliateService $affiliateService)
    {
        if ($affiliateService->isEnabled()) {
            $affiliateService->trackClick($code);
        }
        return ApiResponseService::successResponse('Referral tracked');
    }

    private function ensureEnabled(AffiliateService $affiliateService): void
    {
        if (!$affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is disabled', code: 404);
        }
    }
}
