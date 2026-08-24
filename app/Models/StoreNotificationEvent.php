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
 * @property string $store
 * @property string $environment
 * @property string $external_event_id
 * @property string $event_type
 * @property string|null $event_subtype
 * @property string|null $store_product_id
 * @property string|null $transaction_id
 * @property string|null $original_transaction_id
 * @property string|null $purchase_token_hash
 * @property int|null $user_id
 * @property int|null $subscription_id
 * @property int|null $store_transaction_id
 * @property Carbon|null $event_timestamp
 * @property Carbon $received_at
 * @property string $processing_status
 * @property int $attempt_count
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $processed_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property array|null $raw_payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Subscription|null $subscription
 * @property-read StoreTransaction|null $storeTransaction
 */
final class StoreNotificationEvent extends Model
{
    use HasFactory;

    protected $table = 'store_notification_events';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    public const STORE_APPLE = 'app_store';
    public const STORE_GOOGLE = 'google_play';

    protected $fillable = [
        'store',
        'environment',
        'external_event_id',
        'event_type',
        'event_subtype',
        'store_product_id',
        'transaction_id',
        'original_transaction_id',
        'purchase_token_hash',
        'user_id',
        'subscription_id',
        'store_transaction_id',
        'event_timestamp',
        'received_at',
        'processing_status',
        'attempt_count',
        'last_attempt_at',
        'processed_at',
        'last_error_code',
        'last_error_message',
        'raw_payload',
    ];

    protected $casts = [
        'event_timestamp' => 'datetime',
        'received_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempt_count' => 'integer',
        'raw_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function storeTransaction(): BelongsTo
    {
        return $this->belongsTo(StoreTransaction::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_PENDING);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_FAILED);
    }

    public function scopeStore(Builder $query, string $store): Builder
    {
        return $query->where('store', $store);
    }

    public function markProcessing(): void
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSING,
            'attempt_count' => $this->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);
    }

    public function markProcessed(?int $userId = null, ?int $subscriptionId = null, ?int $storeTransactionId = null): void
    {
        $data = [
            'processing_status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ];

        if ($userId !== null) {
            $data['user_id'] = $userId;
        }
        if ($subscriptionId !== null) {
            $data['subscription_id'] = $subscriptionId;
        }
        if ($storeTransactionId !== null) {
            $data['store_transaction_id'] = $storeTransactionId;
        }

        $this->update($data);
    }

    public function markIgnored(string $reason, ?int $userId = null): void
    {
        $data = [
            'processing_status' => self::STATUS_IGNORED,
            'processed_at' => now(),
            'last_error_code' => 'ignored',
            'last_error_message' => $reason,
        ];

        if ($userId !== null) {
            $data['user_id'] = $userId;
        }

        $this->update($data);
    }

    public function markFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ]);
    }
}
