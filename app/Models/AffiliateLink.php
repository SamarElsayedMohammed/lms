<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateLink extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'total_clicks',
        'total_conversions',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'total_clicks' => 'integer',
        'total_conversions' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
