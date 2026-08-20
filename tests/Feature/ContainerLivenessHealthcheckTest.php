<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Prevents Coolify/Docker from restarting the API container on a loop.
 *
 * Liveness must not go through PHP-FPM (pool saturation looks like a dead app).
 * It must honor runtime PORT (Coolify). It must not use /api/health/ready (503 on DB blip).
 */
class ContainerLivenessHealthcheckTest extends TestCase
{
    public function test_live_endpoint_returns_200_without_touching_the_database(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'alive');
    }

    public function test_dockerfile_healthcheck_uses_pid1_liveness_script(): void
    {
        $dockerfile = $this->readBackendFile('Dockerfile');

        $this->assertMatchesRegularExpression(
            '/HEALTHCHECK[\s\S]*CMD\s+\/usr\/local\/bin\/healthcheck\.sh/',
            $dockerfile,
            'HEALTHCHECK must call healthcheck.sh so PORT is read at runtime, not image-build time.',
        );

        preg_match('/^HEALTHCHECK[\s\S]*?(?=\n[A-Z]|\z)/m', $dockerfile, $healthcheck) || $this->fail('Dockerfile is missing HEALTHCHECK.');
        $this->assertStringNotContainsString(
            '/api/health/ready',
            $healthcheck[0],
            'Readiness 503 on a DB blip must not restart the container.',
        );
    }

    public function test_healthcheck_script_only_checks_container_pid1(): void
    {
        $script = $this->readBackendFile('docker/healthcheck.sh');

        $this->assertStringContainsString('kill -0 1', $script);
        $this->assertStringNotContainsString('curl', $script);
        $this->assertStringNotContainsString('${PORT:-80}', $script);
        $this->assertStringNotContainsString('/api/health/ready', $script);
    }

    public function test_nginx_serves_liveness_without_php_fpm(): void
    {
        $this->assertNginxLivenessBypassesPhp($this->readBackendFile('docker/nginx/nginx.conf'));
        $this->assertNginxLivenessBypassesPhp($this->readBackendFile('nixpacks.toml'));
    }

    private function assertNginxLivenessBypassesPhp(string $config): void
    {
        $this->assertMatchesRegularExpression(
            '/location\s+=\s+\/api\/health\/live\s*\{[^}]*return\s+200/',
            $config,
            'nginx must answer /api/health/live itself so a full FPM pool cannot fail liveness.',
        );

        if (preg_match('/location\s+=\s+\/api\/health\/live\s*\{([^}]*)\}/s', $config, $match) !== 1) {
            $this->fail('Missing exact nginx location for /api/health/live.');
        }

        $this->assertStringNotContainsString(
            'fastcgi_pass',
            $match[1],
            'Liveness location must not proxy to PHP-FPM.',
        );
    }

    private function readBackendFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
