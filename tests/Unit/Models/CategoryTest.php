<?php

namespace Tests\Unit\Models;

use App\Models\Category;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    public function test_category_model_exists()
    {
        $this->assertTrue(class_exists(Category::class));
    }

    public function test_category_has_courses_relationship()
    {
        $category = new Category();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $category->courses());
    }

    public function test_category_fillable_attributes()
    {
        $category = new Category();
        $fillable = $category->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('slug', $fillable);
    }

    public function test_has_subcategory_uses_eager_loaded_subcategories_without_query(): void
    {
        $category = new Category();
        $category->setRelation('subcategories', collect([
            new Category(['status' => true]),
        ]));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $this->assertTrue($category->has_subcategory);
        $this->assertEmpty(\Illuminate\Support\Facades\DB::getQueryLog());
    }

    public function test_has_subcategory_uses_subcategories_count_attribute_without_query(): void
    {
        $category = new Category();
        $category->setAttribute('subcategories_count', 3);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $this->assertTrue($category->has_subcategory);
        $this->assertEmpty(\Illuminate\Support\Facades\DB::getQueryLog());

        $categoryZero = new Category();
        $categoryZero->setAttribute('subcategories_count', 0);
        $this->assertFalse($categoryZero->has_subcategory);
        $this->assertEmpty(\Illuminate\Support\Facades\DB::getQueryLog());
    }
}
