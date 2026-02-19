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
    ];

    protected $casts = [
        'price' => 'float',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
