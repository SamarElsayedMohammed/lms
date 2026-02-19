<?php

namespace Tests\Unit\Models;

use App\Models\Course\Course;
use Tests\TestCase;

class CourseTest extends TestCase
{
    public function test_course_model_exists()
    {
        $this->assertTrue(class_exists(Course::class));
    }

    public function test_course_has_category_relationship()
    {
        $course = new Course();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $course->category());
    }

    public function test_course_has_instructors_relationship()
    {
        $course = new Course();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class, $course->instructors());
    }

    public function test_course_has_wishlists_relationship()
    {
        $course = new Course();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $course->wishlists());
    }

    public function test_course_has_ratings_relationship()
    {
        $course = new Course();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class, $course->ratings());
    }

    public function test_course_fillable_attributes()
    {
        $course = new Course();
        $fillable = $course->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('title', $fillable);
        $this->assertContains('slug', $fillable);
    }
}
