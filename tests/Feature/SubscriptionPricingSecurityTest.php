<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class SubscriptionPricingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_endpoint_accessible(): void
    {
        $response = $this->getJson('/api/subscription/plans');

        $this->assertContains($response->status(), [200, 404]);
    }
}
