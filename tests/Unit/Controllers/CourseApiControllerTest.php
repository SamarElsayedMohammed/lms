<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\API\CourseApiController;
use Tests\TestCase;

class CourseApiControllerTest extends TestCase
{
    public function test_course_api_controller_exists()
    {
        $this->assertTrue(class_exists(CourseApiController::class));
    }

    public function test_get_courses_method_exists()
    {
        $this->assertTrue(method_exists(CourseApiController::class, 'getCourses'));
    }

    public function test_get_course_method_exists()
    {
        $this->assertTrue(method_exists(CourseApiController::class, 'getCourse'));
    }

    public function test_controller_extends_base_controller()
    {
        $controller = new CourseApiController();
        $this->assertInstanceOf(\App\Http\Controllers\Controller::class, $controller);
    }
}
