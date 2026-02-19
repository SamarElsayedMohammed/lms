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
 * @property string|null $transaction_id
 * @property array|null $gateway_response
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Subscription $subscription
 * @property-read User $user
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
        'transaction_id',
        'gateway_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'wallet_amount' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Get the subscription
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Scope: Payments for a specific user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Mark payment as completed
     */
    public function markAsCompleted(?string $transactionId = null): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->paid_at = now();
        
        if ($transactionId) {
            $this->transaction_id = $transactionId;
        }

        return $this->save();
    }

    /**
     * Mark payment as failed
     */
    public function markAsFailed(?array $response = null): bool
    {
        $this->status = self::STATUS_FAILED;
        
        if ($response) {
            $this->gateway_response = $response;
        }

        return $this->save();
    }

    /**
     * Check if payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
