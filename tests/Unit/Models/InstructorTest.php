<?php

namespace Tests\Unit\Models;

use App\Models\Instructor;
use Tests\TestCase;

class InstructorTest extends TestCase
{
    public function test_instructor_model_exists()
    {
        $this->assertTrue(class_exists(Instructor::class));
    }

    public function test_instructor_belongs_to_user()
    {
        $instructor = new Instructor();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $instructor->user());
    }

    public function test_instructor_has_courses_relationship()
    {
        $instructor = new Instructor();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $instructor->courses());
    }

    public function test_instructor_fillable_attributes()
    {
        $instructor = new Instructor();
        $fillable = $instructor->getFillable();

        $this->assertIsArray($fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('type', $fillable);
    }
}
