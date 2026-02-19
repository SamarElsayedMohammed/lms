<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\Course\Course;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;
    private User $instructor;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        Role::create(['name' => 'Instructor', 'guard_name' => 'web']);
        Role::create(['name' => 'Student', 'guard_name' => 'web']);

        // Create admin user (user_id = 1 for admin promo codes)
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        // Create instructor
        $this->instructor = User::factory()->create();
        $this->instructor->assignRole('Instructor');

        // Create regular user
        $this->user = User::factory()->create();
        $this->user->assignRole('Student');

        // Clear tax cache before each test
        Tax::query()->delete();
    }

    // ==================== EMPTY CART TESTS ====================

    public function test_empty_cart_returns_zero_values(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response
            ->assertOk()
            ->assertJsonPath('data.original_price', 0)
            ->assertJsonPath('data.course_discount', 0)
            ->assertJsonPath('data.subtotal', 0)
            ->assertJsonPath('data.promo_discount', 0)
            ->assertJsonPath('data.taxable_amount', 0)
            ->assertJsonPath('data.tax_amount', 0)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.courses', []);
    }

    // ==================== SINGLE COURSE - NO DISCOUNT ====================

    public function test_single_course_no_discount_no_tax(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => null,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // Per-course assertions
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['original_price']);
        $this->assertEquals(0.00, $courseData['course_discount']);
        $this->assertEquals(100.00, $courseData['subtotal']);
        $this->assertEquals(0.00, $courseData['promo_discount']);
        $this->assertEquals(100.00, $courseData['taxable_amount']);
        $this->assertEquals(0.00, $courseData['tax_amount']);
        $this->assertEquals(100.00, $courseData['total']);

        // Summary assertions
        $this->assertEquals(100.00, $response->json('data.original_price'));
        $this->assertEquals(0.00, $response->json('data.course_discount'));
        $this->assertEquals(100.00, $response->json('data.subtotal'));
        $this->assertEquals(0.00, $response->json('data.promo_discount'));
        $this->assertEquals(100.00, $response->json('data.taxable_amount'));
        $this->assertEquals(0.00, $response->json('data.tax_amount'));
        $this->assertEquals(100.00, $response->json('data.total'));
    }

    // ==================== COURSE DISCOUNT (SALE PRICE) ====================

    public function test_single_course_with_course_discount(): void
    {
        // Course: $100 original, $80 sale price
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => 80.00,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // Per-course: original=100, course_discount=20, subtotal=80
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['original_price']);
        $this->assertEquals(20.00, $courseData['course_discount']);
        $this->assertEquals(80.00, $courseData['subtotal']);
        $this->assertEquals(80.00, $courseData['taxable_amount']);
        $this->assertEquals(80.00, $courseData['total']);

        // Summary
        $this->assertEquals(100.00, $response->json('data.original_price'));
        $this->assertEquals(20.00, $response->json('data.course_discount'));
        $this->assertEquals(80.00, $response->json('data.subtotal'));
        $this->assertEquals(80.00, $response->json('data.total'));
    }

    // ==================== PROMO CODE - PERCENTAGE ====================

    public function test_promo_code_percentage_discount(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => null,
        ]);

        // 20% off promo code from admin
        $promoCode = PromoCode::factory()->create([
            'user_id' => $this->admin->id,
            'discount_type' => 'percentage',
            'discount' => 20,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // Per-course: subtotal=100, promo=20, taxable=80
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['original_price']);
        $this->assertEquals(0.00, $courseData['course_discount']);
        $this->assertEquals(100.00, $courseData['subtotal']);
        $this->assertEquals(20.00, $courseData['promo_discount']);
        $this->assertEquals(80.00, $courseData['taxable_amount']);
        $this->assertEquals(80.00, $courseData['total']);

        // Summary
        $this->assertEquals(100.00, $response->json('data.subtotal'));
        $this->assertEquals(20.00, $response->json('data.promo_discount'));
        $this->assertEquals(80.00, $response->json('data.taxable_amount'));
        $this->assertEquals(80.00, $response->json('data.total'));
    }

    // ==================== PROMO CODE - FIXED AMOUNT ====================

    public function test_promo_code_fixed_amount_discount(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => null,
        ]);

        // $15 off promo code
        $promoCode = PromoCode::factory()->create([
            'user_id' => $this->admin->id,
            'discount_type' => 'amount',
            'discount' => 15,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['subtotal']);
        $this->assertEquals(15.00, $courseData['promo_discount']);
        $this->assertEquals(85.00, $courseData['taxable_amount']);
        $this->assertEquals(85.00, $courseData['total']);
    }

    // ==================== PROMO CODE LARGE PERCENTAGE DISCOUNT ====================

    public function test_promo_code_large_percentage_discount(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 200.00,
            'discount_price' => null,
        ]);

        // 50% off
        $promoCode = PromoCode::factory()->create([
            'user_id' => $this->admin->id,
            'discount_type' => 'percentage',
            'discount' => 50,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // 50% of 200 = 100
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(200.00, $courseData['subtotal']);
        $this->assertEquals(100.00, $courseData['promo_discount']);
        $this->assertEquals(100.00, $courseData['taxable_amount']);
        $this->assertEquals(100.00, $courseData['total']);
    }

    // ==================== COURSE DISCOUNT + PROMO CODE ====================

    public function test_course_discount_plus_promo_code(): void
    {
        // Course: $100 original, $80 sale price
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => 80.00,
        ]);

        // 10% off promo on top of sale price
        $promoCode = PromoCode::factory()->create([
            'user_id' => $this->admin->id,
            'discount_type' => 'percentage',
            'discount' => 10,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // original=100, course_discount=20, subtotal=80, promo=8 (10% of 80), taxable=72
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['original_price']);
        $this->assertEquals(20.00, $courseData['course_discount']);
        $this->assertEquals(80.00, $courseData['subtotal']);
        $this->assertEquals(8.00, $courseData['promo_discount']); // 10% of 80
        $this->assertEquals(72.00, $courseData['taxable_amount']);
        $this->assertEquals(72.00, $courseData['total']);
    }

    // ==================== TAX CALCULATIONS ====================

    public function test_tax_calculation_on_taxable_amount(): void
    {
        // Create default tax of 10%
        Tax::factory()->create([
            'percentage' => 10.0,
            'is_default' => true,
            'is_active' => true,
        ]);

        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => null,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // taxable=100, tax=10 (10%), total=110
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['taxable_amount']);
        $this->assertEquals(10.0, $courseData['tax_percentage']);
        $this->assertEquals(10.00, $courseData['tax_amount']);
        $this->assertEquals(110.00, $courseData['total']);

        // Summary
        $this->assertEquals(10.0, $response->json('data.tax_percentage'));
        $this->assertEquals(10.00, $response->json('data.tax_amount'));
        $this->assertEquals(110.00, $response->json('data.total'));
    }

    // ==================== FULL SCENARIO: DISCOUNT + PROMO + TAX ====================

    public function test_full_scenario_course_discount_promo_and_tax(): void
    {
        // Create 18% tax
        Tax::factory()->create([
            'percentage' => 18.0,
            'is_default' => true,
            'is_active' => true,
        ]);

        // Course: $100 original, $80 sale price
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => 80.00,
        ]);

        // 25% off promo
        $promoCode = PromoCode::factory()->create([
            'user_id' => $this->admin->id,
            'discount_type' => 'percentage',
            'discount' => 25,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        /*
         * Calculation:
         * original_price = 100
         * course_discount = 100 - 80 = 20
         * subtotal = 80
         * promo_discount = 80 * 25% = 20
         * taxable_amount = 80 - 20 = 60
         * tax_amount = 60 * 18% = 10.8
         * total = 60 + 10.8 = 70.8
         */
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(100.00, $courseData['original_price']);
        $this->assertEquals(20.00, $courseData['course_discount']);
        $this->assertEquals(80.00, $courseData['subtotal']);
        $this->assertEquals(20.00, $courseData['promo_discount']);
        $this->assertEquals(60.00, $courseData['taxable_amount']);
        $this->assertEquals(18.0, $courseData['tax_percentage']);
        $this->assertEquals(10.80, $courseData['tax_amount']);
        $this->assertEquals(70.80, $courseData['total']);

        // Summary should match
        $this->assertEquals(100.00, $response->json('data.original_price'));
        $this->assertEquals(20.00, $response->json('data.course_discount'));
        $this->assertEquals(80.00, $response->json('data.subtotal'));
        $this->assertEquals(20.00, $response->json('data.promo_discount'));
        $this->assertEquals(60.00, $response->json('data.taxable_amount'));
        $this->assertEquals(18.0, $response->json('data.tax_percentage'));
        $this->assertEquals(10.80, $response->json('data.tax_amount'));
        $this->assertEquals(70.80, $response->json('data.total'));
    }

    // ==================== MULTIPLE COURSES ====================

    public function test_multiple_courses_in_cart(): void
    {
        // Course 1: $100, no discount
        $course1 = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
            'discount_price' => null,
        ]);

        // Course 2: $200, $150 sale price
        $course2 = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 200.00,
            'discount_price' => 150.00,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course1->id,
        ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course2->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        /*
         * Summary calculation:
         * original_price = 100 + 200 = 300
         * subtotal = 100 + 150 = 250
         * course_discount = 300 - 250 = 50
         */
        $this->assertEquals(300.00, $response->json('data.original_price'));
        $this->assertEquals(50.00, $response->json('data.course_discount'));
        $this->assertEquals(250.00, $response->json('data.subtotal'));
        $this->assertEquals(0.00, $response->json('data.promo_discount'));
        $this->assertEquals(250.00, $response->json('data.taxable_amount'));
        $this->assertEquals(250.00, $response->json('data.total'));

        // Check we have 2 courses
        $this->assertCount(2, $response->json('data.courses'));
    }

    // ==================== PROMO CODE EDGE CASES ====================

    public function test_expired_promo_code_not_applied(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
        ]);

        $expiredPromo = PromoCode::factory()
            ->expired()
            ->create([
                'user_id' => $this->admin->id,
                'discount_type' => 'percentage',
                'discount' => 50,
            ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $expiredPromo->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // Promo should not be applied (expired)
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(0.00, $courseData['promo_discount']);
        $this->assertEquals(100.00, $courseData['taxable_amount']);
    }

    public function test_inactive_promo_code_not_applied(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 100.00,
        ]);

        $inactivePromo = PromoCode::factory()
            ->inactive()
            ->create([
                'user_id' => $this->admin->id,
                'discount_type' => 'percentage',
                'discount' => 50,
            ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $inactivePromo->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        $courseData = $response->json('data.courses.0');
        $this->assertEquals(0.00, $courseData['promo_discount']);
    }

    // ==================== PROMO DISCOUNT CANNOT EXCEED PRICE ====================

    public function test_promo_discount_cannot_exceed_subtotal(): void
    {
        $course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'price' => 50.00,
        ]);

        // $100 off promo on a $50 course
        $promoCode = PromoCode::factory()
            ->fixedAmount(100)
            ->create([
                'user_id' => $this->admin->id,
            ]);

        Cart::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promoCode->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/cart');

        $response->assertOk();

        // Discount capped at course price
        $courseData = $response->json('data.courses.0');
        $this->assertEquals(50.00, $courseData['promo_discount']); // Capped at subtotal
        $this->assertEquals(0.00, $courseData['taxable_amount']);
        $this->assertEquals(0.00, $courseData['total']);
    }
}
