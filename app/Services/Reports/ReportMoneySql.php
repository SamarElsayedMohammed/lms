<?php

namespace App\Services\Reports;

final class ReportMoneySql
{
    public static function orderRevenueEgpSql(string $alias = 'orders'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.final_price * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.final_price, 0)";
    }

    public static function orderGrossRevenueEgpSql(string $alias = 'orders'): string
    {
        return "COALESCE(NULLIF({$alias}.total_price, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), NULLIF({$alias}.amount_egp, 0), {$alias}.final_price * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.final_price, 0)";
    }

    public static function orderDiscountEgpSql(string $alias = 'orders'): string
    {
        return "COALESCE(NULLIF({$alias}.discount_amount, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), 0)";
    }

    public static function orderRevenueLocalSql(string $alias = 'orders'): string
    {
        return "COALESCE({$alias}.final_price, 0)";
    }

    public static function subscriptionRevenueEgpSql(string $alias = 'subscription_payments'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), NULLIF({$alias}.final_amount, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), NULLIF({$alias}.amount, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), 0)";
    }

    public static function subscriptionRevenueLocalSql(string $alias = 'subscription_payments'): string
    {
        return "COALESCE(NULLIF({$alias}.final_amount, 0), {$alias}.amount, 0)";
    }

    public static function refundAmountEgpSql(string $alias = 'refund_requests'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.refund_amount * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.refund_amount, 0)";
    }

    public static function refundAmountLocalSql(string $alias = 'refund_requests'): string
    {
        return "COALESCE({$alias}.refund_amount, 0)";
    }

    public static function commissionAdminEgpSql(string $commAlias = 'commissions', string $orderAlias = 'orders'): string
    {
        return "({$commAlias}.admin_commission_amount * COALESCE(NULLIF({$orderAlias}.exchange_rate_snapshot, 0), 1))";
    }

    public static function commissionInstructorEgpSql(string $commAlias = 'commissions', string $orderAlias = 'orders'): string
    {
        return "({$commAlias}.instructor_commission_amount * COALESCE(NULLIF({$orderAlias}.exchange_rate_snapshot, 0), 1))";
    }

    public static function commissionTotalEgpSql(string $commAlias = 'commissions', string $orderAlias = 'orders'): string
    {
        return "(({$commAlias}.admin_commission_amount + {$commAlias}.instructor_commission_amount) * COALESCE(NULLIF({$orderAlias}.exchange_rate_snapshot, 0), 1))";
    }

    public static function orderCourseRevenueEgpSql(string $alias = 'order_courses'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.price * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.price, 0)";
    }

    public static function withdrawalAmountEgpSql(string $alias = 'withdrawal_requests'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.amount, 0)";
    }

    public static function dateFormatSql(string $column, string $groupBy = 'day', ?string $driver = null): string
    {
        $driver = $driver ?? \Illuminate\Support\Facades\DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return match ($groupBy) {
                'year' => "strftime('%Y', {$column})",
                'month' => "strftime('%Y-%m', {$column})",
                'week' => "strftime('%Y-%W', {$column})",
                default => "strftime('%Y-%m-%d', {$column})",
            };
        }

        if ($driver === 'pgsql') {
            return match ($groupBy) {
                'year' => "TO_CHAR({$column}, 'YYYY')",
                'month' => "TO_CHAR({$column}, 'YYYY-MM')",
                'week' => "TO_CHAR({$column}, 'IYYY-IW')",
                default => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            };
        }

        return match ($groupBy) {
            'year' => "DATE_FORMAT({$column}, '%Y')",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            'week' => "DATE_FORMAT({$column}, '%Y-%u')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }
}
