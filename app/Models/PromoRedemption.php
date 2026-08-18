<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $promo_code_id
 * @property string $promo_code
 * @property int $user_id
 * @property int|null $order_id
 * @property int|null $subscription_id
 * @property int|null $subscription_payment_id
 * @property string $status
 * @property string $currency
 * @property float $original_amount
 * @property float $discount_amount
 * @property float $final_amount
 * @property string|null $discount_type_snapshot
 * @property float|null $discount_value_snapshot
 * @property Carbon|null $reserved_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PromoCode|null $promoCode
 * @property-read User $user
 * @property-read Order|null $order
 * @property-read Subscription|null $subscription
 * @property-read SubscriptionPayment|null $subscriptionPayment
 */
final class PromoRedemption extends Model
{
    use HasFactory;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'promo_code_id',
        'promo_code',
        'user_id',
        'order_id',
        'subscription_id',
        'subscription_payment_id',
        'status',
        'currency',
        'original_amount',
        'discount_amount',
        'final_amount',
        'discount_type_snapshot',
        'discount_value_snapshot',
        'reserved_at',
        'consumed_at',
        'released_at',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'discount_value_snapshot' => 'decimal:2',
        'reserved_at' => 'datetime',
        'consumed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function scopeReserved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function scopeConsumed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONSUMED);
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RELEASED);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function markAsConsumed(): bool
    {
        $this->status = self::STATUS_CONSUMED;
        $this->consumed_at = now();
        return $this->save();
    }

    public function markAsReleased(): bool
    {
        $this->status = self::STATUS_RELEASED;
        $this->released_at = now();
        return $this->save();
    }

    public function markAsExpired(): bool
    {
        $this->status = self::STATUS_EXPIRED;
        $this->released_at = now();
        return $this->save();
    }
}
