<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliateService;
use App\Services\ApiResponseService;
use App\Models\AffiliateLink;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AffiliateApiController extends Controller
{
    public function __construct(private readonly AffiliateService $affiliateService)
    {
        // status and trackReferral are public, others are protected by auth:sanctum middleware in routes
    }

    public function trackReferral(Request $request, $code)
    {
        $this->affiliateService->trackClick($code);
        
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://skillso.net')), '/');
        $redirectUrl = $frontendUrl . '/auth/sign-up?referral=' . $code;
        
        return redirect()->away($redirectUrl)->withCookie(cookie('affiliate_code', $code, 43200)); // 30 days
    }

    public function status()
    {
        $user = Auth::guard('sanctum')->user();
        
        $link = $user ? AffiliateLink::where('user_id', $user->id)->first() : null;
        $hasPendingWithdrawal = $user ? AffiliateWithdrawal::where('affiliate_id', $user->id)->where('status', 'pending')->exists() : false;
        
        $minWithdrawal = $this->affiliateService->getMinimumWithdrawalAmount();
        $enabled = $this->affiliateService->isEnabled();
        
        return ApiResponseService::successResponse('Affiliate status', [
            'enabled' => $enabled,
            'is_affiliate' => $link ? true : false,
            'status' => $link ? ($link->is_active ? 'active' : 'inactive') : 'inactive',
            'joined_at' => $link ? $link->created_at->toIso8601String() : null,
            'min_withdrawal_amount_egp' => $minWithdrawal,
            'available_balance_egp' => $user ? $this->affiliateService->getAvailableBalance($user) : 0,
            'pending_withdrawal' => $hasPendingWithdrawal,
            'settings' => [
                'affiliate_marketing_enabled' => $enabled,
                'min_withdrawal_amount_egp' => $minWithdrawal,
            ]
        ]);
    }

    public function getMyLink()
    {
        if (!$this->affiliateService->isEnabled()) {
            return ApiResponseService::errorResponse('Affiliate system is disabled', [], 403);
        }

        $user = Auth::user();
        $link = $this->affiliateService->generateAffiliateLink($user);
        
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://skillso.net')), '/');
        $referralLink = $frontendUrl . '/auth/sign-up?referral=' . $link->code;

        return ApiResponseService::successResponse('My link', [
            'code' => $link->code,
            'referral_code' => $link->code,
            'referral_link' => $referralLink,
        ]);
    }

    public function getStats()
    {
        $user = Auth::user();
        $link = AffiliateLink::where('user_id', $user->id)->first();
        
        $clicks = $link ? $link->total_clicks : 0;
        $conversions = $link ? $link->total_conversions : 0;
        $referrals = User::where('referred_by', $user->id)->count();
        
        $totalEarnings = AffiliateCommission::forAffiliate($user->id)->whereNotIn('status', ['cancelled'])->sum('amount');
        $pending = $this->affiliateService->getPendingBalance($user);
        $available = $this->affiliateService->getAvailableBalance($user);
        $withdrawn = AffiliateWithdrawal::where('affiliate_id', $user->id)->where('status', 'completed')->sum('amount');
        
        return ApiResponseService::successResponse('Stats', [
            'clicks' => $clicks,
            'total_clicks' => $clicks,
            'referrals' => $referrals,
            'total_referrals' => $referrals,
            'conversions' => $conversions,
            'total_conversions' => $conversions,
            'total_earnings_egp' => (float) $totalEarnings,
            'pending_earnings_egp' => (float) $pending,
            'available_balance_egp' => (float) $available,
            'paid_earnings_egp' => (float) $withdrawn,
            'withdrawn_egp' => (float) $withdrawn,
            'currency' => 'EGP',
        ]);
    }

    public function getCommissions(Request $request)
    {
        $user = Auth::user();
        $query = AffiliateCommission::forAffiliate($user->id)
            ->with(['referredUser:id,name,email', 'plan:id,name']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }
        
        $commissions = $query->orderByDesc('id')->paginate($request->input('per_page', 15));
        
        $formatted = collect($commissions->items())->map(function ($c) {
            return [
                'id' => $c->id,
                'commission_id' => $c->id,
                'referrer_user_id' => $c->affiliate_id,
                'referred_user_id' => $c->referred_user_id,
                'referred_user_name' => $c->referredUser->name ?? '',
                'referred_user_email' => $c->referredUser->email ?? '',
                'customer_name' => $c->referredUser->name ?? '',
                'customer_email' => $c->referredUser->email ?? '',
                'plan_id' => $c->plan_id,
                'plan_name' => $c->plan->name ?? '',
                'subscription_id' => $c->subscription_id,
                'subscription_amount_egp' => $c->commission_rate > 0 ? (float) ($c->amount / ($c->commission_rate / 100)) : 0,
                'commission_rate_percent' => (float) $c->commission_rate,
                'commission_amount_egp' => (float) $c->amount,
                'amount_egp' => (float) $c->amount,
                'currency' => 'EGP',
                'status' => $c->status,
                'purchased_at' => clone $c->created_at,
                'created_at' => $c->created_at->toIso8601String(),
                'payout_due_at' => $c->available_date?->toDateString(),
            ];
        });
        
        return ApiResponseService::successResponse('Commissions', [
            'data' => $formatted,
            'meta' => [
                'current_page' => $commissions->currentPage(),
                'last_page' => $commissions->lastPage(),
                'per_page' => $commissions->perPage(),
                'total' => $commissions->total(),
            ]
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount_egp' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $amount = (float) $request->amount_egp;
        $minAmount = $this->affiliateService->getMinimumWithdrawalAmount();

        if ($amount < $minAmount) {
            return response()->json([
                'success' => false,
                'message' => "Minimum withdrawal amount is {$minAmount} EGP",
                'errors' => ['amount_egp' => ["Minimum withdrawal amount is {$minAmount} EGP"]]
            ], 422);
        }

        $hasPending = AffiliateWithdrawal::where('affiliate_id', $user->id)->where('status', 'pending')->exists();
        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending withdrawal request.',
                'errors' => ['amount_egp' => ['You already have a pending withdrawal request.']]
            ], 422);
        }

        try {
            $withdrawal = $this->affiliateService->requestWithdrawal($user, $amount);
            
            return ApiResponseService::successResponse('Withdrawal request submitted', [
                'id' => $withdrawal->id,
                'amount_egp' => (float) $withdrawal->amount,
                'currency' => 'EGP',
                'status' => $withdrawal->status,
                'requested_at' => $withdrawal->requested_at->toIso8601String(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['amount_egp' => [$e->getMessage()]]
            ], 422);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to request withdrawal: ' . $e->getMessage());
        }
    }

    public function transferToWallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount_egp' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $amount = (float) $request->amount_egp;

        try {
            $this->affiliateService->transferCommissionToWallet($user, $amount);
            
            return ApiResponseService::successResponse('Funds successfully transferred to your wallet.', [
                'transferred_amount_egp' => $amount,
                'new_wallet_balance' => (float) $user->fresh()->wallet_balance,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['amount_egp' => [$e->getMessage()]]
            ], 422);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to transfer funds to wallet: ' . $e->getMessage());
        }
    }

    public function getWithdrawals(Request $request)
    {
        $user = Auth::user();
        $perPage = $request->input('per_page', 15);
        $withdrawals = $this->affiliateService->getWithdrawals($user, $perPage);
        
        $formatted = collect($withdrawals->items())->map(function ($w) {
            return [
                'id' => $w->id,
                'withdrawal_id' => $w->id,
                'amount_egp' => (float) $w->amount,
                'currency' => 'EGP',
                'status' => $w->status,
                'requested_at' => clone $w->requested_at,
                'created_at' => $w->created_at->toIso8601String(),
                'paid_at' => $w->processed_at?->toIso8601String(),
                'admin_note' => $w->rejection_reason,
            ];
        });

        return ApiResponseService::successResponse('Withdrawals', [
            'data' => $formatted,
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ]
        ]);
    }
}
