<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class SubscriptionLifecycleQuery
{
    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function startedInPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('starts_at', [$start, $end]);
    }

    /**
     * Coverage overlap: a row is included if it could have been active at any instant in the period.
     * `ends_at` is exclusive, matching Subscription::isActive.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function overlappingPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return self::excludePending($query)
            ->where(function (Builder $inner) use ($end): void {
                $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $end);
            })
            ->where(function (Builder $inner) use ($start): void {
                $inner->whereNull('ends_at')->orWhere('ends_at', '>', $start);
            })
            ->where(function (Builder $inner) use ($start): void {
                $inner->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $start);
            });
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function activeAt(Builder $query, Carbon $asOf): Builder
    {
        return $query->where('status', Subscription::STATUS_ACTIVE)
            ->where(function (Builder $inner) use ($asOf): void {
                $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $asOf);
            })
            ->where(function (Builder $inner) use ($asOf): void {
                $inner->whereNull('ends_at')->orWhere('ends_at', '>', $asOf);
            })
            ->where(function (Builder $inner) use ($asOf): void {
                $inner->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $asOf);
            });
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function expiredEvents(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('ends_at', [$start, $end])
            ->where(function (Builder $inner): void {
                $inner->whereNull('cancelled_at')
                    ->orWhereColumn('cancelled_at', '>', 'ends_at');
            });
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function cancelledEvents(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('cancelled_at', [$start, $end]);
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public static function excludePending(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            Subscription::STATUS_PENDING,
            Subscription::STATUS_PENDING_APPROVAL,
        ]);
    }
}
