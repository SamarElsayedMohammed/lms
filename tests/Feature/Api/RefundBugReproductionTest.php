<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Course\CourseLanguage;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserBillingDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefundBugReproductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_response_reports_the_completed_order_total()
    {
        Queue::fake();

        // 1. Authenticate User
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create Billing Details (required for order)
        UserBillingDetail::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'NY',
            'country_code' => 'US',
            'postal_code' => '10001',
            'phone' => '1234567890',
        ]);

        // Create dependencies manually since factories are missing/incomplete
        $category = Category::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'status' => 1,
            'is_active' => 1,
        ]);

        $language = CourseLanguage::create([
            'name' => 'English',
            'slug' => 'en',
            'is_active' => 1,
        ]);

        $instructor = User::factory()->create(['is_active' => 1]);
        Instructor::create([
            'user_id' => $instructor->id,
            'type' => 'individual',
            'status' => 'approved',
        ]);

        // 2. Create 2 courses with specific prices
        $course1 = \Database\Factories\CourseFactory::new()->create([
            'price' => 100,
            'discount_price' => 100, // Explicitly set equal to price or null? Logic uses price.
            'course_type' => 'paid',
            'title' => 'Course 1 (100)',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $instructor->id,
        ]);

        $course2 = \Database\Factories\CourseFactory::new()->create([
            'price' => 200,
            'discount_price' => 200,
            'course_type' => 'paid',
            'title' => 'Course 2 (200)',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $instructor->id,
        ]);

        // Enable refunds
        Setting::updateOrCreate(['name' => 'refund_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['name' => 'refund_period_days'], ['value' => '30']);

        // 3. Add courses to cart
        $this->postJson('/api/cart/add', ['course_id' => $course1->id])->assertStatus(200);
        $this->postJson('/api/cart/add', ['course_id' => $course2->id])->assertStatus(200);

        // 4. Place order via API

        // Credit user wallet - use 'adjustment' as 'deposit' is not in enum
        \App\Services\WalletService::creditWallet($user->id, 1000, 'adjustment', 'Test Deposit');

        $response = $this->withHeader('Idempotency-Key', 'refund-order-test-' . $user->id)
            ->postJson('/api/place_order', [
            'payment_method' => 'wallet',
            'type' => 'web',
        ]);

        $response->assertStatus(200);

        // Verify total price is 300
        $this->assertEquals(300, $response->json('data.total'));
    }

    public function test_refund_amount_is_limited_to_the_requested_course(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::create([
            'name' => 'Refund Category',
            'slug' => 'refund-category',
            'status' => 1,
            'is_active' => 1,
        ]);

        $language = CourseLanguage::create([
            'name' => 'English',
            'slug' => 'en',
            'is_active' => 1,
        ]);

        $instructorUser = User::factory()->create(['is_active' => 1]);
        Instructor::create([
            'user_id' => $instructorUser->id,
            'type' => 'individual',
            'status' => 'approved',
        ]);

        $course1 = \Database\Factories\CourseFactory::new()->create([
            'price' => 100,
            'discount_price' => 100,
            'course_type' => 'paid',
            'title' => 'Refundable Course 1',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $instructorUser->id,
        ]);
        $course2 = \Database\Factories\CourseFactory::new()->create([
            'price' => 200,
            'discount_price' => 200,
            'course_type' => 'paid',
            'title' => 'Refundable Course 2',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $instructorUser->id,
        ]);

        Setting::updateOrCreate(['name' => 'refund_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['name' => 'refund_period_days'], ['value' => '30']);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'REFUND-' . $user->id,
            'total_price' => 300,
            'tax_price' => 0,
            'final_price' => 300,
            'payment_method' => 'wallet',
            'status' => 'completed',
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course1->id,
            'price' => 100,
            'tax_price' => 0,
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course2->id,
            'price' => 200,
            'tax_price' => 0,
        ]);
        Transaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => 300,
            'payment_method' => 'wallet',
            'status' => 'completed',
        ]);

        $refundResponse = $this
            ->withHeader('Idempotency-Key', 'refund-request-test-' . $user->id)
            ->postJson('/api/refund/request', [
                'course_id' => $course1->id,
                'reason' => 'Test Refund',
            ]);

        $refundResponse->assertStatus(200);

        // 6. Verify refund amount in database
        $this->assertDatabaseHas('refund_requests', [
            'user_id' => $user->id,
            'course_id' => $course1->id,
            'refund_amount' => 100, // We expect 100 (Course 1 price)
        ]);

        // Also verify the OTHER course refund amount if we were to request it
        // Should be 200
        $this->assertDatabaseMissing('refund_requests', [
            'course_id' => $course2->id,
        ]);
    }
}
