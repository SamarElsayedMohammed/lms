<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanPrice extends Model
{
    protected $fillable = [
        'plan_id',
        'country_code',
        'currency_code',
        'price',
        'old_price',
        'is_active',
        'can_subscribe',
    ];

    protected $casts = [
        'price' => 'float',
        'old_price' => 'float',
        'is_active' => 'boolean',
        'can_subscribe' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class , 'plan_id');
    }

    // No country relationship since we use country_code directly
}