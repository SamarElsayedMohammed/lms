<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\API\WishlistApiController;
use Tests\TestCase;

class WishlistApiControllerTest extends TestCase
{
    public function test_wishlist_api_controller_exists()
    {
        $this->assertTrue(class_exists(WishlistApiController::class));
    }

    public function test_get_wishlist_method_exists()
    {
        $this->assertTrue(method_exists(WishlistApiController::class, 'getWishlist'));
    }

    public function test_add_to_wishlist_method_exists()
    {
        $this->assertTrue(method_exists(WishlistApiController::class, 'addToWishlist'));
    }

    public function test_remove_from_wishlist_method_exists()
    {
        $this->assertTrue(method_exists(WishlistApiController::class, 'removeFromWishlist'));
    }

    public function test_controller_extends_base_controller()
    {
        $controller = new WishlistApiController();
        $this->assertInstanceOf(\App\Http\Controllers\Controller::class, $controller);
    }
}
