<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\FeatureFlag;
use App\Models\Setting;
use App\Services\AffiliateService;
use App\Services\CachingService;
use App\Services\FeatureFlagService;
use App\Services\ResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class AffiliateController extends Controller
{
    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly FeatureFlagService $featureFlagService
    ) {}

    /**
     * Return affiliate settings (enabled, min_withdrawal).
     */
    public function settings(): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $enabled = $this->affiliateService->isEnabled();
        $minWithdrawal = $this->affiliateService->getMinimumWithdrawalAmount();

        return response()->json([
            'error' => false,
            'data' => [
                'enabled' => $enabled,
                'min_withdrawal' => $minWithdrawal,
            ],
        ]);
    }

    /**
     * Update affiliate settings and toggle feature flag.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $validator = Validator::make($request->all(), [
            'enabled' => 'nullable|boolean',
            'min_withdrawal' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        if ($request->has('enabled')) {
            $flag = FeatureFlag::where('key', 'affiliate_system')->first();
            if ($flag) {
                $flag->is_enabled = (bool) $request->input('enabled');
                $flag->save();
                $this->featureFlagService->clearCache('affiliate_system');
            }
        }

        if ($request->has('min_withdrawal')) {
            Setting::updateOrCreate(
                ['name' => 'affiliate_min_withdrawal'],
                ['value' => (string) $request->input('min_withdrawal'), 'type' => 'number']
            );
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
        }

        return response()->json([
            'error' => false,
            'message' => 'Settings updated',
            'data' => [
                'enabled' => $this->affiliateService->isEnabled(),
                'min_withdrawal' => $this->affiliateService->getMinimumWithdrawalAmount(),
            ],
        ]);
    }

    /**
     * List pending withdrawal requests.
     */
    public function pendingWithdrawals(Request $request): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $perPage = min((int) $request->input('per_page', 15), 50);
        $perPage = max($perPage, 1);

        $paginator = AffiliateWithdrawal::where('status', 'pending')
            ->with('affiliate:id,name,email')
            ->orderByDesc('requested_at')
            ->paginate($perPage);

        $withdrawals = $paginator->getCollection()->map(fn ($w) => [
            'id' => $w->id,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'requested_at' => $w->requested_at?->format('Y-m-d H:i:s'),
            'affiliate' => $w->affiliate ? [
                'id' => $w->affiliate->id,
                'name' => $w->affiliate->name,
                'email' => $w->affiliate->email,
            ] : null,
        ]);

        return response()->json([
            'error' => false,
            'data' => [
                'withdrawals' => $withdrawals,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Approve a withdrawal.
     */
    public function approveWithdrawal(int $id): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $withdrawal = AffiliateWithdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['error' => true, 'message' => 'Withdrawal not found'], 404);
        }

        try {
            $admin = Auth::user();
            if (!$admin) {
                return response()->json(['error' => true, 'message' => 'Unauthorized'], 401);
            }

            $this->affiliateService->processWithdrawal($withdrawal, $admin);

            return response()->json([
                'error' => false,
                'message' => 'Withdrawal approved',
                'data' => [
                    'id' => $withdrawal->id,
                    'status' => $withdrawal->fresh()->status,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reject a withdrawal with reason.
     */
    public function rejectWithdrawal(Request $request, int $id): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $withdrawal = AffiliateWithdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['error' => true, 'message' => 'Withdrawal not found'], 404);
        }

        try {
            $admin = Auth::user();
            if (!$admin) {
                return response()->json(['error' => true, 'message' => 'Unauthorized'], 401);
            }

            $this->affiliateService->rejectWithdrawal($withdrawal, $request->input('reason'), $admin);

            return response()->json([
                'error' => false,
                'message' => 'Withdrawal rejected',
                'data' => [
                    'id' => $withdrawal->id,
                    'status' => $withdrawal->fresh()->status,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Paginated all commissions with filters.
     */
    public function allCommissions(Request $request): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $perPage = min((int) $request->input('per_page', 15), 50);
        $perPage = max($perPage, 1);
        $status = $request->input('status');
        $affiliateId = $request->input('affiliate_id');

        $query = AffiliateCommission::with(['affiliate:id,name,email', 'plan', 'referredUser:id,name,email'])
            ->orderByDesc('earned_date');

        if (in_array($status, ['pending', 'available', 'withdrawn', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        if ($affiliateId) {
            $query->where('affiliate_id', $affiliateId);
        }

        $paginator = $query->paginate($perPage);

        $commissions = $paginator->getCollection()->map(fn ($c) => [
            'id' => $c->id,
            'amount' => (float) $c->amount,
            'commission_rate' => (float) $c->commission_rate,
            'status' => $c->status,
            'earned_date' => $c->earned_date?->format('Y-m-d'),
            'available_date' => $c->available_date?->format('Y-m-d'),
            'affiliate' => $c->affiliate ? [
                'id' => $c->affiliate->id,
                'name' => $c->affiliate->name,
                'email' => $c->affiliate->email,
            ] : null,
            'plan_name' => $c->plan?->name,
            'referred_user' => $c->referredUser ? [
                'id' => $c->referredUser->id,
                'name' => $c->referredUser->name,
                'email' => $c->referredUser->email ?? null,
            ] : null,
        ]);

        return response()->json([
            'error' => false,
            'data' => [
                'commissions' => $commissions,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * System-wide affiliate stats.
     */
    public function stats(): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('manage_affiliates');

        $stats = $this->affiliateService->getSystemStats();

        return response()->json([
            'error' => false,
            'data' => $stats,
        ]);
    }
}
