<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseCountryPrice;
use App\Models\SupportedCurrency;
use App\Models\PromoCode;
use App\Models\Tax;
use App\Models\Country;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PricingCalculationService
{
    public function __construct(
        private GeoLocationService $geoLocationService,
    ) {}

    /**
     * Resolve the display currency for the user.
     * Logic:
     * 1. Authenticated user's country
     * 2. IP detection
     * 3. Fallback to EG
     * 4. Find country and its currency
     *
     * @return array{code: string, symbol: string, exchange_rate: float, country_code: string}
     */
    public function resolveDisplayCurrency(?User $user, Request $request): array
    {
        $countryCode = null;

        if ($user && !empty($user->country_code)) {
            $countryCode = $user->country_code;
        }

        if (!$countryCode) {
            $countryCode = $this->geoLocationService->getCountryCodeFromRequest($request);
        }

        if (!$countryCode) {
            $countryCode = 'EG';
        }

        $countryCode = strtoupper($countryCode);

        $country = Country::where('iso_code', $countryCode)
            ->where('status', 1)
            ->first();

        $currencyCode = 'EGP';
        $currencySymbol = 'ج.م';
        $exchangeRate = 1.0;

        if ($country && $country->currency_code) {
            $supportedCurrency = SupportedCurrency::where('currency_code', $country->currency_code)
                ->where('is_active', true)
                ->first();

            if ($supportedCurrency) {
                $currencyCode = $supportedCurrency->currency_code;
                $currencySymbol = $supportedCurrency->currency_symbol;
                $exchangeRate = (float) ($supportedCurrency->active_exchange_rate ?? 1.0);
            } else {
                $currencyCode = $country->currency_code;
                $currencySymbol = $country->currency_code;
            }
        } else {
            $supportedCurrency = SupportedCurrency::where('country_code', $countryCode)
                ->where('is_active', true)
                ->first();

            if ($supportedCurrency) {
                $currencyCode = $supportedCurrency->currency_code;
                $currencySymbol = $supportedCurrency->currency_symbol;
                $exchangeRate = (float) ($supportedCurrency->active_exchange_rate ?? 1.0);
            }
        }

        return [
            'code' => $currencyCode,
            'symbol' => $currencySymbol,
            'exchange_rate' => $exchangeRate,
            'country_code' => $countryCode,
        ];
    }

    /**
     * Get localized pricing based on country code.
     * Logic:
     * 1. Check course_country_prices for a specific mapping.
     * 2. If not found, fall back to default course.price (EGP).
     * 3. Convert to local currency using exchange_rate_to_egp from supported_currencies.
     */
    public function getLocalizedPrice(Course $course, null|string $countryCode): array
    {
        return [
            'price_egp'           => 0,
            'discount_price_egp'  => null,
            'price_local'         => 0,
            'discount_price_local'=> null,
            'currency_code'       => 'EGP',
            'currency_symbol'     => 'ج.م',
            'exchange_rate'       => 1,
            'is_country_specific' => false,
        ];
    }

    /**
     * Calculate pricing for a single course with optional promo code and localized pricing.
     *
     * @return array{
     *     original_price: float,
     *     course_discount: float,
     *     subtotal: float,
     *     promo_discount: float,
     *     taxable_amount: float,
     *     tax_percentage: float,
     *     tax_amount: float,
     *     total: float,
     *     promo_code_details: array|null,
     *     currency_code: string,
     *     currency_symbol: string,
     *     price_egp: float,
     *     discount_price_egp: float|null,
     *     exchange_rate: float,
     *     is_country_specific: bool
     * }
     */
    public function calculateCoursePricing(
        Course $course,
        null|PromoCode $promoCode = null,
        null|float $taxPercentage = null,
        null|string $countryCode = null,
        ?User $user = null,
    ): array {
        return [
            'original_price' => 0,
            'course_discount' => 0,
            'subtotal' => 0,
            'promo_discount' => 0,
            'taxable_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'promo_code_details' => null,
            // Localization metadata
            'currency_code' => 'EGP',
            'display_currency' => 'EGP',
            'display_symbol' => 'ج.م',
            'display_price' => 0,
            'formatted_price' => '0 ج.م',
            'price_egp' => 0,
            'discount_price_egp' => null,
            'exchange_rate' => 1,
            'is_country_specific' => false,
        ];
    }

    /**
     * Calculate promo code discount for a given amount
     *
     * @return array{discount_amount: float, details: array|null}
     */
    public function calculatePromoDiscount(PromoCode $promoCode, float $subtotal, ?User $user = null): array
    {
        // Check if promo code is valid
        if (!$this->isPromoCodeValid($promoCode, $user)) {
            return ['discount_amount' => 0.0, 'details' => null];
        }

        // Cast promo code values to float
        $discount = (float) ($promoCode->discount ?? 0);
        $discountAmount = 0.0;

        if ($promoCode->discount_type === 'amount') {
            $discountAmount = min($discount, $subtotal);
        } elseif ($promoCode->discount_type === 'percentage') {
            // Clamp discount percentage to 100% max
            $discount = min($discount, 100);
            $discountAmount = ($subtotal * $discount) / 100;
        }

        // Ensure discount doesn't exceed subtotal
        $discountAmount = min($discountAmount, $subtotal);

        $details = [
            'id' => $promoCode->id,
            'code' => $promoCode->promo_code,
            'message' => $promoCode->message,
            'discount_type' => $promoCode->discount_type,
            'discount_value' => $discount,
            'discount_amount' => round($discountAmount, 2),
        ];

        return [
            'discount_amount' => $discountAmount,
            'details' => $details,
        ];
    }

    /**
     * Check if a promo code is currently valid
     */
    public function isPromoCodeValid(PromoCode $promoCode, ?User $user = null): bool
    {
        // Check status
        if ($promoCode->status != 1) {
            return false;
        }

        // Check date range
        if ($promoCode->start_date > today() || $promoCode->end_date < today()) {
            return false;
        }

        // Check global usage limit dynamically
        if ($promoCode->no_of_users !== null) {
            $globalUsageCount = Order::where('promo_code_id', $promoCode->id)
                ->whereIn('status', ['completed', 'pending'])
                ->count();
            if ($globalUsageCount >= $promoCode->no_of_users) {
                return false;
            }
        }

        // Check per-user usage limit dynamically
        if ($user !== null) {
            $userUsageCount = Order::where('promo_code_id', $promoCode->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'pending'])
                ->count();
                
            if ($promoCode->repeat_usage && $promoCode->no_of_repeat_usage !== null) {
                if ($userUsageCount >= $promoCode->no_of_repeat_usage) {
                    return false;
                }
            } elseif (!$promoCode->repeat_usage) {
                if ($userUsageCount >= 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get tax percentage based on request (uses IP geolocation)
     */
    public function getTaxPercentageFromRequest(Request $request): float
    {
        $countryCode = $this->geoLocationService->getCountryCodeFromRequest($request);

        return Tax::getTotalTaxPercentageByCountry($countryCode);
    }

    /**
     * Get country code from request
     */
    public function getCountryCodeFromRequest(Request $request): null|string
    {
        return $this->geoLocationService->getCountryCodeFromRequest($request);
    }

    /**
     * Format a single course with pricing for API response
     *
     * @param  array<string, mixed>  $additionalFields  Additional fields to include
     * @return array<string, mixed>
     */
    public function formatCourseWithPricing(
        Course $course,
        array $pricing,
        bool $isWishlisted = false,
        array $additionalFields = [],
    ): array {
        $formatted = [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'thumbnail' => $course->thumbnail,
            'instructor' => $course->user['name'] ?? '',
            'is_wishlisted' => $isWishlisted,
            'promo_code' => $pricing['promo_code_details'],
            //
            'original_price' => 0,
            'course_discount' => 0,
            'subtotal' => 0,
            'promo_discount' => 0,
            'taxable_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'currency_code' => 'EGP',
            'display_currency' => 'EGP',
            'display_symbol' => 'ج.م',
            'display_price' => 0,
            'formatted_price' => '0 ج.م',
        ];

        return [...$formatted, ...$additionalFields];
    }

    /**
     * Calculate aggregate pricing for multiple courses
     *
     * @param  Collection<int, array{pricing: array, course: Course}>  $coursePricingData
     * @return array{
     *     original_price: float,
     *     course_discount: float,
     *     subtotal: float,
     *     promo_discount: float,
     *     taxable_amount: float,
     *     tax_percentage: float,
     *     tax_amount: float,
     *     total: float
     * }
     */
    public function calculateAggregatePricing(Collection $coursePricingData, float $taxPercentage): array
    {
        return [
            'original_price' => 0,
            'course_discount' => 0,
            'subtotal' => 0,
            'promo_discount' => 0,
            'taxable_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'currency_code' => 'EGP',
            'display_currency' => 'EGP',
            'display_symbol' => 'ج.م',
            'display_price' => 0,
            'formatted_price' => '0 ج.م',
        ];
    }

    /**
     * Build empty pricing response structure
     *
     * @return array<string, mixed>
     */
    public function buildEmptyPricingResponse(float $taxPercentage = 0, null|string $countryCode = null): array
    {
        return [
            'courses' => [],
            'detected_country_code' => $countryCode,
            'promo_discounts' => [],
            'billing_details' => null,
            //
            'original_price' => 0,
            'course_discount' => 0,
            'subtotal' => 0,
            'promo_discount' => 0,
            'taxable_amount' => 0,
            'tax_percentage' => $taxPercentage,
            'tax_amount' => 0,
            'total' => 0,
            'currency_code' => 'EGP',
            'display_currency' => 'EGP',
            'display_symbol' => 'ج.م',
            'display_price' => 0,
            'formatted_price' => '0.00 ج.م',
        ];
    }
}
