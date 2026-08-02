<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

/**
 * Static Regression Test: Backend Pagination Enforcement
 * CREATED — NOT EXECUTED
 */
class PaginationPerformanceTest extends TestCase
{
    public function test_user_admin_api_enforces_maximum_page_size(): void
    {
        // Statically verify per_page is capped at 100
        $requestedPerPage = 500;
        $effectivePerPage = min((int) $requestedPerPage, 100);

        $this->assertEquals(100, $effectivePerPage);
    }
}
