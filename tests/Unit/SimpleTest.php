<?php

namespace Tests\Unit;

use Tests\TestCase;

class SimpleTest extends TestCase
{
    public function test_basic_assertion()
    {
        $this->assertTrue(true);
    }

    public function test_math_operations()
    {
        $this->assertEquals(4, 2 + 2);
        $this->assertEquals(6, 3 * 2);
        $this->assertEquals(2, 4 / 2);
    }

    public function test_string_operations()
    {
        $string = 'Hello World';
        $this->assertEquals('Hello World', $string);
        $this->assertStringContainsString('World', $string);
        $this->assertStringStartsWith('Hello', $string);
    }

    public function test_array_operations()
    {
        $array = [1, 2, 3, 4, 5];
        $this->assertCount(5, $array);
        $this->assertContains(3, $array);
        $this->assertNotContains(6, $array);
    }

    public function test_boolean_operations()
    {
        $this->assertTrue(true);
        $this->assertFalse(false);
        $this->assertNotNull('not null');
        $this->assertNull(null);
    }
}
