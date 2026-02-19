<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Skip database setup for now due to SQLite VACUUM issues
        // $this->artisan('migrate:fresh');
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
