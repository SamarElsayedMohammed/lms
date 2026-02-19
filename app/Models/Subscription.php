<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $status
 * @property bool $auto_renew
 * @property bool $notified_7_days
 * @property bool $notified_3_days
 * @property bool $notified_1_day
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read SubscriptionPlan $plan
 * @property-read bool $is_active
 * @property-read int|null $days_remaining
 */
final class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'plan_id',
        'starts_at',
        'ends_at',
        'status',
        'auto_renew',
        'cancellation_reason',
        'cancelled_at',
        'notified_7_days',
        'notified_3_days',
        'notified_1_day',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
        'notified_7_days' => 'boolean',
        'notified_3_days' => 'boolean',
        'notified_1_day' => 'boolean',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PENDING = 'pending';

    /**
     * Get the user that owns the subscription
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription plan
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * Get all payments for this subscription
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Scope: Active subscriptions
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    /**
     * Scope: Expired subscriptions
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->orWhere(function ($q) {
                $q->whereNotNull('ends_at')
                    ->where('ends_at', '<=', now());
            });
    }

    /**
     * Scope: Subscriptions expiring in X days
     */
    public function scopeExpiringIn(Builder $query, int $days): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDays($days))
            ->where('ends_at', '>', now());
    }

    /**
     * Scope: Subscriptions for a specific user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Accessor: Check if subscription is currently active
     */
    protected function isActive(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status !== self::STATUS_ACTIVE) {
                return false;
            }
            // Lifetime subscription (no end date)
            if ($this->ends_at === null) {
                return true;
            }
            return $this->ends_at->isFuture();
        });
    }

    /**
     * Accessor: Days remaining until expiry
     */
    protected function daysRemaining(): Attribute
    {
        return Attribute::get(function () {
            if ($this->ends_at === null) {
                return null; // Lifetime
            }
            if ($this->ends_at->isPast()) {
                return 0;
            }
            return (int) now()->diffInDays($this->ends_at);
        });
    }

    /**
     * Cancel the subscription
     */
    public function cancel(?string $reason = null): bool
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = $reason;
        $this->cancelled_at = now();
        $this->auto_renew = false;
        
        return $this->save();
    }

    /**
     * Extend subscription by days
     */
    public function extend(int $days): bool
    {
        if ($this->ends_at === null) {
            return true; // Lifetime, no need to extend
        }

        $baseDate = $this->ends_at->isPast() ? now() : $this->ends_at;
        $this->ends_at = $baseDate->copy()->addDays($days);
        $this->status = self::STATUS_ACTIVE;

        return $this->save();
    }

    /**
     * Check if subscription is lifetime
     */
    public function isLifetime(): bool
    {
        return $this->ends_at === null;
    }

    /**
     * Notification thresholds in days (7 days, 3 days, 1 day before expiry)
     */
    public const NOTIFICATION_THRESHOLDS = [7, 3, 1];

    /**
     * Check if user should be notified about expiry
     * Returns the threshold (7, 3, or 1) if notification is due, null otherwise
     */
    public function getExpiryNotificationThreshold(): ?int
    {
        if ($this->ends_at === null) {
            return null; // Lifetime — no notification needed
        }

        $daysRemaining = $this->days_remaining;
        if ($daysRemaining === null || $daysRemaining <= 0) {
            return null;
        }

        foreach (self::NOTIFICATION_THRESHOLDS as $threshold) {
            if ($daysRemaining <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Check if user should be notified about expiry (boolean shorthand)
     */
    public function shouldNotifyExpiry(): bool
    {
        return $this->getExpiryNotificationThreshold() !== null;
    }
}
