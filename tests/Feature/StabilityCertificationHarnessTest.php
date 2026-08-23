<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class StabilityCertificationHarnessTest extends TestCase
{
    public function test_load_harness_is_rate_limited_and_refuses_known_production_by_default(): void
    {
        $source = $this->source('scripts/stability/stability-load.php');

        $this->assertStringContainsString('$knownProduction', $source);
        $this->assertStringContainsString("'allow-production'", $source);
        $this->assertStringContainsString('$rate = max(0.1, min(100.0', $source);
        $this->assertStringContainsString('$concurrency = max(1, min(64', $source);
        $this->assertStringContainsString('$duration = max(1, min(259200', $source);
        $this->assertStringContainsString('CURLOPT_CONNECTTIMEOUT => 3', $source);
        $this->assertStringContainsString('CURLOPT_TIMEOUT => 10', $source);
        $this->assertStringContainsString('$has503', $source);
    }

    public function test_docker_sampler_is_read_only(): void
    {
        $source = $this->source('scripts/stability/capture-docker-evidence.sh');

        foreach (['docker inspect', 'docker stats --no-stream', 'docker ps -a', 'docker system df'] as $command) {
            $this->assertStringContainsString($command, $source);
        }
        foreach (['docker restart', 'docker rm', 'docker stop', 'docker system prune', 'docker compose down'] as $command) {
            $this->assertStringNotContainsString($command, $source);
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }
}
