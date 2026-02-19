<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use Tests\TestCase;

class OrderTest extends TestCase
{
    public function test_order_model_exists()
    {
        $this->assertTrue(class_exists(Order::class));
    }

    public function test_order_belongs_to_user()
    {
        $order = new Order();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $order->user());
    }

    public function test_order_has_order_courses_relationship()
    {
        $order = new Order();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $order->orderCourses());
    }

    public function test_order_fillable_attributes()
    {
        $order = new Order();
        $fillable = $order->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('status', $fillable);
    }
}
