<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class SubscriptionReportService
{
    /**
     * Country metadata mapping with Arabic/English names and flag emojis.
     */
    private const COUNTRY_META = [
        'EG' => ['name_ar' => 'مصر', 'name_en' => 'Egypt', 'flag' => '🇪🇬', 'color' => '#e50914'],
        'SA' => ['name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia', 'flag' => '🇸🇦', 'color' => '#10b981'],
        'AE' => ['name_ar' => 'الإمارات', 'name_en' => 'UAE', 'flag' => '🇦🇪', 'color' => '#f59e0b'],
        'KW' => ['name_ar' => 'الكويت', 'name_en' => 'Kuwait', 'flag' => '🇰🇼', 'color' => '#3b82f6'],
        'QA' => ['name_ar' => 'قطر', 'name_en' => 'Qatar', 'flag' => '🇶🇦', 'color' => '#8b5cf6'],
        'OM' => ['name_ar' => 'عمان', 'name_en' => 'Oman', 'flag' => '🇴🇲', 'color' => '#ec4899'],
        'BH' => ['name_ar' => 'البحرين', 'name_en' => 'Bahrain', 'flag' => '🇧🇭', 'color' => '#06b6d4'],
        'JO' => ['name_ar' => 'الأردن', 'name_en' => 'Jordan', 'flag' => '🇯🇴', 'color' => '#84cc16'],
    ];

    /**
     * Get Global Subscription Report summary, metrics, time series, and plan summaries.
     */
    public function getGlobalOverviewReport(array $filters): array
    {
        $dates = $this->resolveFilterDates($filters);
        $currentStart = $dates['current_start'];
        $currentEnd = $dates['current_end'];
        $prevStart = $dates['prev_start'];
        $prevEnd = $dates['prev_end'];

        $paymentMethod = $filters['payment_method'] ?? null;
        $country = isset($filters['country']) ? strtoupper((string) $filters['country']) : null;
        $statusFilter = $filters['status'] ?? 'all';

        // 1. Current Period Metrics
        $currentPaymentsQuery = $this->getBasePaymentsQuery($currentStart, $currentEnd, $paymentMethod, $country);
        $currentRevenueEgp = (float) (clone $currentPaymentsQuery)->sum(DB::raw($this->getEgpAmountSql()));
        $currentOrdersCount = (int) (clone $currentPaymentsQuery)->count();

        $currentSubsQuery = $this->getBaseSubscriptionsQuery($currentStart, $currentEnd, $statusFilter, $paymentMethod, $country);
        $currentSubscribersCount = (int) (clone $currentSubsQuery)->distinct('user_id')->count('user_id');
        $currentActiveCount = (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_ACTIVE)->distinct('user_id')->count('user_id');
        $currentExpiredCount = (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_EXPIRED)->distinct('user_id')->count('user_id');

        // 2. Previous Period Metrics (for comparisons)
        $prevPaymentsQuery = $this->getBasePaymentsQuery($prevStart, $prevEnd, $paymentMethod, $country);
        $prevRevenueEgp = (float) (clone $prevPaymentsQuery)->sum(DB::raw($this->getEgpAmountSql()));
        $prevOrdersCount = (int) (clone $prevPaymentsQuery)->count();

        $prevSubsQuery = $this->getBaseSubscriptionsQuery($prevStart, $prevEnd, $statusFilter, $paymentMethod, $country);
        $prevSubscribersCount = (int) (clone $prevSubsQuery)->distinct('user_id')->count('user_id');
        $prevExpiredCount = (int) (clone $prevSubsQuery)->where('status', Subscription::STATUS_EXPIRED)->distinct('user_id')->count('user_id');

        // 3. Comparisons Calculation
        $comparisons = [
            'revenue' => $this->buildComparisonMetric($currentRevenueEgp, $prevRevenueEgp),
            'orders' => $this->buildComparisonMetric($currentOrdersCount, $prevOrdersCount),
            'subscribers' => $this->buildComparisonMetric($currentSubscribersCount, $prevSubscribersCount),
            'expired' => $this->buildComparisonMetric($currentExpiredCount, $prevExpiredCount),
        ];

        // 4. Time Series Data (Revenue Overview Chart)
        $revenueSeries = $this->buildRevenueTimeSeries($currentStart, $currentEnd, $paymentMethod, $country);

        // 5. Status Distribution (Donut Chart)
        $statusDistribution = $this->buildStatusDistribution($currentStart, $currentEnd, $paymentMethod, $country);

        // 6. Plan Summaries Grid
        $plans = $this->buildPlanSummaries($currentStart, $currentEnd, $paymentMethod, $country);

        return [
            'summary' => [
                'total_plans' => count($plans),
                'total_revenue_egp' => round($currentRevenueEgp, 2),
                'total_orders' => $currentOrdersCount,
                'total_subscribers' => $currentSubscribersCount,
                'total_active_subscribers' => $currentActiveCount,
                'total_expired_subscribers' => $currentExpiredCount,
                'total_cancelled_subscribers' => (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_CANCELLED)->count(),
                'total_suspended_subscribers' => 0,
                'comparisons' => $comparisons,
            ],
            'revenue_series' => $revenueSeries,
            'status_distribution' => $statusDistribution,
            'plans' => $plans,
        ];
    }

    /**
     * Get Individual Subscription Plan Detail Report.
     */
    public function getPlanDetailReport(int $planId, array $filters): array
    {
        $plan = SubscriptionPlan::withTrashed()->find($planId);
        if (!$plan) {
            throw new \InvalidArgumentException('الباقة غير موجودة.');
        }

        $dates = $this->resolveFilterDates($filters);
        $currentStart = $dates['current_start'];
        $currentEnd = $dates['current_end'];
        $prevStart = $dates['prev_start'];
        $prevEnd = $dates['prev_end'];

        $paymentMethod = $filters['payment_method'] ?? null;
        $country = isset($filters['country']) ? strtoupper((string) $filters['country']) : null;

        // Base plan queries
        $currentPlanSubs = Subscription::where('plan_id', $planId)
            ->whereBetween('created_at', [$currentStart, $currentEnd]);

        $currentPlanPayments = SubscriptionPayment::whereHas('subscription', function ($q) use ($planId) {
            $q->where('plan_id', $planId);
        })
        ->where('status', SubscriptionPayment::STATUS_COMPLETED)
        ->whereBetween('created_at', [$currentStart, $currentEnd]);

        if ($paymentMethod) {
            $currentPlanPayments->where('payment_method', $paymentMethod);
        }
        if ($country) {
            $currentPlanPayments->where('resolved_country', $country);
        }

        $totalStudents = (int) (clone $currentPlanSubs)->distinct('user_id')->count('user_id');
        $activeSubs = (int) (clone $currentPlanSubs)->where('status', Subscription::STATUS_ACTIVE)->count();
        $expiredSubs = (int) (clone $currentPlanSubs)->where('status', Subscription::STATUS_EXPIRED)->count();
        $totalRevenueEgp = (float) (clone $currentPlanPayments)->sum(DB::raw($this->getEgpAmountSql()));

        // Previous plan metrics
        $prevPlanSubs = Subscription::where('plan_id', $planId)
            ->whereBetween('created_at', [$prevStart, $prevEnd]);
        $prevPlanPayments = SubscriptionPayment::whereHas('subscription', function ($q) use ($planId) {
            $q->where('plan_id', $planId);
        })
        ->where('status', SubscriptionPayment::STATUS_COMPLETED)
        ->whereBetween('created_at', [$prevStart, $prevEnd]);

        $prevStudents = (int) (clone $prevPlanSubs)->distinct('user_id')->count('user_id');
        $prevActive = (int) (clone $prevPlanSubs)->where('status', Subscription::STATUS_ACTIVE)->count();
        $prevExpired = (int) (clone $prevPlanSubs)->where('status', Subscription::STATUS_EXPIRED)->count();
        $prevRevenue = (float) (clone $prevPlanPayments)->sum(DB::raw($this->getEgpAmountSql()));

        $comparisons = [
            'students' => $this->buildComparisonMetric($totalStudents, $prevStudents),
            'active' => $this->buildComparisonMetric($activeSubs, $prevActive),
            'expired' => $this->buildComparisonMetric($expiredSubs, $prevExpired),
            'revenue' => $this->buildComparisonMetric($totalRevenueEgp, $prevRevenue),
        ];

        // Country breakdown table & distribution chart
        $countryBreakdown = $this->buildCountryBreakdown($planId, $currentStart, $currentEnd, $paymentMethod);

        // Monthly growth (12-month series)
        $monthlyGrowth = $this->buildMonthlyGrowth($planId);

        return [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => strtolower($plan->name),
            'badge_variant' => $this->resolveBadgeVariant($plan->name),
            'billing_cycle' => $plan->billing_cycle ?? 'سنوي',
            'status' => $plan->is_active ? 'active' : 'inactive',
            'summary' => [
                'total_students' => $totalStudents,
                'active_subscribers' => $activeSubs,
                'expired_subscribers' => $expiredSubs,
                'total_revenue_egp' => round($totalRevenueEgp, 2),
                'comparisons' => $comparisons,
            ],
            'country_breakdown' => $countryBreakdown['table'],
            'country_totals' => $countryBreakdown['totals'],
            'country_distribution' => $countryBreakdown['distribution'],
            'monthly_growth' => $monthlyGrowth,
        ];
    }

    /**
     * Generate CSV export stream for global subscription report.
     */
    public function generateCsvContent(array $filters): string
    {
        $overview = $this->getGlobalOverviewReport($filters);
        $plans = $overview['plans'];

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csv .= "الرمز,الباقة,إجمالي المشتركين,النشطين,المنتهيين,إجمالي الإيرادات (ج.م)\n";

        foreach ($plans as $p) {
            $code = $p['plan_code'] ?? 'plan';
            $name = str_replace(',', ' ', $p['plan_name']);
            $total = $p['subscribers_count'] ?? $p['total_subscribers'] ?? 0;
            $active = $p['active_subscribers'];
            $expired = $p['expired_subscribers'] ?? 0;
            $revenue = number_format($p['total_revenue_egp'], 2, '.', '');

            $csv .= "{$code},{$name},{$total},{$active},{$expired},{$revenue}\n";
        }

        return $csv;
    }

    // ── Internal Helpers ───────────────────────────────────────────────────

    private function resolveFilterDates(array $filters): array
    {
        $preset = $filters['preset'] ?? '30d';
        $now = Carbon::now();

        if ($preset === 'today') {
            $currentStart = $now->copy()->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDay()->startOfDay();
            $prevEnd = $now->copy()->subDay()->endOfDay();
        } elseif ($preset === '7d') {
            $currentStart = $now->copy()->subDays(7)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDays(14)->startOfDay();
            $prevEnd = $now->copy()->subDays(7)->subSecond();
        } elseif ($preset === '90d') {
            $currentStart = $now->copy()->subDays(90)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDays(180)->startOfDay();
            $prevEnd = $now->copy()->subDays(90)->subSecond();
        } elseif ($preset === '12m') {
            $currentStart = $now->copy()->subMonths(12)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subMonths(24)->startOfDay();
            $prevEnd = $now->copy()->subMonths(12)->subSecond();
        } elseif ($preset === 'custom' && !empty($filters['date_from']) && !empty($filters['date_to'])) {
            $currentStart = Carbon::parse($filters['date_from'])->startOfDay();
            $currentEnd = Carbon::parse($filters['date_to'])->endOfDay();
            $diffDays = $currentStart->diffInDays($currentEnd) ?: 1;
            $prevStart = $currentStart->copy()->subDays($diffDays + 1)->startOfDay();
            $prevEnd = $currentStart->copy()->subSecond();
        } else {
            // Default 30d
            $currentStart = $now->copy()->subDays(30)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDays(60)->startOfDay();
            $prevEnd = $now->copy()->subDays(30)->subSecond();
        }

        return [
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'prev_start' => $prevStart,
            'prev_end' => $prevEnd,
        ];
    }

    private function getBasePaymentsQuery(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country)
    {
        $q = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereBetween('created_at', [$start, $end]);

        if ($paymentMethod) {
            $q->where('payment_method', $paymentMethod);
        }
        if ($country) {
            $q->where('resolved_country', $country);
        }

        return $q;
    }

    private function getBaseSubscriptionsQuery(Carbon $start, Carbon $end, string $status, ?string $paymentMethod, ?string $country)
    {
        $q = Subscription::whereBetween('created_at', [$start, $end]);

        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($paymentMethod || $country) {
            $q->whereHas('payments', function ($pq) use ($paymentMethod, $country) {
                if ($paymentMethod) {
                    $pq->where('payment_method', $paymentMethod);
                }
                if ($country) {
                    $pq->where('resolved_country', $country);
                }
            });
        }

        return $q;
    }

    private function getEgpAmountSql(): string
    {
        return 'COALESCE(subscription_payments.amount_egp, subscription_payments.final_amount * COALESCE(subscription_payments.exchange_rate_snapshot, 1))';
    }

    private function buildComparisonMetric(float|int $current, float|int $previous): array
    {
        $diff = $current - $previous;
        $pct = $previous > 0 ? round(($diff / $previous) * 100, 1) : 0.0;
        $direction = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral');

        return [
            'current' => $current,
            'previous' => $previous,
            'percentage' => abs($pct),
            'direction' => $direction,
            'label' => 'عن الفترة السابقة',
        ];
    }

    private function buildRevenueTimeSeries(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country): array
    {
        $raw = DB::table('subscription_payments')
            ->select(
                DB::raw('DATE(created_at) as date_val'),
                DB::raw('SUM(' . $this->getEgpAmountSql() . ') as total_rev'),
                DB::raw('COUNT(id) as orders_cnt')
            )
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereBetween('created_at', [$start, $end])
            ->when($paymentMethod, fn($q) => $q->where('payment_method', $paymentMethod))
            ->when($country, fn($q) => $q->where('resolved_country', $country))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date_val', 'asc')
            ->get();

        $series = [];
        foreach ($raw as $row) {
            $dt = Carbon::parse($row->date_val);
            $series[] = [
                'date' => $row->date_val,
                'label' => $dt->translatedFormat('j M'),
                'revenue_egp' => round((float) $row->total_rev, 2),
                'orders_count' => (int) $row->orders_cnt,
            ];
        }

        return $series;
    }

    private function buildStatusDistribution(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country): array
    {
        $q = Subscription::whereBetween('created_at', [$start, $end]);
        if ($paymentMethod || $country) {
            $q->whereHas('payments', function ($pq) use ($paymentMethod, $country) {
                if ($paymentMethod) $pq->where('payment_method', $paymentMethod);
                if ($country) $pq->where('resolved_country', $country);
            });
        }

        $active = (clone $q)->where('status', Subscription::STATUS_ACTIVE)->count();
        $expired = (clone $q)->where('status', Subscription::STATUS_EXPIRED)->count();
        $pending = (clone $q)->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])->count();
        $cancelled = (clone $q)->where('status', Subscription::STATUS_CANCELLED)->count();
        $total = $active + $expired + $pending + $cancelled;

        return [
            'active_count' => $active,
            'active_percentage' => $total > 0 ? round(($active / $total) * 100, 1) : 0,
            'expired_count' => $expired,
            'expired_percentage' => $total > 0 ? round(($expired / $total) * 100, 1) : 0,
            'pending_count' => $pending,
            'pending_percentage' => $total > 0 ? round(($pending / $total) * 100, 1) : 0,
            'cancelled_count' => $cancelled,
            'cancelled_percentage' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            'total_count' => $total,
        ];
    }

    private function buildPlanSummaries(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country): array
    {
        $plans = SubscriptionPlan::withTrashed()->get();
        $result = [];

        foreach ($plans as $plan) {
            $subsQuery = Subscription::where('plan_id', $plan->id)
                ->whereBetween('created_at', [$start, $end]);

            $paymentsQuery = SubscriptionPayment::whereHas('subscription', function ($q) use ($plan) {
                $q->where('plan_id', $plan->id);
            })
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereBetween('created_at', [$start, $end]);

            if ($paymentMethod) $paymentsQuery->where('payment_method', $paymentMethod);
            if ($country) $paymentsQuery->where('resolved_country', $country);

            $subsCount = (int) (clone $subsQuery)->count();
            $activeCount = (int) (clone $subsQuery)->where('status', Subscription::STATUS_ACTIVE)->count();
            $expiredCount = (int) (clone $subsQuery)->where('status', Subscription::STATUS_EXPIRED)->count();
            $revEgp = (float) (clone $paymentsQuery)->sum(DB::raw($this->getEgpAmountSql()));

            $variant = $this->resolveBadgeVariant($plan->name);
            $color = $this->resolveBadgeColor($variant);

            $result[] = [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => strtolower($plan->name),
                'billing_cycle' => $plan->billing_cycle ?? 'سنوي',
                'badge_variant' => $variant,
                'badge_color' => $color,
                'subscribers_count' => $subsCount,
                'total_subscribers' => $subsCount,
                'active_subscribers' => $activeCount,
                'expired_subscribers' => $expiredCount,
                'total_revenue_egp' => round($revEgp, 2),
                'price' => (float) $plan->price,
                'is_active' => (bool) $plan->is_active,
                'is_deleted' => $plan->deleted_at !== null,
            ];
        }

        return $result;
    }

    private function buildCountryBreakdown(int $planId, Carbon $start, Carbon $end, ?string $paymentMethod): array
    {
        $raw = DB::table('subscription_payments')
            ->join('subscriptions', 'subscription_payments.subscription_id', '=', 'subscriptions.id')
            ->select(
                'subscription_payments.resolved_country as country_code',
                DB::raw('COUNT(DISTINCT subscriptions.user_id) as subs_cnt'),
                DB::raw('SUM(CASE WHEN subscriptions.status = "active" THEN 1 ELSE 0 END) as active_cnt'),
                DB::raw('SUM(CASE WHEN subscriptions.status = "expired" THEN 1 ELSE 0 END) as expired_cnt'),
                DB::raw('SUM(CASE WHEN subscriptions.status = "cancelled" THEN 1 ELSE 0 END) as cancelled_cnt'),
                DB::raw('SUM(' . $this->getEgpAmountSql() . ') as rev_egp'),
                DB::raw('AVG(subscription_payments.final_amount * COALESCE(subscription_payments.exchange_rate_snapshot, 1)) as avg_price_egp')
            )
            ->where('subscriptions.plan_id', $planId)
            ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereBetween('subscriptions.created_at', [$start, $end])
            ->when($paymentMethod, fn($q) => $q->where('subscription_payments.payment_method', $paymentMethod))
            ->groupBy('subscription_payments.resolved_country')
            ->get();

        $table = [];
        $totalSubscribers = 0;
        $totalExpired = 0;
        $totalRevenue = 0.0;

        foreach ($raw as $row) {
            $cc = strtoupper($row->country_code ?: 'EG');
            $meta = self::COUNTRY_META[$cc] ?? [
                'name_ar' => $cc,
                'name_en' => $cc,
                'flag' => '🌐',
                'color' => '#6b7280',
            ];

            $subs = (int) $row->subs_cnt;
            $exp = (int) $row->expired_cnt;
            $rev = (float) $row->rev_egp;

            $totalSubscribers += $subs;
            $totalExpired += $exp;
            $totalRevenue += $rev;

            $table[] = [
                'country_code' => $cc,
                'country_name_ar' => $meta['name_ar'],
                'country_name_en' => $meta['name_en'],
                'flag_emoji' => $meta['flag'],
                'price_egp' => round((float) $row->avg_price_egp, 2),
                'subscribers_count' => $subs,
                'active_count' => (int) $row->active_cnt,
                'expired_count' => $exp,
                'cancelled_count' => (int) $row->cancelled_cnt,
                'total_revenue_egp' => round($rev, 2),
                'percentage_share' => 0, // Populated after total
            ];
        }

        // Calculate percentage shares & distribution
        $distribution = [];
        foreach ($table as &$row) {
            $row['percentage_share'] = $totalSubscribers > 0 ? round(($row['subscribers_count'] / $totalSubscribers) * 100, 1) : 0;
            $meta = self::COUNTRY_META[$row['country_code']] ?? ['color' => '#6b7280'];
            $distribution[] = [
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name_ar'],
                'subscribers_count' => $row['subscribers_count'],
                'percentage' => $row['percentage_share'],
                'color' => $meta['color'],
            ];
        }
        unset($row);

        return [
            'table' => $table,
            'totals' => [
                'total_subscribers' => $totalSubscribers,
                'total_expired' => $totalExpired,
                'total_revenue_egp' => round($totalRevenue, 2),
            ],
            'distribution' => $distribution,
        ];
    }

    private function buildMonthlyGrowth(int $planId): array
    {
        $series = [];
        $now = Carbon::now();

        for ($i = 11; $i >= 0; $i--) {
            $dt = $now->copy()->subMonths($i);
            $monthStart = $dt->copy()->startOfMonth();
            $monthEnd = $dt->copy()->endOfMonth();

            $cnt = Subscription::where('plan_id', $planId)
                ->where('created_at', '<=', $monthEnd)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_EXPIRED])
                ->count();

            $series[] = [
                'month' => $dt->format('Y-m'),
                'label_ar' => $dt->translatedFormat('F Y'),
                'label_en' => $dt->format('M Y'),
                'subscribers_count' => $cnt,
            ];
        }

        return $series;
    }

    private function resolveBadgeVariant(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'أساس') || str_contains($n, 'basic')) return 'basic';
        if (str_contains($n, 'ماس') || str_contains($n, 'diamond')) return 'diamond';
        if (str_contains($n, 'فض') || str_contains($n, 'silver')) return 'silver';
        if (str_contains($n, 'ذهب') || str_contains($n, 'gold')) return 'gold';
        if (str_contains($n, 'تجريب') || str_contains($n, 'trial')) return 'trial';
        return 'basic';
    }

    private function resolveBadgeColor(string $variant): string
    {
        return match ($variant) {
            'basic' => '#10b981',
            'diamond' => '#8b5cf6',
            'silver' => '#94a3b8',
            'gold' => '#f59e0b',
            'trial' => '#3b82f6',
            default => '#10b981',
        };
    }
}
