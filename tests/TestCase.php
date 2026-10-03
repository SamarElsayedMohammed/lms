<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    #[\Override]
    protected function setUp(): void
    {
        ini_set('memory_limit', '512M');
        \App\Services\ContentAccessService::flushRequestCache();
        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        \App\Services\ContentAccessService::flushRequestCache();
        parent::tearDown();
    }
}
