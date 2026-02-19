<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateWithdrawal extends Model
{
    protected $fillable = [
        'affiliate_id',
        'amount',
        'commission_ids',
        'status',
        'requested_at',
        'processed_at',
        'processed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'commission_ids' => 'array',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
