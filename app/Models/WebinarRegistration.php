<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebinarRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'webinar_id',
        'payment_status',
        'paid_amount',
        'expires_at',
        'attended_at',
        'attended',
        'form_responses',
        'utm_source',
        'wallet_transaction_id',
        'amount_egp',
        'currency_code',
        'exchange_rate_snapshot',
        'wallet_amount_egp',
        'gateway_amount',
        'gateway_order_id',
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
        'amount_egp' => 'decimal:2',
        'exchange_rate_snapshot' => 'decimal:4',
        'wallet_amount_egp' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'attended_at' => 'datetime',
        'attended' => 'boolean',
        'form_responses' => 'array',
    ];

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isFree(): bool
    {
        return $this->payment_status === 'free';
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->isPending() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConfirmed(): bool
    {
        return $this->isPaid() || $this->isFree();
    }

    public function scopeConsumesCapacity($query)
    {
        return $query->where(function ($capacityQuery) {
            $capacityQuery->whereIn('payment_status', ['paid', 'free'])
                ->orWhere(function ($pendingQuery) {
                    $pendingQuery->where('payment_status', 'pending')
                        ->where(function ($expiryQuery) {
                            $expiryQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                });
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }
}
