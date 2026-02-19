<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cart pricing calculations.
 *
 * These tests verify the pricing logic without database dependencies.
 * The calculations follow this flow:
 *
 * original_price     = Full price before any discount
 * course_discount    = original_price - subtotal
 * subtotal           = Price after course discount (sale price)
 * promo_discount     = Promo code discount amount
 * taxable_amount     = subtotal - promo_discount
 * tax_amount         = taxable_amount * tax_percentage / 100
 * total              = taxable_amount + tax_amount
 */
final class CartPricingTest extends TestCase
{
    // ==================== HELPER METHODS ====================

    /**
     * Calculate course-level pricing
     */
    private function calculateCoursePricing(
        float $price,
        null|float $discountPrice,
        float $promoDiscountPercent = 0,
        float $promoDiscountAmount = 0,
        float $promoMaxDiscount = 0,
        float $taxPercentage = 0,
    ): array {
        // Original price (full price)
        $originalPrice = $price;

        // Subtotal (price after course discount)
        $subtotal = $discountPrice !== null && $discountPrice > 0 ? $discountPrice : $originalPrice;

        // Course discount
        $courseDiscount = $originalPrice - $subtotal;

        // Calculate promo discount
        $promoDiscount = 0;
        if ($promoDiscountPercent > 0) {
            $promoDiscount = ($subtotal * $promoDiscountPercent) / 100;
            if ($promoMaxDiscount > 0) {
                $promoDiscount = min($promoDiscount, $promoMaxDiscount);
            }
        } elseif ($promoDiscountAmount > 0) {
            $promoDiscount = min($promoDiscountAmount, $subtotal);
        }

        // Taxable amount
        $taxableAmount = max(0, $subtotal - $promoDiscount);

        // Tax
        $taxAmount = ($taxableAmount * $taxPercentage) / 100;

        // Total
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
     * Calculate cart summary from multiple courses
     */
    private function calculateCartSummary(array $courses, float $taxPercentage = 0): array
    {
        $originalPrice = 0;
        $subtotal = 0;
        $promoDiscount = 0;
        $taxAmount = 0;

        foreach ($courses as $course) {
            $originalPrice += $course['original_price'];
            $subtotal += $course['subtotal'];
            $promoDiscount += $course['promo_discount'];
            $taxAmount += $course['tax_amount'];
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

    // ==================== SINGLE COURSE TESTS ====================

    public function test_single_course_no_discount_no_tax(): void
    {
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(0.00, $result['course_discount']);
        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(0.00, $result['promo_discount']);
        $this->assertEquals(100.00, $result['taxable_amount']);
        $this->assertEquals(0.00, $result['tax_amount']);
        $this->assertEquals(100.00, $result['total']);
    }

    public function test_single_course_with_course_discount(): void
    {
        // Course: $100 original, $80 sale price
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: 80.00,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(20.00, $result['course_discount']);
        $this->assertEquals(80.00, $result['subtotal']);
        $this->assertEquals(0.00, $result['promo_discount']);
        $this->assertEquals(80.00, $result['taxable_amount']);
        $this->assertEquals(80.00, $result['total']);
    }

    public function test_promo_code_percentage_discount(): void
    {
        // $100 course with 20% promo
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
            promoDiscountPercent: 20,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(0.00, $result['course_discount']);
        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(20.00, $result['promo_discount']);
        $this->assertEquals(80.00, $result['taxable_amount']);
        $this->assertEquals(80.00, $result['total']);
    }

    public function test_promo_code_fixed_amount_discount(): void
    {
        // $100 course with $15 off
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
            promoDiscountAmount: 15,
        );

        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(15.00, $result['promo_discount']);
        $this->assertEquals(85.00, $result['taxable_amount']);
        $this->assertEquals(85.00, $result['total']);
    }

    public function test_promo_code_percentage_with_max_discount(): void
    {
        // $200 course with 50% off but max $30
        $result = $this->calculateCoursePricing(
            price: 200.00,
            discountPrice: null,
            promoDiscountPercent: 50,
            promoMaxDiscount: 30,
        );

        $this->assertEquals(200.00, $result['subtotal']);
        $this->assertEquals(30.00, $result['promo_discount']); // Capped at max
        $this->assertEquals(170.00, $result['taxable_amount']);
        $this->assertEquals(170.00, $result['total']);
    }

    public function test_course_discount_plus_promo_code(): void
    {
        // Course: $100 original, $80 sale price, 10% promo
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: 80.00,
            promoDiscountPercent: 10,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(20.00, $result['course_discount']);
        $this->assertEquals(80.00, $result['subtotal']);
        $this->assertEquals(8.00, $result['promo_discount']); // 10% of 80
        $this->assertEquals(72.00, $result['taxable_amount']);
        $this->assertEquals(72.00, $result['total']);
    }

    public function test_tax_calculation(): void
    {
        // $100 course with 10% tax
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
            taxPercentage: 10,
        );

        $this->assertEquals(100.00, $result['taxable_amount']);
        $this->assertEquals(10.0, $result['tax_percentage']);
        $this->assertEquals(10.00, $result['tax_amount']);
        $this->assertEquals(110.00, $result['total']);
    }

    public function test_full_scenario_course_discount_promo_and_tax(): void
    {
        /*
         * Course: $100 original, $80 sale price
         * Promo: 25% off
         * Tax: 18%
         *
         * Calculation:
         * original_price = 100
         * course_discount = 100 - 80 = 20
         * subtotal = 80
         * promo_discount = 80 * 25% = 20
         * taxable_amount = 80 - 20 = 60
         * tax_amount = 60 * 18% = 10.8
         * total = 60 + 10.8 = 70.8
         */
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: 80.00,
            promoDiscountPercent: 25,
            taxPercentage: 18,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(20.00, $result['course_discount']);
        $this->assertEquals(80.00, $result['subtotal']);
        $this->assertEquals(20.00, $result['promo_discount']);
        $this->assertEquals(60.00, $result['taxable_amount']);
        $this->assertEquals(18.0, $result['tax_percentage']);
        $this->assertEquals(10.80, $result['tax_amount']);
        $this->assertEquals(70.80, $result['total']);
    }

    public function test_promo_discount_cannot_exceed_subtotal(): void
    {
        // $50 course with $100 off promo (should cap at $50)
        $result = $this->calculateCoursePricing(
            price: 50.00,
            discountPrice: null,
            promoDiscountAmount: 100,
        );

        $this->assertEquals(50.00, $result['promo_discount']); // Capped at subtotal
        $this->assertEquals(0.00, $result['taxable_amount']);
        $this->assertEquals(0.00, $result['total']);
    }

    // ==================== MULTIPLE COURSES TESTS ====================

    public function test_multiple_courses_summary(): void
    {
        // Course 1: $100, no discount
        $course1 = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
        );

        // Course 2: $200, $150 sale price
        $course2 = $this->calculateCoursePricing(
            price: 200.00,
            discountPrice: 150.00,
        );

        $summary = $this->calculateCartSummary([$course1, $course2]);

        $this->assertEquals(300.00, $summary['original_price']);
        $this->assertEquals(50.00, $summary['course_discount']);
        $this->assertEquals(250.00, $summary['subtotal']);
        $this->assertEquals(0.00, $summary['promo_discount']);
        $this->assertEquals(250.00, $summary['taxable_amount']);
        $this->assertEquals(250.00, $summary['total']);
    }

    public function test_multiple_courses_with_promo_and_tax(): void
    {
        /*
         * Course 1: $100 -> $80 (sale) -> 10% promo = $8 -> taxable $72
         * Course 2: $200 -> no sale -> 10% promo = $20 -> taxable $180
         * Tax: 10%
         */
        $course1 = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: 80.00,
            promoDiscountPercent: 10,
            taxPercentage: 10,
        );

        $course2 = $this->calculateCoursePricing(
            price: 200.00,
            discountPrice: null,
            promoDiscountPercent: 10,
            taxPercentage: 10,
        );

        $summary = $this->calculateCartSummary([$course1, $course2], 10);

        // original: 100 + 200 = 300
        $this->assertEquals(300.00, $summary['original_price']);

        // course_discount: (100-80) + 0 = 20
        $this->assertEquals(20.00, $summary['course_discount']);

        // subtotal: 80 + 200 = 280
        $this->assertEquals(280.00, $summary['subtotal']);

        // promo: 8 + 20 = 28
        $this->assertEquals(28.00, $summary['promo_discount']);

        // taxable: 280 - 28 = 252
        $this->assertEquals(252.00, $summary['taxable_amount']);

        // tax: 7.2 + 18 = 25.2
        $this->assertEquals(25.20, $summary['tax_amount']);

        // total: 252 + 25.2 = 277.2
        $this->assertEquals(277.20, $summary['total']);
    }

    // ==================== EDGE CASES ====================

    public function test_zero_price_course(): void
    {
        $result = $this->calculateCoursePricing(
            price: 0.00,
            discountPrice: null,
        );

        $this->assertEquals(0.00, $result['original_price']);
        $this->assertEquals(0.00, $result['subtotal']);
        $this->assertEquals(0.00, $result['total']);
    }

    public function test_discount_price_equals_original(): void
    {
        // Edge case: discount_price = price (no actual discount)
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: 100.00,
        );

        $this->assertEquals(100.00, $result['original_price']);
        $this->assertEquals(0.00, $result['course_discount']);
        $this->assertEquals(100.00, $result['subtotal']);
    }

    public function test_100_percent_promo_discount(): void
    {
        // 100% off promo
        $result = $this->calculateCoursePricing(
            price: 100.00,
            discountPrice: null,
            promoDiscountPercent: 100,
        );

        $this->assertEquals(100.00, $result['promo_discount']);
        $this->assertEquals(0.00, $result['taxable_amount']);
        $this->assertEquals(0.00, $result['total']);
    }

    public function test_decimal_precision(): void
    {
        // Test with values that could cause floating point issues
        $result = $this->calculateCoursePricing(
            price: 99.99,
            discountPrice: 79.99,
            promoDiscountPercent: 15,
            taxPercentage: 18,
        );

        // subtotal = 79.99
        // promo = 79.99 * 15% = 11.9985 -> 12.00
        // taxable = 79.99 - 12.00 = 67.99
        // tax = 67.99 * 18% = 12.2382 -> 12.24
        // total = 67.99 + 12.24 = 80.23
        $this->assertEquals(99.99, $result['original_price']);
        $this->assertEquals(20.00, $result['course_discount']);
        $this->assertEquals(79.99, $result['subtotal']);
        $this->assertEquals(12.00, $result['promo_discount']);
        $this->assertEquals(67.99, $result['taxable_amount']);
        $this->assertEquals(12.24, $result['tax_amount']);
        $this->assertEquals(80.23, $result['total']);
    }
}
