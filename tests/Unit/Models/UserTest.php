<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_model_exists()
    {
        $this->assertTrue(class_exists(User::class));
    }

    public function test_user_has_wishlists_relationship()
    {
        $user = new User();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->wishlists());
    }

    public function test_user_has_orders_relationship()
    {
        $user = new User();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->orders());
    }

    public function test_user_has_wallet_histories_relationship()
    {
        $user = new User();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->walletHistories());
    }

    public function test_user_has_refund_requests_relationship()
    {
        $user = new User();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->refundRequests());
    }

    public function test_user_fillable_attributes()
    {
        $user = new User();
        $fillable = $user->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('email', $fillable);
    }
}
