<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_instructor_catalog_returns_an_empty_page_when_no_instructors_exist(): void
    {
        $this->getJson('/api/get-instructors?page=1&per_page=15')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 0);
    }
}
