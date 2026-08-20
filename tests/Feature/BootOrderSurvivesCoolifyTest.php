<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Coolify marks the container Exited when HTTP is down during migrate/cache.
 * Nginx must listen before any artisan command that can block on the database.
 */
class BootOrderSurvivesCoolifyTest extends TestCase
{
    public function test_start_script_execs_supervisord_before_any_migrate(): void
    {
        $start = $this->readBackendFile('docker/start.sh');
        $execPos = strpos($start, 'exec');
        $migratePos = strpos($start, 'artisan migrate');

        $this->assertNotFalse($execPos, 'start.sh must exec supervisord as PID 1.');
        $this->assertTrue(
            $migratePos === false || $migratePos > $execPos,
            'Blocking migrate before nginx starts makes Coolify kill the container.',
        );
    }

    public function test_migrations_run_as_a_oneshot_that_cannot_kill_pid1(): void
    {
        $supervisor = $this->readBackendFile('docker/supervisor/supervisord.conf');

        $this->assertStringContainsString('artisan migrate --force', $supervisor);
        $this->assertMatchesRegularExpression(
            '/\[program:laravel-boot\][\s\S]*autorestart\s*=\s*false/',
            $supervisor,
        );
    }

    public function test_queue_workers_start_after_laravel_boot_not_at_container_start(): void
    {
        $supervisor = $this->readBackendFile('docker/supervisor/supervisord.conf');

        foreach (['default-worker', 'ingestion-worker', 'video-worker', 'scheduler'] as $program) {
            $this->assertMatchesRegularExpression(
                '/\[program:'.$program.'\][\s\S]*?autostart\s*=\s*false/',
                $supervisor,
                $program.' must wait for laravel-boot so migrate and cache do not compete for RAM.',
            );
        }

        $this->assertStringContainsString(
            'supervisorctl start default-worker:* ingestion-worker:* video-worker:* scheduler',
            $supervisor,
        );
        $this->assertStringContainsString('php artisan route:cache', $supervisor);
    }

    public function test_healthcheck_does_not_depend_on_http_or_runtime_port(): void
    {
        $script = $this->readBackendFile('docker/healthcheck.sh');

        $this->assertStringContainsString('kill -0 1', $script);
        $this->assertStringContainsString('php-fpm', $script);
        $this->assertStringContainsString('nginx', $script);
        $this->assertStringNotContainsString('curl', $script);
        $this->assertStringNotContainsString('PORT', $script);
    }

    public function test_fpm_pool_is_capped_so_small_vps_does_not_oomkill(): void
    {
        $fpm = $this->readBackendFile('docker/php/zz-skillso-fpm.conf');
        $this->assertMatchesRegularExpression('/pm\.max_children\s*=\s*[1-8]\b/', $fpm);
    }

    private function readBackendFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
