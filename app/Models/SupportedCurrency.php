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
        'manual_exchange_rate_to_egp',
        'use_manual_rate',
        'is_active',
        'last_updated_at',
    ];

    protected $casts = [
        'is_active'                    => 'boolean',
        'use_manual_rate'              => 'boolean',
        'exchange_rate_to_egp'         => 'float',
        'manual_exchange_rate_to_egp'  => 'float',
        'last_updated_at'              => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * الدالة الذكية: ترجع السعر الصحيح بناءً على حالة التوجل.
     * لو use_manual_rate = true والسعر اليدوي موجود، هترجع السعر اليدوي.
     * لو لأ، هترجع السعر البنكي العادي.
     */
    public function getActiveExchangeRateAttribute(): float
    {
        if ($this->use_manual_rate && $this->manual_exchange_rate_to_egp > 0) {
            return (float) $this->manual_exchange_rate_to_egp;
        }

        return (float) ($this->exchange_rate_to_egp ?? 1.0);
    }
}
