<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RefundRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'transaction_id',
        'refund_amount',
        'status',
        'reason',
        'user_media',
        'admin_notes',
        'admin_receipt',
        'purchase_date',
        'request_date',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'purchase_date' => 'datetime',
        'request_date' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(\App\Models\Course\Course::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function processedByUser()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function walletHistories()
    {
        return $this->morphMany(WalletHistory::class, 'reference');
    }
}
