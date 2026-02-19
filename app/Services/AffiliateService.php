<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AffiliateLink;
use App\Models\AffiliateWithdrawal;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AffiliateService
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService
    ) {}

    public function isEnabled(): bool
    {
        return $this->featureFlagService->isEnabled('affiliate_system', false);
    }

    public function generateAffiliateLink(User $user): AffiliateLink
    {
        $existing = AffiliateLink::where('user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        do {
            $code = Str::upper(Str::random(8));
        } while (AffiliateLink::where('code', $code)->exists());

        return AffiliateLink::create([
            'user_id' => $user->id,
            'code' => $code,
            'total_clicks' => 0,
            'total_conversions' => 0,
            'is_active' => true,
        ]);
    }

    public function trackClick(string $code): void
    {
        $link = AffiliateLink::where('code', $code)->where('is_active', true)->first();

        if ($link !== null) {
            $link->increment('total_clicks');
        }
    }

    public function processReferral(User $referredUser, Subscription $subscription): ?AffiliateCommission
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $affiliateId = $this->resolveAffiliateId($referredUser);
        if ($affiliateId === null) {
            return null;
        }

        if ($affiliateId === $referredUser->id) {
            return null;
        }

        $existingCommission = AffiliateCommission::where('referred_user_id', $referredUser->id)
            ->where('subscription_id', $subscription->id)
            ->exists();
        if ($existingCommission) {
            return null;
        }

        $plan = $subscription->plan;
        $payment = $subscription->payments()->where('status', 'completed')->first();
        $paymentAmount = $payment ? (float) $payment->amount : (float) $plan->price;

        $commissionRate = (float) ($plan->commission_rate ?? 0);
        if ($commissionRate <= 0) {
            return null;
        }

        $amount = round($paymentAmount * ($commissionRate / 100), 2);
        $earnedDate = Carbon::today();
        $availableDate = $this->calculateAvailableDate($earnedDate);

        $periodStart = $earnedDate->day <= 15
            ? $earnedDate->copy()->startOfMonth()
            : $earnedDate->copy()->day(16);
        $periodEnd = $earnedDate->day <= 15
            ? $earnedDate->copy()->day(15)
            : $earnedDate->copy()->endOfMonth();

        return DB::transaction(function () use ($affiliateId, $referredUser, $subscription, $plan, $amount, $commissionRate, $earnedDate, $availableDate, $periodStart, $periodEnd) {
            $commission = AffiliateCommission::create([
                'affiliate_id' => $affiliateId,
                'referred_user_id' => $referredUser->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'amount' => $amount,
                'commission_rate' => $commissionRate,
                'status' => 'pending',
                'earned_date' => $earnedDate,
                'available_date' => $availableDate,
                'settlement_period_start' => $periodStart,
                'settlement_period_end' => $periodEnd,
            ]);

            AffiliateLink::where('user_id', $affiliateId)->increment('total_conversions');

            return $commission;
        });
    }

    public function calculateAvailableDate(Carbon $earnedDate): Carbon
    {
        if ($earnedDate->day <= 15) {
            return $earnedDate->copy()->day(28);
        }

        return $earnedDate->copy()->addMonth()->day(15);
    }

    public function getAvailableBalance(User $user): float
    {
        return (float) AffiliateCommission::forAffiliate($user->id)
            ->available()
            ->sum('amount');
    }

    public function getPendingBalance(User $user): float
    {
        return (float) AffiliateCommission::forAffiliate($user->id)
            ->pending()
            ->sum('amount');
    }

    public function getMinimumWithdrawalAmount(): float
    {
        $value = CachingService::getSystemSettings('affiliate_min_withdrawal');

        return $value !== '' && $value !== null ? (float) $value : 500.0;
    }

    /**
     * Request a withdrawal. Validates amount, selects available commissions, marks them withdrawn.
     *
     * @throws \InvalidArgumentException When validation fails
     */
    public function requestWithdrawal(User $user, float $amount): AffiliateWithdrawal
    {
        if (!$this->isEnabled()) {
            throw new \InvalidArgumentException('Affiliate system is not enabled.');
        }

        $minAmount = $this->getMinimumWithdrawalAmount();
        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("Minimum withdrawal amount is {$minAmount}.");
        }

        $availableBalance = $this->getAvailableBalance($user);
        if ($availableBalance < $amount) {
            throw new \InvalidArgumentException('Insufficient available balance.');
        }

        return DB::transaction(function () use ($user, $amount) {
            $commissions = AffiliateCommission::forAffiliate($user->id)
                ->available()
                ->orderBy('available_date')
                ->orderBy('id')
                ->get();

            $selected = [];
            $sum = 0.0;

            foreach ($commissions as $commission) {
                $selected[] = $commission;
                $sum += (float) $commission->amount;
                if ($sum >= $amount) {
                    break;
                }
            }

            if ($sum < $amount) {
                throw new \InvalidArgumentException('Insufficient available balance.');
            }

            $commissionIds = array_map(fn (AffiliateCommission $c) => $c->id, $selected);
            $withdrawalAmount = round($sum, 2);

            foreach ($selected as $commission) {
                $commission->update([
                    'status' => 'withdrawn',
                    'withdrawn_at' => now(),
                ]);
            }

            return AffiliateWithdrawal::create([
                'affiliate_id' => $user->id,
                'amount' => $withdrawalAmount,
                'commission_ids' => $commissionIds,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Get paginated withdrawal history for a user.
     */
    public function getWithdrawals(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return AffiliateWithdrawal::where('affiliate_id', $user->id)
            ->orderByDesc('requested_at')
            ->paginate($perPage);
    }

    /**
     * Process (approve) a withdrawal.
     */
    public function processWithdrawal(AffiliateWithdrawal $withdrawal, User $admin): void
    {
        if ($withdrawal->status !== 'pending') {
            throw new \InvalidArgumentException('Withdrawal is not pending.');
        }

        $withdrawal->update([
            'status' => 'completed',
            'processed_at' => now(),
            'processed_by' => $admin->id,
        ]);
    }

    /**
     * Reject a withdrawal and revert commissions to available.
     */
    public function rejectWithdrawal(AffiliateWithdrawal $withdrawal, string $reason, User $admin): void
    {
        if ($withdrawal->status !== 'pending') {
            throw new \InvalidArgumentException('Withdrawal is not pending.');
        }

        DB::transaction(function () use ($withdrawal, $reason, $admin) {
            $withdrawal->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'processed_at' => now(),
                'processed_by' => $admin->id,
            ]);

            AffiliateCommission::whereIn('id', $withdrawal->commission_ids)
                ->update([
                    'status' => 'available',
                    'withdrawn_at' => null,
                ]);
        });
    }

    /**
     * Get system-wide affiliate stats for admin.
     *
     * @return array{total_commissions: float, total_payouts: float, pending_withdrawals: float}
     */
    public function getSystemStats(): array
    {
        $totalCommissions = (float) AffiliateCommission::sum('amount');
        $totalPayouts = (float) AffiliateWithdrawal::where('status', 'completed')->sum('amount');
        $pendingWithdrawals = (float) AffiliateWithdrawal::where('status', 'pending')->sum('amount');

        return [
            'total_commissions' => $totalCommissions,
            'total_payouts' => $totalPayouts,
            'pending_withdrawals' => $pendingWithdrawals,
        ];
    }

    /**
     * Release pending commissions to available when available_date has passed.
     * Returns the count of commissions released.
     */
    public function releaseCommissions(): int
    {
        $today = now()->toDateString();

        $commissions = AffiliateCommission::where('status', 'pending')
            ->where('available_date', '<=', $today)
            ->get();

        $count = $commissions->count();

        foreach ($commissions as $commission) {
            $commission->update(['status' => 'available']);
        }

        return $count;
    }

    private function resolveAffiliateId(User $referredUser): ?int
    {
        if (session()->has('affiliate_code')) {
            $link = AffiliateLink::where('code', session('affiliate_code'))->first();

            return $link?->user_id;
        }

        $hasReferredBy = \Illuminate\Support\Facades\Cache::remember('schema_users_has_referred_by', 3600, function () {
            return Schema::hasColumn('users', 'referred_by');
        });
        if ($hasReferredBy) {
            return $referredUser->referred_by;
        }

        return null;
    }
}
