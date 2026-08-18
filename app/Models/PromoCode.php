<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'promo_code',
        'message',
        'start_date',
        'end_date',
        'no_of_users',
        'discount',
        'discount_type',
        'repeat_usage',
        'no_of_repeat_usage',
        'status',
        'applies_to_all_courses',
    ];

    protected $casts = [
        'applies_to_all_courses' => 'boolean',
        'repeat_usage' => 'boolean',
        'status' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function courses()
    {
        return $this->belongsToMany(Course\Course::class, 'promo_code_course');
    }

    public function subscriptionPlans()
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'promo_code_subscription_plan');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'promo_code_id');
    }

    public function redemptions()
    {
        return $this->hasMany(PromoRedemption::class, 'promo_code_id');
    }

    public function getUsedCountAttribute(): int
    {
        $code = strtoupper(trim((string) $this->promo_code));
        $redemptionCount = PromoRedemption::where(function ($q) use ($code) {
            $q->where('promo_code_id', $this->id)
              ->orWhere('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', PromoRedemption::STATUS_CONSUMED)->count();

        // Count unmigrated legacy completed orders (not having a promo_redemptions record)
        $unlinkedOrdersCount = Order::where(function ($q) use ($code) {
            $q->where('promo_code_id', $this->id)
              ->orWhere('promo_code', $code);
        })->where('status', 'completed')
          ->whereNotIn('id', function ($sub) {
              $sub->select('order_id')->from('promo_redemptions')->whereNotNull('order_id');
          })->count();

        // Count unmigrated legacy completed subscription payments (not having a promo_redemptions record)
        $unlinkedPaymentsCount = SubscriptionPayment::where(function ($q) use ($code) {
            $q->where('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', SubscriptionPayment::STATUS_COMPLETED)
          ->whereNotIn('id', function ($sub) {
              $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
          })->count();

        return $redemptionCount + $unlinkedOrdersCount + $unlinkedPaymentsCount;
    }

    public function getReservedCountAttribute(): int
    {
        $code = strtoupper(trim((string) $this->promo_code));
        $cutoff = now()->subHours(\App\Services\SubscriptionPromoService::RESERVATION_EXPIRY_HOURS);
        
        $redemptionCount = PromoRedemption::where(function ($q) use ($code) {
            $q->where('promo_code_id', $this->id)
              ->orWhere('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', PromoRedemption::STATUS_RESERVED)
          ->where('reserved_at', '>=', $cutoff)
          ->count();

        // Count unlinked legacy pending subscription payments
        $unlinkedPendingCount = SubscriptionPayment::where(function ($q) use ($code) {
            $q->where('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', SubscriptionPayment::STATUS_PENDING)
          ->where('created_at', '>=', $cutoff)
          ->whereNotIn('id', function ($sub) {
              $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
          })->count();

        return $redemptionCount + $unlinkedPendingCount;
    }

    public function getRemainingUsesAttribute(): ?int
    {
        if ($this->no_of_users === null) {
            return null;
        }
        $used = $this->used_count + $this->reserved_count;
        return max(0, (int) $this->no_of_users - $used);
    }
}

