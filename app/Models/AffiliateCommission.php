<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    protected $fillable = [
        'affiliate_id',
        'referred_user_id',
        'subscription_id',
        'plan_id',
        'amount',
        'commission_rate',
        'status',
        'earned_date',
        'available_date',
        'settlement_period_start',
        'settlement_period_end',
        'withdrawn_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'commission_rate' => 'float',
        'earned_date' => 'date',
        'available_date' => 'date',
        'settlement_period_start' => 'date',
        'settlement_period_end' => 'date',
        'withdrawn_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeForAffiliate($query, int $affiliateId)
    {
        return $query->where('affiliate_id', $affiliateId);
    }
}
