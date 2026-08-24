<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $user_id
 * @property float $amount
 * @property float $wallet_amount
 * @property float $gateway_amount
 * @property string $status
 * @property string|null $payment_method
 * @property string|null $promo_code
 * @property float|null $original_amount
 * @property float $discount_amount
 * @property string|null $transaction_id
 * @property int|null $store_transaction_id
 * @property array|null $gateway_response
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Subscription $subscription
 * @property-read User $user
 * @property-read StoreTransaction|null $storeTransaction
 */
final class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'user_id',
        'amount',
        'wallet_amount',
        'gateway_amount',
        'status',
        'payment_method',
        'resolved_country',
        'currency_code',
        'price_source',
        'tax',
        'final_amount',
        'promo_code',
        'original_amount',
        'discount_amount',
        'transaction_id',
        'store_transaction_id',
        'gateway_response',
        'paid_at',
        'manual_deposit_method_id',
        'payment_method_id',
        'receipt',
        'admin_notes',
        'amount_egp',
        'wallet_amount_egp',
        'gateway_amount_egp',
        'exchange_rate_snapshot',
        'method_snapshot',
        'submitted_fields',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'wallet_amount' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'amount_egp' => 'decimal:2',
        'wallet_amount_egp' => 'decimal:2',
        'gateway_amount_egp' => 'decimal:2',
        'exchange_rate_snapshot' => 'decimal:4',
        'gateway_response' => 'array',
        'method_snapshot' => 'array',
        'submitted_fields' => 'array',
        'paid_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeTransaction(): BelongsTo
    {
        return $this->belongsTo(StoreTransaction::class, 'store_transaction_id');
    }

    /**
     * Scope: Payments for a specific user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Completed payments
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope: Pending payments
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Failed payments
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Check if payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if payment is failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if payment is refunded
     */
    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
}
