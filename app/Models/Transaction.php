<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function ($transaction) {
            if (empty($transaction->transaction_id)) {
                $transaction->transaction_id = 'TRX-' . strtoupper(uniqid());
            }
        });
    }

    protected $fillable = [
        'user_id',
        'order_id',
        'transaction_id',
        'amount',
        'payment_method',
        'status',
        'message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function refundRequests()
    {
        return $this->hasMany(RefundRequest::class);
    }
}
