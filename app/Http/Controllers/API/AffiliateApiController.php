<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Services\AffiliateService;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class AffiliateApiController extends Controller
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {}

    /**
     * Check if affiliate system is enabled.
     * Returns 404 when disabled.
     */
    public function status(): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        ApiResponseService::successResponse('OK', ['enabled' => true]);
    }

    /**
     * Get or generate affiliate link for authenticated user.
     */
    public function getMyLink(): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Authentication required.', null, 401);
        }

        $link = $this->affiliateService->generateAffiliateLink($user);
        $refUrl = url('/api/ref/' . $link->code);

        ApiResponseService::successResponse('OK', [
            'code' => $link->code,
            'url' => $refUrl,
        ]);
    }

    /**
     * Get affiliate stats for authenticated user.
     */
    public function getStats(): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Authentication required.', null, 401);
        }

        $link = $this->affiliateService->generateAffiliateLink($user);

        ApiResponseService::successResponse('OK', [
            'available_balance' => $this->affiliateService->getAvailableBalance($user),
            'pending_balance' => $this->affiliateService->getPendingBalance($user),
            'total_conversions' => $link->total_conversions,
            'total_clicks' => $link->total_clicks,
        ]);
    }

    /**
     * Get paginated commission list with optional status filter.
     */
    public function getCommissions(Request $request): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Authentication required.', null, 401);
        }

        $perPage = min((int) $request->input('per_page', 15), 50);
        $perPage = max($perPage, 1);
        $status = $request->input('status'); // pending, available, withdrawn

        $query = AffiliateCommission::forAffiliate($user->id)
            ->with(['plan', 'referredUser:id,name,email'])
            ->orderByDesc('earned_date');

        if (in_array($status, ['pending', 'available', 'withdrawn'], true)) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $commissions = $paginator->getCollection()->map(fn (AffiliateCommission $c) => [
            'id' => $c->id,
            'amount' => (float) $c->amount,
            'commission_rate' => (float) $c->commission_rate,
            'status' => $c->status,
            'earned_date' => $c->earned_date?->format('Y-m-d'),
            'available_date' => $c->available_date?->format('Y-m-d'),
            'plan_name' => $c->plan?->name,
            'referred_user' => $c->referredUser ? [
                'id' => $c->referredUser->id,
                'name' => $c->referredUser->name,
                'email' => $c->referredUser->email ?? null,
            ] : null,
        ]);

        ApiResponseService::successResponse('OK', [
            'commissions' => $commissions,
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
     * Request a withdrawal.
     */
    public function requestWithdrawal(Request $request): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Authentication required.', null, 401);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $withdrawal = $this->affiliateService->requestWithdrawal($user, (float) $request->input('amount'));

            ApiResponseService::successResponse('Withdrawal request submitted successfully', [
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'amount' => (float) $withdrawal->amount,
                    'status' => $withdrawal->status,
                    'requested_at' => $withdrawal->requested_at?->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            ApiResponseService::errorResponse($e->getMessage(), null, 422);
        }
    }

    /**
     * Get paginated withdrawal history.
     */
    public function getWithdrawals(Request $request): never
    {
        if (!$this->affiliateService->isEnabled()) {
            ApiResponseService::errorResponse('Affiliate system is not available.', null, 404);
        }

        $user = Auth::user();
        if (!$user) {
            ApiResponseService::errorResponse('Authentication required.', null, 401);
        }

        $perPage = min((int) $request->input('per_page', 15), 50);
        $perPage = max($perPage, 1);

        $paginator = $this->affiliateService->getWithdrawals($user, $perPage);

        $withdrawals = $paginator->getCollection()->map(fn (AffiliateWithdrawal $w) => [
            'id' => $w->id,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'requested_at' => $w->requested_at?->format('Y-m-d H:i:s'),
            'processed_at' => $w->processed_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $w->rejection_reason,
        ]);

        ApiResponseService::successResponse('OK', [
            'withdrawals' => $withdrawals,
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
     * Track referral click, store in session/cookie, redirect.
     */
    public function trackReferral(string $code): JsonResponse|RedirectResponse
    {
        if (!$this->affiliateService->isEnabled()) {
            return response()->json(['error' => true, 'message' => 'Not found'], 404);
        }

        $this->affiliateService->trackClick($code);

        session()->put('affiliate_code', $code);
        Cookie::queue('affiliate_code', $code, 60 * 24 * 30); // 30 days

        $redirectUrl = config('app.url', url('/'));

        return redirect()->away($redirectUrl);
    }
}
