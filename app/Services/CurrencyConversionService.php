<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SupportedCurrency;
use Illuminate\Support\Facades\Cache;

final class CurrencyConversionService
{
    /** @var array<string, ?SupportedCurrency> */
    private static array $currencyCache = [];

    /**
     * Flush in-memory static cache.
     */
    public static function flushStaticCache(): void
    {
        self::$currencyCache = [];
    }

    /**
     * Convert an EGP base amount to a target currency using the live system rate.
     */
    public function convertFromEgp(float $amountEgp, string $targetCurrencyCode): float
    {
        $targetCurrencyCode = strtoupper($targetCurrencyCode);
        
        if ($targetCurrencyCode === 'EGP') {
            return round($amountEgp, 2);
        }

        $currency = $this->getCurrency($targetCurrencyCode);
        
        if (!$currency || $currency->active_exchange_rate <= 0) {
            // Fallback: 1 to 1 if no valid rate found to avoid division by zero
            return round($amountEgp, 2);
        }

        // Active exchange rate logic matching existing PricingService
        return round($amountEgp / $currency->active_exchange_rate, 2);
    }

    /**
     * Convert a localized amount to EGP using the live system rate.
     */
    public function convertToEgp(float $localAmount, string $sourceCurrencyCode): float
    {
        $sourceCurrencyCode = strtoupper($sourceCurrencyCode);

        if ($sourceCurrencyCode === 'EGP') {
            return round($localAmount, 2);
        }

        $currency = $this->getCurrency($sourceCurrencyCode);

        if (!$currency || $currency->active_exchange_rate <= 0) {
            return round($localAmount, 2);
        }

        return round($localAmount * $currency->active_exchange_rate, 2);
    }

    /**
     * Get currency model, cached for performance.
     */
    public function getCurrency(string $currencyCode): ?SupportedCurrency
    {
        $currencyCode = strtoupper($currencyCode);

        if (array_key_exists($currencyCode, self::$currencyCache)) {
            return self::$currencyCache[$currencyCode];
        }

        $currency = Cache::remember("currency_{$currencyCode}", now()->addMinutes(30), function () use ($currencyCode) {
            return SupportedCurrency::where('currency_code', $currencyCode)->where('is_active', true)->first();
        });

        self::$currencyCache[$currencyCode] = $currency;

        return $currency;
    }
    
    /**
     * Get the active exchange rate for a given currency to EGP.
     */
    public function getExchangeRateToEgp(string $currencyCode): float
    {
        $currencyCode = strtoupper($currencyCode);
        
        if ($currencyCode === 'EGP') {
            return 1.0;
        }
        
        $currency = $this->getCurrency($currencyCode);
        if (!$currency) {
            return 1.0;
        }
        
        return $currency->active_exchange_rate;
    }
}
