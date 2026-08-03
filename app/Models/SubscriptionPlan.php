<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CachingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $duration_days
 * @property string $billing_cycle
 * @property float $price
 * @property float|null $usd_price
 * @property string $commission_type
 * @property float $commission_rate
 * @property array|null $features
 * @property bool $is_active
 * @property int $sort_order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'duration_days',
        'billing_cycle',
        'price',
        'usd_price',
        'commission_type',
        'commission_rate',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'usd_price' => 'decimal:2',
        'commission_type' => 'string',
        'commission_rate' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
        'duration_days' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Billing cycle labels in Arabic
     */
    public const BILLING_CYCLES = [
        'monthly' => 'شهري',
        'quarterly' => 'ربع سنوي',
        'semi_annual' => 'نصف سنوي',
        'yearly' => 'سنوي',
        'custom' => 'مدة مخصصة',
    ];

    /**
     * Duration days for each billing cycle
     * 'custom' uses the duration_days field directly
     */
    public const CYCLE_DAYS = [
        'monthly' => 30,
        'quarterly' => 90,
        'semi_annual' => 180,
        'yearly' => 365,
        'custom' => null, // Uses duration_days field
    ];

    /**
     * Get all subscriptions for this plan
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class, 'promo_code_subscription_plan');
    }

    /**
     * Get country-specific prices for this plan
     */
    public function countryPrices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id');
    }

    /**
     * Get active subscriptions for this plan
     */
    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()->where('status', 'active');
    }

    /**
     * Scope: Only active plans
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Order by sort_order
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Accessor: Clean Name
     * Ensures the raw plan name is returned without billing cycle labels like '(مدة مخصصة)'
     */
    protected function name(): Attribute
    {
        return Attribute::get(function ($value) {
            if (!$value) {
                return $value;
            }
            
            $cleaned = $value;
            // Remove common duration suffixes and their parenthesized versions
            foreach (self::BILLING_CYCLES as $key => $label) {
                $cleaned = preg_replace('/\s*\(' . preg_quote($label, '/') . '\)\s*/u', '', $cleaned);
                // Only remove if it's at the end of the string to avoid stripping valid words in the middle
                $cleaned = preg_replace('/\s*' . preg_quote($label, '/') . '\s*$/u', '', $cleaned);
            }
            
            return trim($cleaned);
        });
    }

    /**
     * Accessor: Formatted price
     */
    protected function formattedPrice(): Attribute
    {
        return Attribute::get(fn() => number_format((float) $this->price, 2) . ' ' . (CachingService::getSystemSettings('currency_symbol') ?: 'EGP'));
    }

    /**
     * Accessor: Billing cycle label
     */
    protected function billingCycleLabel(): Attribute
    {
        return Attribute::get(fn() => self::BILLING_CYCLES[$this->billing_cycle] ?? $this->billing_cycle);
    }

    /**
     * Check if plan is lifetime
     */
    public function isLifetime(): bool
    {
        return false;
    }

    /**
     * Check if plan uses custom duration
     */
    public function isCustomDuration(): bool
    {
        return $this->billing_cycle === 'custom';
    }

    /**
     * Get duration days based on billing cycle
     * For 'custom' cycle, duration_days field is required
     */
    public function getDurationDays(): ?int
    {
        if ($this->billing_cycle === 'custom') {
            return $this->duration_days;
        }

        return $this->duration_days ?? self::CYCLE_DAYS[$this->billing_cycle] ?? null;
    }

    public function getLocalizedDurationLabelAttribute(): string
    {
        $days = $this->getDurationDays();
        if ($this->billing_cycle === 'yearly' || $days === 365) {
            return 'سنة واحدة';
        }
        if ($this->billing_cycle === 'semi_annual' || $days === 180) {
            return '6 أشهر';
        }
        if ($this->billing_cycle === 'quarterly' || $days === 90) {
            return '3 أشهر';
        }
        if ($this->billing_cycle === 'monthly' || $days === 30) {
            return 'شهر واحد';
        }
        if ($days) {
            if ($days % 30 === 0) {
                $months = (int) ($days / 30);
                if ($months === 1) return 'شهر واحد';
                if ($months === 2) return 'شهران';
                if ($months >= 3 && $months <= 10) return "{$months} أشهر";
                return "{$months} شهراً";
            }
            if ($days === 1) return 'يوم واحد';
            if ($days === 2) return 'يومان';
            if ($days >= 3 && $days <= 10) return "{$days} أيام";
            return "{$days} يوماً";
        }
        return self::BILLING_CYCLES[$this->billing_cycle] ?? 'حسب الباقة';
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['is_active'] = $array['is_active'] ?? false;
        $array['is_available'] = $array['is_available'] ?? false;
        $array['localized_duration'] = $this->getLocalizedDurationLabelAttribute();
        return $array;
    }
}
