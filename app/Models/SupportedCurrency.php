<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportedCurrency extends Model
{
    protected $fillable = [
        'country_code',
        'country_name',
        'currency_code',
        'currency_symbol',
        'exchange_rate_to_egp',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'exchange_rate_to_egp' => 'float',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
