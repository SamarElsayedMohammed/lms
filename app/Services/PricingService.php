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
     * Get display and checkout price for a plan in the given country's currency.
     *
     * Resolution order:
     * 1. Country override row (subscription_plan_prices)
     * 2. Egypt with no override → plan base price in EGP
     * 3. Any other country with no override → plan usd_price in USD
     *
     * Prices are rounded up to the nearest whole number for frontend display.
     *
     * @return array{price: int, old_price: int|null, currency_code: string, currency_symbol: string, price_source: string, can_subscribe: boolean}
     */
    public function getPriceForCountry(SubscriptionPlan $plan, string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        // 1. Check for specific country override
        $planPrice = SubscriptionPlanPrice::where('plan_id', $plan->id)
            ->where('country_code', $countryCode)
            ->first();

        if ($planPrice !== null) {
            $currencyCode = $planPrice->currency_code ?? 'EGP';

            // If the country override is explicitly marked as inactive, disable subscriptions for this country
            if (!$planPrice->is_active) {
                return [
                    'price' => $this->roundUpForDisplay((float) $planPrice->price),
                    'old_price' => $planPrice->old_price
                        ? $this->roundUpForDisplay((float) $planPrice->old_price)
                        : null,
                    'currency_code' => $currencyCode,
                    'currency_symbol' => $this->getCurrencySymbol($currencyCode),
                    'price_source' => 'country_override',
                    'can_subscribe' => false,
                ];
            }

            return [
                'price' => $this->roundUpForDisplay((float) $planPrice->price),
                'old_price' => $planPrice->old_price
                    ? $this->roundUpForDisplay((float) $planPrice->old_price)
                    : null,
                'currency_code' => $currencyCode,
                'currency_symbol' => $this->getCurrencySymbol($currencyCode),
                'price_source' => 'country_override',
                'can_subscribe' => (bool) $planPrice->can_subscribe,
            ];
        }

        // 2. Egypt fallback: base EGP price stored on the plan
        if ($countryCode === 'EG') {
            return [
                'price' => $this->roundUpForDisplay((float) $plan->price),
                'old_price' => null,
                'currency_code' => 'EGP',
                'currency_symbol' => CachingService::getSystemSettings('currency_symbol') ?: 'EGP',
                'price_source' => 'default',
                'can_subscribe' => true,
            ];
        }

        // 3. Dynamic Currency Conversion Fallback
        // Convert the base EGP price to the country's active supported currency if available
        $supportedCurrency = SupportedCurrency::where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();

        if ($supportedCurrency !== null && $supportedCurrency->active_exchange_rate > 0) {
            $convertedPrice = $this->convertFromEgp((float) $plan->price, $supportedCurrency->currency_code);
            return [
                'price' => $this->roundUpForDisplay($convertedPrice),
                'old_price' => null,
                'currency_code' => $supportedCurrency->currency_code,
                'currency_symbol' => $supportedCurrency->currency_symbol,
                'price_source' => 'dynamic_conversion',
                'can_subscribe' => true,
            ];
        }

        // 4. International fallback: USD default price (not EGP base)
        $usdPrice = $plan->usd_price;

        return [
            'price' => $this->roundUpForDisplay((float) ($usdPrice ?? 0)),
            'old_price' => null,
            'currency_code' => 'USD',
            'currency_symbol' => $this->getCurrencySymbol('USD'),
            'price_source' => 'default',
            'can_subscribe' => $usdPrice !== null && (float) $usdPrice > 0,
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

        if ($currency === null || (float) $currency->active_exchange_rate <= 0) {
            return $amount;
        }

        return round($amount * (float) $currency->active_exchange_rate, 2);
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

        if ($currency === null || (float) $currency->active_exchange_rate <= 0) {
            return $amount;
        }

        return round($amount / (float) $currency->active_exchange_rate, 2);
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

    private function roundUpForDisplay(float $price): int
    {
        return (int) ceil($price);
    }
}
