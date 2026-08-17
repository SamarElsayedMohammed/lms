<?php

namespace App\Services;

use App\Exceptions\PromoQuotaExceededException;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionPromoService
{
    public const RESERVATION_EXPIRY_HOURS = 4;

    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Canonical promo code normalization.
     */
    public static function normalizeCode(?string $code): string
    {
        if ($code === null) {
            return '';
        }
        return strtoupper(trim((string) $code));
    }

    /**
     * Validate a promo code against a subscription plan, user, and country context.
     *
     * @return array{
     *   valid: bool,
     *   message: string,
     *   promo?: PromoCode,
     *   promo_code?: string,
     *   discount_type?: string,
     *   discount_value?: float,
     *   discount_percent?: float,
     *   discount_amount?: float,
     *   original_amount?: float,
     *   total_amount?: float,
     *   currency?: string,
     *   country_code?: string,
     *   price_source?: string
     * }
     */
    public function validatePromo(string $rawCode, int $planId, ?User $user = null, ?string $countryCode = null): array
    {
        $normalizedCode = self::normalizeCode($rawCode);
        if ($normalizedCode === '') {
            return [
                'valid' => false,
                'message' => 'كود الخصم غير صالح.',
            ];
        }

        // Lazy cleanup of expired reservations so quota is kept fresh
        $this->reclaimExpiredReservations();

        /** @var PromoCode|null $promo */
        $promo = PromoCode::where(function ($q) use ($normalizedCode) {
            $q->where('promo_code', $normalizedCode)
              ->orWhereRaw('UPPER(promo_code) = ?', [$normalizedCode]);
        })
        ->where('status', 1)
        ->first();

        if (! $promo) {
            return [
                'valid' => false,
                'message' => 'كود الخصم غير صحيح أو غير مفعل.',
            ];
        }

        // Date validity check (DEF-04): end_date is valid through end of day
        $now = now();
        if ($promo->start_date && $promo->start_date->copy()->startOfDay()->isFuture()) {
            return [
                'valid' => false,
                'message' => 'كود الخصم لم يبدأ بعد.',
            ];
        }

        if ($promo->end_date && $promo->end_date->copy()->endOfDay()->isPast()) {
            return [
                'valid' => false,
                'message' => 'كود الخصم منتهي الصلاحية.',
            ];
        }

        // Plan restrictions
        if ($promo->subscriptionPlans()->exists()) {
            $isPlanAllowed = $promo->subscriptionPlans()->where('subscription_plans.id', $planId)->exists();
            if (! $isPlanAllowed) {
                return [
                    'valid' => false,
                    'message' => 'كود الخصم غير صالح لهذه الباقة.',
                ];
            }
        }

        // Global quota check
        if ($promo->no_of_users !== null && $promo->no_of_users <= 0) {
            return [
                'valid' => false,
                'message' => 'كود الخصم وصل للحد الأقصى من المستخدمين.',
            ];
        }

        // Per-user usage check (DEF-03)
        if ($user !== null) {
            $userCheck = $this->checkUserEligibility($promo, $user->id, $normalizedCode);
            if (! $userCheck['allowed']) {
                return [
                    'valid' => false,
                    'message' => $userCheck['message'],
                ];
            }
        }

        // Resolve plan and localized pricing
        $plan = SubscriptionPlan::find($planId);
        if (! $plan) {
            return [
                'valid' => false,
                'message' => 'الباقة المحددة غير موجودة.',
            ];
        }

        $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);
        $originalAmount = (float) $countryPricing['price'];
        $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');

        $discountVal = (float) $promo->discount;
        $discountType = $promo->discount_type;

        if ($discountType === 'percentage') {
            $safePercent = max(0.0, min($discountVal, 100.0));
            $discountAmount = round($originalAmount * ($safePercent / 100.0), 2);
            $discountPercent = $safePercent;
        } else {
            $safeFixed = max(0.0, $discountVal);
            $discountAmount = $this->pricingService->convertFromEgp($safeFixed, $resolvedCurrency);
            $discountAmount = min($discountAmount, $originalAmount);
            $discountPercent = $originalAmount > 0 ? round(($discountAmount / $originalAmount) * 100, 2) : 0.0;
        }

        $totalAmount = max(round($originalAmount - $discountAmount, 2), 0.0);

        return [
            'valid' => true,
            'message' => $promo->message ?: 'كود الخصم صالح',
            'promo' => $promo,
            'promo_code' => $promo->promo_code,
            'discount_type' => $discountType,
            'discount_value' => $discountVal,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'original_amount' => $originalAmount,
            'total_amount' => $totalAmount,
            'currency' => $resolvedCurrency,
            'country_code' => $countryCode ?? '',
            'price_source' => $countryPricing['price_source'] ?? 'default',
        ];
    }

    /**
     * Check if a specific user is eligible to use this promo code based on repeat rules.
     */
    public function checkUserEligibility(PromoCode $promo, int $userId, ?string $normalizedCode = null): array
    {
        $code = $normalizedCode ?: self::normalizeCode($promo->promo_code);

        // Count completed redemptions
        $completedCount = SubscriptionPayment::where('user_id', $userId)
            ->where(function ($q) use ($code) {
                $q->where('promo_code', $code)
                  ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
            })
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->count();

        // Count active pending reservations within expiry window
        $activePendingCount = SubscriptionPayment::where('user_id', $userId)
            ->where(function ($q) use ($code) {
                $q->where('promo_code', $code)
                  ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
            })
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->where('created_at', '>=', now()->subHours(self::RESERVATION_EXPIRY_HOURS))
            ->count();

        $totalUsages = $completedCount + $activePendingCount;

        if (! $promo->repeat_usage) {
            if ($totalUsages >= 1) {
                return [
                    'allowed' => false,
                    'message' => 'لقد استخدمت كود الخصم هذا من قبل.',
                ];
            }
        } else {
            $maxRepeat = (int) $promo->no_of_repeat_usage;
            if ($maxRepeat > 0 && $totalUsages >= $maxRepeat) {
                return [
                    'allowed' => false,
                    'message' => 'لقد وصلت للحد الأقصى المسموح لاستخدام هذا الكود.',
                ];
            }
        }

        return [
            'allowed' => true,
            'message' => '',
        ];
    }

    /**
     * Atomically claim promo code quota using where condition.
     *
     * @throws PromoQuotaExceededException
     */
    public function claimPromoQuota(PromoCode $promo): void
    {
        if ($promo->no_of_users !== null) {
            $affected = PromoCode::where('id', $promo->id)
                ->where('no_of_users', '>', 0)
                ->decrement('no_of_users');

            if ($affected === 0) {
                throw new PromoQuotaExceededException('كوبون الخصم استنفذ الحد الأقصى للاستخدام');
            }
        }
    }

    /**
     * Atomically reserve promo code quota during checkout initiation.
     *
     * @return array{success: bool, message?: string, promo?: PromoCode}
     */
    public function reservePromo(string $rawCode, int $userId, int $planId, ?string $countryCode = null): array
    {
        $normalizedCode = self::normalizeCode($rawCode);
        if ($normalizedCode === '') {
            return ['success' => false, 'message' => 'كود الخصم غير صالح.'];
        }

        try {
            return DB::transaction(function () use ($normalizedCode, $userId, $planId, $countryCode) {
                /** @var PromoCode|null $promo */
                $promo = PromoCode::where(function ($q) use ($normalizedCode) {
                    $q->where('promo_code', $normalizedCode)
                      ->orWhereRaw('UPPER(promo_code) = ?', [$normalizedCode]);
                })
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

                if (! $promo) {
                    return ['success' => false, 'message' => 'كود الخصم غير صحيح أو غير مفعل.'];
                }

                // Date checks
                if ($promo->start_date && $promo->start_date->copy()->startOfDay()->isFuture()) {
                    return ['success' => false, 'message' => 'كود الخصم لم يبدأ بعد.'];
                }

                if ($promo->end_date && $promo->end_date->copy()->endOfDay()->isPast()) {
                    return ['success' => false, 'message' => 'كود الخصم منتهي الصلاحية.'];
                }

                // Plan checks
                if ($promo->subscriptionPlans()->exists()) {
                    $isPlanAllowed = $promo->subscriptionPlans()->where('subscription_plans.id', $planId)->exists();
                    if (! $isPlanAllowed) {
                        return ['success' => false, 'message' => 'كود الخصم غير صالح لهذه الباقة.'];
                    }
                }

                // Per-user check
                $userCheck = $this->checkUserEligibility($promo, $userId, $normalizedCode);
                if (! $userCheck['allowed']) {
                    return ['success' => false, 'message' => $userCheck['message']];
                }

                // Global quota check & atomic decrement
                $this->claimPromoQuota($promo);

                return [
                    'success' => true,
                    'promo' => $promo,
                ];
            });
        } catch (PromoQuotaExceededException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Atomically release a promo reservation (e.g. on failed payment, abandoned session, or admin rejection).
     */
    public function releasePromo(?string $promoCode): bool
    {
        $code = self::normalizeCode($promoCode);
        if ($code === '') {
            return true;
        }

        return DB::transaction(function () use ($code) {
            /** @var PromoCode|null $promo */
            $promo = PromoCode::withTrashed()
                ->where(function ($q) use ($code) {
                    $q->where('promo_code', $code)
                      ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
                })
                ->lockForUpdate()
                ->first();

            if ($promo && $promo->no_of_users !== null) {
                $promo->increment('no_of_users');
                Log::info('Promo quota released', [
                    'promo_code' => $code,
                    'new_no_of_users' => $promo->fresh()->no_of_users,
                ]);
            }

            return true;
        });
    }

    /**
     * Sweep and reclaim expired pending reservations older than RESERVATION_EXPIRY_HOURS.
     */
    public function reclaimExpiredReservations(): int
    {
        $cutoff = now()->subHours(self::RESERVATION_EXPIRY_HOURS);

        $expiredPayments = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_PENDING)
            ->whereNotNull('promo_code')
            ->where('promo_code', '!=', '')
            ->where('created_at', '<', $cutoff)
            ->get();

        $reclaimedCount = 0;

        foreach ($expiredPayments as $payment) {
            DB::transaction(function () use ($payment, &$reclaimedCount) {
                $lockedPayment = SubscriptionPayment::where('id', $payment->id)
                    ->where('status', SubscriptionPayment::STATUS_PENDING)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedPayment) {
                    return;
                }

                $lockedPayment->status = SubscriptionPayment::STATUS_FAILED;
                $lockedPayment->admin_notes = 'Payment intent expired automatically';
                $lockedPayment->save();

                if ($lockedPayment->subscription && in_array($lockedPayment->subscription->status, [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL], true)) {
                    $lockedPayment->subscription->status = Subscription::STATUS_CANCELLED;
                    $lockedPayment->subscription->save();
                }

                $this->releasePromo($lockedPayment->promo_code);
                $reclaimedCount++;
            });
        }

        return $reclaimedCount;
    }
}
