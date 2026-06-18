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

    
    public function getActiveExchangeRateAttribute(): float
    {
        if ($this->use_manual_rate && $this->manual_exchange_rate_to_egp > 0) {
            return (float) $this->manual_exchange_rate_to_egp;
        }

        return (float) ($this->exchange_rate_to_egp ?? 1.0);
    }

    public static function ensureCurrencyExists(string $countryCode, ?string $currencyCode = null): void
    {
        $country = \App\Models\Country::where('iso_code', $countryCode)->first();
        if (!$country) {
            return;
        }

        $currencyCode = $currencyCode ?? $country->currency_code;
        if (!$currencyCode) {
            return;
        }

        $currencyCode = strtoupper($currencyCode);
        $countryCode = strtoupper(substr($countryCode, 0, 2));

        $exists = self::where('country_code', $countryCode)->exists();
        if (!$exists) {
            self::create([
                'country_code' => $countryCode,
                'country_name' => $country->name_en ?? $country->name_ar ?? $countryCode,
                'currency_code' => $currencyCode,
                'currency_symbol' => $currencyCode,
                'exchange_rate_to_egp' => 1,
                'use_manual_rate' => false,
                'is_active' => true,
            ]);

            try {
                \App\Jobs\UpdateExchangeRatesJob::dispatch();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to dispatch UpdateExchangeRatesJob: ' . $e->getMessage());
            }
        }
    }
}
