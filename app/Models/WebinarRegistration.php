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
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'attended_at' => 'datetime',
        'attended' => 'boolean',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }
}
