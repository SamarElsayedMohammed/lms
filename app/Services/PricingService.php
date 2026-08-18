<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\SupportedCurrency;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function getPriceForCountry(SubscriptionPlan $plan, ?string $countryCode = null): array
    {
        $countryCode = strtoupper(trim((string) ($countryCode ?? 'EG')));
        if ($countryCode === '') {
            $countryCode = 'EG';
        }

        // 1. Check for specific country override
        if (!empty($countryCode)) {
            $country = Country::where('iso_code', $countryCode)->first();
            $planPrice = SubscriptionPlanPrice::where('plan_id', $plan->id)
                ->where(function ($q) use ($countryCode, $country) {
                    $q->where('country_code', $countryCode);
                    if ($country) {
                        $q->orWhere('country_id', $country->id);
                    }
                })
                ->orderBy('id', 'desc')
                ->first();

            if ($planPrice !== null) {
                $currencyCode = !empty($planPrice->currency_code)
                    ? $planPrice->currency_code
                    : ($country?->currency_code ?? 'EGP');

                $symbol = $this->getCurrencySymbol($currencyCode);

                if (!$planPrice->is_active) {
                    return [
                        'price' => $this->roundUpForDisplay((float) $planPrice->price),
                        'old_price' => $planPrice->old_price
                            ? $this->roundUpForDisplay((float) $planPrice->old_price)
                            : null,
                        'currency_code' => $currencyCode,
                        'currency_symbol' => $symbol,
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
                    'currency_symbol' => $symbol,
                    'price_source' => 'country_override',
                    'can_subscribe' => (bool) $planPrice->can_subscribe,
                ];
            }
        }

        // 2. USD fallback for non-Egypt countries when usd_price is configured
        if (!empty($countryCode) && $countryCode !== 'EG') {
            if ($plan->usd_price !== null && (float) $plan->usd_price > 0) {
                return [
                    'price' => $this->roundUpForDisplay((float) $plan->usd_price),
                    'old_price' => null,
                    'currency_code' => 'USD',
                    'currency_symbol' => '$',
                    'price_source' => 'default',
                    'can_subscribe' => true,
                ];
            }

            return [
                'price' => 0,
                'old_price' => null,
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'price_source' => 'default',
                'can_subscribe' => false,
            ];
        }

        // 3. Egypt / Global Fallback: base price in EGP
        return [
            'price' => $this->roundUpForDisplay((float) $plan->price),
            'old_price' => !empty($plan->old_price) ? $this->roundUpForDisplay((float) $plan->old_price) : null,
            'currency_code' => 'EGP',
            'currency_symbol' => 'ج.م',
            'price_source' => 'default',
            'can_subscribe' => true,
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
        $country = Country::where('iso_code', strtoupper($countryCode))->where('status', 1)->first();
        if ($country && $country->currency_code) {
            return SupportedCurrency::where('currency_code', $country->currency_code)
                ->where('is_active', true)
                ->first();
        }

        return SupportedCurrency::where('country_code', strtoupper($countryCode))
            ->where('is_active', true)
            ->first();
    }

    public function getCurrencySymbol(string $currencyCode): string
    {
        $currencyCode = strtoupper($currencyCode);
        if ($currencyCode === 'EGP') {
            return 'ج.م';
        }

        $currency = SupportedCurrency::where('currency_code', $currencyCode)->first();

        return $currency?->currency_symbol ?? $currencyCode;
    }

    private function roundUpForDisplay(float $price): int
    {
        return (int) ceil($price);
    }
}
