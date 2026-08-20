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

    public function test_healthcheck_tries_coolify_port_then_80(): void
    {
        $script = $this->readBackendFile('docker/healthcheck.sh');

        $this->assertStringContainsString('${PORT:-80}', $script);
        $this->assertStringContainsString('try_live 80', $script);
        $this->assertStringNotContainsString('exec curl', $script);
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
