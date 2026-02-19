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

        $planPrice = SubscriptionPlanPrice::where('plan_id', $plan->id)
            ->where('country_code', $countryCode)
            ->first();

        if ($planPrice !== null) {
            return [
                'price' => (float) $planPrice->price,
                'currency_code' => $planPrice->currency_code,
                'currency_symbol' => $this->getCurrencySymbol($planPrice->currency_code),
            ];
        }

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

    private function getCurrencySymbol(string $currencyCode): string
    {
        $currency = SupportedCurrency::where('currency_code', strtoupper($currencyCode))->first();

        return $currency?->currency_symbol ?? $currencyCode;
    }
}
