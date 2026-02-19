<?php

namespace Tests\Unit\Models;

use App\Models\Wishlist;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    public function test_wishlist_model_exists()
    {
        $this->assertTrue(class_exists(Wishlist::class));
    }

    public function test_wishlist_belongs_to_user()
    {
        $wishlist = new Wishlist();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $wishlist->user());
    }

    public function test_wishlist_belongs_to_course()
    {
        $wishlist = new Wishlist();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $wishlist->course());
    }

    public function test_wishlist_fillable_attributes()
    {
        $wishlist = new Wishlist();
        $fillable = $wishlist->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('course_id', $fillable);
    }
}
