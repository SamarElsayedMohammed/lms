<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $subscription_id
 * @property int|null $subscription_payment_id
 * @property int $plan_id
 * @property string $store
 * @property string $environment
 * @property string $store_product_id
 * @property string $transaction_id
 * @property string $original_transaction_id
 * @property string|null $purchase_token
 * @property string|null $purchase_token_hash
 * @property string $status
 * @property Carbon $purchased_at
 * @property Carbon|null $expires_at
 * @property bool $auto_renew
 * @property bool $is_verified
 * @property bool $is_revoked
 * @property bool $is_refunded
 * @property float|null $amount
 * @property string|null $currency
 * @property array|null $raw_payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Subscription|null $subscription
 * @property-read SubscriptionPayment|null $subscriptionPayment
 * @property-read SubscriptionPlan $plan
 */
final class StoreTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'store_transactions';

    protected $fillable = [
        'user_id',
        'subscription_id',
        'subscription_payment_id',
        'plan_id',
        'store',
        'environment',
        'store_product_id',
        'transaction_id',
        'original_transaction_id',
        'purchase_token',
        'purchase_token_hash',
        'status',
        'purchased_at',
        'expires_at',
        'auto_renew',
        'is_verified',
        'is_revoked',
        'is_refunded',
        'amount',
        'currency',
        'raw_payload',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'is_verified' => 'boolean',
        'is_revoked' => 'boolean',
        'is_refunded' => 'boolean',
        'amount' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public const STORE_APPLE = 'app_store';
    public const STORE_GOOGLE = 'google_play';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_IN_GRACE_PERIOD = 'in_grace_period';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_REFUNDED = 'refunded';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * Compute SHA-256 hash for purchase tokens to allow fast indexed lookups and uniqueness guarantees
     */
    public static function hashToken(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }
        return hash('sha256', trim($token));
    }
}
