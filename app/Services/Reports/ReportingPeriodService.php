<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class ReportingPeriodService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function resolve(array $filters): ReportingPeriod
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $preset = $this->normalizePreset((string) ($filters['preset'] ?? $filters['date_range'] ?? $filters['period'] ?? '30d'));
        $now = Carbon::now($timezone);

        if ($preset === 'today') {
            $currentStart = $now->copy()->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDay()->startOfDay();
            $prevEnd = $now->copy()->subDay()->endOfDay();
        } elseif ($preset === '7d') {
            $currentStart = $now->copy()->subDays(6)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(7);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === '90d') {
            $currentStart = $now->copy()->subDays(89)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(90);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === '12m') {
            $currentStart = $now->copy()->subMonths(11)->startOfMonth();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subMonths(12);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === 'this_month') {
            $currentStart = $now->copy()->startOfMonth();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $prevEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();
        } elseif ($preset === 'last_month') {
            $currentStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();
            $prevStart = $now->copy()->subMonthsNoOverflow(2)->startOfMonth();
            $prevEnd = $now->copy()->subMonthsNoOverflow(2)->endOfMonth();
        } elseif ($preset === 'last_year') {
            $currentStart = $now->copy()->subYear()->startOfYear();
            $currentEnd = $now->copy()->subYear()->endOfYear();
            $prevStart = $currentStart->copy()->subYear();
            $prevEnd = $currentEnd->copy()->subYear();
        } elseif ($preset === 'this_year') {
            $currentStart = $now->copy()->startOfYear();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subYearNoOverflow();
            $prevEnd = $currentEnd->copy()->subYearNoOverflow();
        } elseif ($preset === 'all' || $preset === 'all_time') {
            $currentStart = Carbon::create(2000, 1, 1, 0, 0, 0, $timezone);
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = Carbon::create(2000, 1, 1, 0, 0, 0, $timezone);
            $prevEnd = Carbon::create(2000, 1, 1, 0, 0, 0, $timezone);
        } elseif ($preset === 'custom' && !empty($filters['date_from']) && !empty($filters['date_to'])) {
            $currentStart = Carbon::parse((string) $filters['date_from'], $timezone)->startOfDay();
            $currentEnd = Carbon::parse((string) $filters['date_to'], $timezone)->endOfDay();
            $days = $currentStart->copy()->startOfDay()->diffInDays($currentEnd->copy()->startOfDay()) + 1;
            $prevStart = $currentStart->copy()->subDays($days)->startOfDay();
            $prevEnd = $currentStart->copy()->subSecond();
        } else {
            $currentStart = $now->copy()->subDays(29)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(30);
            $prevEnd = $currentStart->copy()->subSecond();
            $preset = '30d';
        }

        return new ReportingPeriod(
            $currentStart,
            $currentEnd,
            $prevStart,
            $prevEnd,
            $preset,
            $timezone,
        );
    }

    public function applyToRequest(Request $request): ReportingPeriod
    {
        $filters = $request->all();
        if (!$request->filled('preset') && !$request->filled('date_from') && !$request->filled('from_date')) {
            $filters['preset'] = '30d';
        }

        if (!$request->filled('preset') && ($request->filled('date_from') || $request->filled('from_date'))) {
            $filters['preset'] = 'custom';
            $filters['date_from'] = $request->date_from ?? $request->from_date;
            $filters['date_to'] = $request->date_to ?? $request->to_date ?? Carbon::now((string) config('app.timezone', 'UTC'))->toDateString();
        }

        $period = $this->resolve($filters);
        $request->merge([
            'date_from' => $period->start->toDateString(),
            'date_to' => $period->end->toDateString(),
            'from_date' => $period->start->toDateString(),
            'to_date' => $period->end->toDateString(),
        ]);

        return $period;
    }

    public function normalizePreset(string $preset): string
    {
        $aliases = [
            'last_7_days' => '7d',
            '7_days' => '7d',
            'last_30_days' => '30d',
            '30_days' => '30d',
            'last_90_days' => '90d',
            '90_days' => '90d',
        ];

        return $aliases[$preset] ?? $preset;
    }
}
