<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Course\CourseLanguage;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserBillingDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundBugReproductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_amount_is_correct_via_api_flow()
    {
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

        // 2. Create 2 courses with specific prices
        $course1 = \Database\Factories\CourseFactory::new()->create([
            'price' => 100,
            'discount_price' => 100, // Explicitly set equal to price or null? Logic uses price.
            'course_type' => 'paid',
            'title' => 'Course 1 (100)',
            'category_id' => $category->id,
            'language_id' => $language->id,
        ]);

        $course2 = \Database\Factories\CourseFactory::new()->create([
            'price' => 200,
            'discount_price' => 200,
            'course_type' => 'paid',
            'title' => 'Course 2 (200)',
            'category_id' => $category->id,
            'language_id' => $language->id,
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

        $response = $this->postJson('/api/place_order', [
            'payment_method' => 'wallet',
            'type' => 'web',
        ]);

        $response->assertStatus(200);

        $order = $response->json('data.order');
        $this->assertNotNull($order);

        // Verify total price is 300
        $this->assertEquals(300, $order['final_price']);

        // 5. Request Refund for Course 1 ONLY
        // Expected refund amount: 100 (Course 1 price)
        // Buggy behavior: 300 (Total Order Price)

        $refundResponse = $this->postJson('/api/refund/request', [
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
