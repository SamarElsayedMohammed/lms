<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Commission;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;

/**
 * Centralized service for earnings, revenue, and commission data.
 * Uses Commission table as the single source of truth.
 */
final readonly class EarningsService
{
    /**
     * Get financial statistics for a given period.
     *
     * @return array{revenue: float, commission: float, earnings: float, sales_count: int}
     */
    public function getStats(
        null|int $instructorId = null,
        null|int $courseId = null,
        null|Carbon $startDate = null,
        null|Carbon $endDate = null,
    ): array {
        $query = Commission::query();

        if ($instructorId !== null) {
            $query->where('instructor_id', $instructorId);
        }

        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $stats = $query->selectRaw('
            COALESCE(SUM(admin_commission_amount), 0) as admin,
            COALESCE(SUM(instructor_commission_amount), 0) as instructor,
            COUNT(*) as sales_count
        ')->first();

        $adminCommission = (float) ($stats->admin ?? 0);
        $instructorEarnings = (float) ($stats->instructor ?? 0);

        return [
            'revenue' => $adminCommission + $instructorEarnings, // What user actually paid
            'commission' => $adminCommission,
            'earnings' => $instructorEarnings,
            'sales_count' => (int) ($stats->sales_count ?? 0),
        ];
    }

    /**
     * Get monthly data for a year (12 months).
     *
     * @return array<int, array{month: string, month_number: int, revenue: float, commission: float, earnings: float, sales_count: int}>
     */
    public function getMonthlyData(int $year, null|int $instructorId = null, null|int $courseId = null): array
    {
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $months = [];

        for ($i = 1; $i <= 12; $i++) {
            $startOfMonth = Carbon::createFromDate($year, $i, 1)->startOfDay();
            $endOfMonth = Carbon::createFromDate($year, $i, 1)->endOfMonth()->endOfDay();

            $stats = $this->getStats($instructorId, $courseId, $startOfMonth, $endOfMonth);

            $months[] = [
                'month' => $monthNames[$i - 1],
                'month_number' => $i,
                ...$stats,
            ];
        }

        return $months;
    }

    /**
     * Get daily data for a specific month.
     *
     * @return array<int, array{day: int, revenue: float, commission: float, earnings: float, sales_count: int}>
     */
    public function getDailyDataForMonth(
        int $year,
        int $month,
        null|int $instructorId = null,
        null|int $courseId = null,
    ): array {
        $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $dailyData = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $startOfDay = Carbon::createFromDate($year, $month, $day)->startOfDay();
            $endOfDay = Carbon::createFromDate($year, $month, $day)->endOfDay();

            $stats = $this->getStats($instructorId, $courseId, $startOfDay, $endOfDay);

            $dailyData[] = [
                'day' => $day,
                ...$stats,
            ];
        }

        return $dailyData;
    }

    /**
     * Get daily data for a specific week (7 days).
     *
     * @return array<int, array{day: int, day_name: string, revenue: float, commission: float, earnings: float, sales_count: int}>
     */
    public function getDailyDataForWeek(
        int $year,
        int $week,
        null|int $instructorId = null,
        null|int $courseId = null,
    ): array {
        $startOfWeek = Carbon::now()->setISODate($year, $week)->startOfWeek();
        $weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $weeklyData = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = $startOfWeek->copy()->addDays($i);
            $startOfDay = $currentDay->copy()->startOfDay();
            $endOfDay = $currentDay->copy()->endOfDay();

            $stats = $this->getStats($instructorId, $courseId, $startOfDay, $endOfDay);

            $weeklyData[] = [
                'day' => $i + 1,
                'day_name' => $weekDays[$i],
                ...$stats,
            ];
        }

        return $weeklyData;
    }

    /**
     * Get total earnings for an instructor (all time).
     */
    public function getInstructorTotalEarnings(int $instructorId): float
    {
        return (float) Commission::where('instructor_id', $instructorId)->sum('instructor_commission_amount');
    }

    /**
     * Get total withdrawn amount for an instructor.
     */
    public function getInstructorTotalWithdrawn(int $instructorId): float
    {
        return (float) WithdrawalRequest::where('user_id', $instructorId)->where('status', 'approved')->sum('amount');
    }

    /**
     * Get available balance for withdrawal.
     */
    public function getInstructorAvailableBalance(int $instructorId): float
    {
        $totalEarnings = $this->getInstructorTotalEarnings($instructorId);
        $totalWithdrawn = $this->getInstructorTotalWithdrawn($instructorId);

        return max(0, $totalEarnings - $totalWithdrawn);
    }
}
