<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WalletTopUpAttempt extends Model
{
    protected $fillable = [
        'user_id', 'order_id', 'provider_transaction_id', 'amount_egp',
        'status', 'expires_at', 'gateway_response',
    ];

    protected $casts = [
        'amount_egp' => 'decimal:2',
        'expires_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
