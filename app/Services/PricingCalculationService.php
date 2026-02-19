<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\PromoCode;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PricingCalculationService
{
    public function __construct(
        private GeoLocationService $geoLocationService,
    ) {}

    /**
     * Calculate pricing for a single course with optional promo code
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
     *     promo_code_details: array|null
     * }
     */
    public function calculateCoursePricing(
        Course $course,
        null|PromoCode $promoCode = null,
        null|float $taxPercentage = null,
    ): array {
        // Original price (full price before any discount) - cast to float
        $originalPrice = (float) ($course->price ?? 0);

        // Subtotal (price after course discount, before promo) - cast to float
        $discountPrice = (float) ($course->discount_price ?? 0);
        $subtotal = $discountPrice > 0 ? $discountPrice : $originalPrice;

        // Course discount amount
        $courseDiscount = $originalPrice - $subtotal;

        // Calculate promo discount
        $promoDiscount = 0;
        $promoCodeDetails = null;

        if ($promoCode !== null) {
            $promoResult = $this->calculatePromoDiscount($promoCode, $subtotal);
            $promoDiscount = $promoResult['discount_amount'];
            $promoCodeDetails = $promoResult['details'];
        }

        // Taxable amount (after promo discount)
        $taxableAmount = max(0, $subtotal - $promoDiscount);

        // Calculate tax
        $taxPercentage = $taxPercentage ?? 0;
        $taxAmount = 0;
        if ($taxPercentage > 0 && $taxableAmount > 0) {
            $taxAmount = ($taxableAmount * $taxPercentage) / 100;
        }

        // Total = taxable_amount + tax
        $total = $taxableAmount + $taxAmount;

        return [
            'original_price' => round($originalPrice, 2),
            'course_discount' => round($courseDiscount, 2),
            'subtotal' => round($subtotal, 2),
            'promo_discount' => round($promoDiscount, 2),
            'taxable_amount' => round($taxableAmount, 2),
            'tax_percentage' => $taxPercentage,
            'tax_amount' => round($taxAmount, 2),
            'total' => round($total, 2),
            'promo_code_details' => $promoCodeDetails,
        ];
    }

    /**
     * Calculate promo code discount for a given amount
     *
     * @return array{discount_amount: float, details: array|null}
     */
    public function calculatePromoDiscount(PromoCode $promoCode, float $subtotal): array
    {
        // Check if promo code is valid
        if (!$this->isPromoCodeValid($promoCode)) {
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
    public function isPromoCodeValid(PromoCode $promoCode): bool
    {
        // Check status
        if ($promoCode->status != 1) {
            return false;
        }

        // Check date range (use today() to compare dates without time component)
        if ($promoCode->start_date > today() || $promoCode->end_date < today()) {
            return false;
        }

        // Check usage limit
        if ($promoCode->no_of_users !== null && $promoCode->no_of_users <= 0) {
            return false;
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
            'original_price' => $pricing['original_price'],
            'course_discount' => $pricing['course_discount'],
            'subtotal' => $pricing['subtotal'],
            'promo_discount' => $pricing['promo_discount'],
            'taxable_amount' => $pricing['taxable_amount'],
            'tax_percentage' => $pricing['tax_percentage'],
            'tax_amount' => $pricing['tax_amount'],
            'total' => $pricing['total'],
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
        $originalPrice = 0;
        $subtotal = 0;
        $promoDiscount = 0;
        $taxAmount = 0;

        foreach ($coursePricingData as $data) {
            $pricing = $data['pricing'];
            $originalPrice += $pricing['original_price'];
            $subtotal += $pricing['subtotal'];
            $promoDiscount += $pricing['promo_discount'];
            $taxAmount += $pricing['tax_amount'];
        }

        $courseDiscount = $originalPrice - $subtotal;
        $taxableAmount = max(0, $subtotal - $promoDiscount);
        $total = $taxableAmount + $taxAmount;

        return [
            'original_price' => round($originalPrice, 2),
            'course_discount' => round($courseDiscount, 2),
            'subtotal' => round($subtotal, 2),
            'promo_discount' => round($promoDiscount, 2),
            'taxable_amount' => round($taxableAmount, 2),
            'tax_percentage' => $taxPercentage,
            'tax_amount' => round($taxAmount, 2),
            'total' => round($total, 2),
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
        ];
    }
}
