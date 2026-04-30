<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\SupportedCurrency;
use Illuminate\Http\Request;

final class PricingService
{
    public function __construct(
        private readonly GeoLocationService $geoLocationService
    ) {}

    /**
     * Get display price for a plan in the given country's currency.
     * Falls back to base EGP price if no country-specific price exists.
     *
     * @return array{price: float, currency_code: string, currency_symbol: string}
     */
    public function getPriceForCountry(SubscriptionPlan $plan, string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        $planPrice = SubscriptionPlanPrice::query()
            ->join('countries', 'subscription_plan_prices.country_id', '=', 'countries.id')
            ->where('subscription_plan_prices.plan_id', $plan->id)
            ->where('countries.iso_code', $countryCode)
            ->select('subscription_plan_prices.*', 'countries.currency_code')
            ->first();

        if ($planPrice !== null) {
            $currencyCode = $planPrice->currency_code ?? 'EGP';
            return [
                'price' => (float) $planPrice->price,
                'currency_code' => $currencyCode,
                'currency_symbol' => $this->getCurrencySymbol($currencyCode),
            ];
        }

        // --- NEW Fallback: Auto-conversion using exchange rates ---
        if ($countryCode !== 'EG' && $countryCode !== '') {
            $currency = SupportedCurrency::where('country_code', $countryCode)
                ->where('is_active', true)
                ->first();

            if ($currency) {
                $exchangeRate = (float) ($currency->exchange_rate_to_egp ?? 1.0);
                if ($exchangeRate > 0) {
                    $basePriceEgp = (float) ($plan->price ?? 0);
                    $priceLocal = $basePriceEgp / $exchangeRate;

                    return [
                        'price' => round($priceLocal, 2),
                        'currency_code' => $currency->currency_code,
                        'currency_symbol' => $currency->currency_symbol,
                    ];
                }
            }
        }

        // Final fallback: Base price in EGP
        return [
            'price' => (float) $plan->price,
            'currency_code' => 'EGP',
            'currency_symbol' => CachingService::getSystemSettings('currency_symbol') ?: 'EGP',
        ];
    }

    /**
     * Detect user's country from request (IP geolocation or user profile).
     */
    public function detectUserCountry(Request $request): string
    {
        $countryCode = $this->geoLocationService->getCountryCodeFromRequest($request);

        return $countryCode ?? '';
    }

    /**
     * Convert amount from given currency to EGP using SupportedCurrency exchange rate.
     */
    public function convertToEgp(float $amount, string $currencyCode): float
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === 'EGP') {
            return $amount;
        }

        $currency = SupportedCurrency::where('currency_code', $currencyCode)->first();

        if ($currency === null || (float) $currency->exchange_rate_to_egp <= 0) {
            return $amount;
        }

        return round($amount * (float) $currency->exchange_rate_to_egp, 2);
    }

    /**
     * Convert amount from EGP to given currency code.
     */
    public function convertFromEgp(float $amount, string $currencyCode): float
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === 'EGP') {
            return $amount;
        }

        $currency = SupportedCurrency::where('currency_code', $currencyCode)->first();

        if ($currency === null || (float) $currency->exchange_rate_to_egp <= 0) {
            return $amount;
        }

        return round($amount / (float) $currency->exchange_rate_to_egp, 2);
    }

    /**
     * Get currency details for a given country code.
     */
    public function getCurrencyForCountry(string $countryCode): ?SupportedCurrency
    {
        return SupportedCurrency::where('country_code', strtoupper($countryCode))
            ->where('is_active', true)
            ->first();
    }

    private function getCurrencySymbol(string $currencyCode): string
    {
        $currency = SupportedCurrency::where('currency_code', strtoupper($currencyCode))->first();

        return $currency?->currency_symbol ?? $currencyCode;
    }
}
