<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * File logs vanish on every Coolify restart unless they go to stderr + laravel.log
 * and boot scripts never truncate storage/logs.
 */
class PersistentApplicationLogsTest extends TestCase
{
    public function test_default_stack_writes_stderr_and_stable_laravel_log(): void
    {
        $channels = config('logging.channels.stack.channels');

        $this->assertContains('stderr', $channels);
        $this->assertContains('single', $channels);
        $this->assertSame(storage_path('logs/laravel.log'), config('logging.channels.single.path'));
    }

    public function test_boot_scripts_never_delete_or_truncate_log_files(): void
    {
        $scripts = [
            $this->readBackendFile('docker/start.sh'),
            $this->readBackendFile('nixpacks.toml'),
        ];

        foreach ($scripts as $script) {
            $this->assertDoesNotMatchRegularExpression(
                '/rm\s+(-[a-zA-Z]+\s+)*\/.*(storage\/logs|laravel\.log)/',
                $script,
                'Boot must not delete Laravel log files.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(?:truncate|:|>|tee)\s+.*laravel\.log/',
                $script,
                'Boot must not truncate laravel.log. touch is allowed; redirect wipe is not.',
            );
        }
    }

    public function test_image_declares_a_logs_volume_so_restarts_keep_files(): void
    {
        $dockerfile = $this->readBackendFile('Dockerfile');

        $this->assertMatchesRegularExpression(
            '/^VOLUME\s+\[?\s*"\/var\/www\/html\/storage\/logs"/m',
            $dockerfile,
            'Without a volume, every container replace wipes storage/logs.',
        );
    }

    private function readBackendFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
