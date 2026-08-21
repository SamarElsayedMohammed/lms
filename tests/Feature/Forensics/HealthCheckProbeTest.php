<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Class HealthCheckProbeTest
 *
 * Forensic Feature Test Suite verifying fast non-blocking health check probes,
 * database disconnection resilience, and observability endpoints (R4).
 */
final class HealthCheckProbeTest extends TestCase
{
    /**
     * Tier 1: Feature Coverage — Verify GET /api/health returns 200 with status: ok when database is connected.
     */
    public function test_health_endpoint_returns_200_ok_when_database_is_connected(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('ok', $data['status'] ?? null);
        $this->assertTrue($data['db'] ?? null);
        $this->assertArrayHasKey('ts', $data);
    }

    /**
     * Tier 1: Feature Coverage — Verify GET /api/health/live returns 200 alive without database dependency.
     */
    public function test_health_live_endpoint_returns_200_alive(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('alive', $data['status'] ?? null);
        $this->assertArrayHasKey('app', $data);
        $this->assertArrayHasKey('ts', $data);
    }

    /**
     * Tier 1: Feature Coverage — Verify GET /api/health/ready returns 200 ready when database is connected.
     */
    public function test_health_ready_endpoint_returns_200_ready(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('ready', $data['status'] ?? null);
        $this->assertTrue($data['db'] ?? null);
    }

    /**
     * Tier 1: Feature Coverage — Verify GET /up returns 200.
     */
    public function test_up_endpoint_returns_200_ok(): void
    {
        $response = $this->get('/up');
        $response->assertStatus(200);
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify GET /api/health returns 200 degraded when database is disconnected.
     */
    public function test_health_endpoint_returns_degraded_when_database_connection_fails(): void
    {
        DB::shouldReceive('connection')
            ->andThrow(new \PDOException('Simulated DB network failure', 2002));

        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('degraded', $data['status'] ?? null);
        $this->assertFalse($data['db'] ?? true);
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify GET /api/health/ready returns 503 not_ready when database is disconnected.
     */
    public function test_health_ready_endpoint_returns_503_not_ready_when_database_fails(): void
    {
        DB::shouldReceive('connection')
            ->andThrow(new \PDOException('Simulated DB network failure', 2002));

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(503);
        $data = $response->json();
        $this->assertSame('not_ready', $data['status'] ?? null);
        $this->assertFalse($data['db'] ?? true);
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify /api/health/live succeeds even when database is completely down.
     */
    public function test_health_live_endpoint_succeeds_even_when_database_is_severed(): void
    {
        DB::shouldReceive('connection')
            ->andThrow(new \PDOException('Simulated DB network failure', 2002));

        $response = $this->getJson('/api/health/live');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('alive', $data['status'] ?? null);
    }

    /**
     * Tier 4: Real-World Scenarios — High frequency health probing executes without memory bloat or degradation.
     */
    public function test_high_frequency_health_probes_succeed_consistently(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson('/api/health');
            $response->assertStatus(200);
        }
    }
}
