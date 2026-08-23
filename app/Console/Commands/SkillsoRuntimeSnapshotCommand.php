<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class SkillsoRuntimeSnapshotCommand extends Command
{
    protected $signature = 'skillso:runtime-snapshot';

    protected $description = 'Capture non-secret runtime diagnostic metrics for production stability observability';

    public function handle(): int
    {
        $this->boundDiagnosticConnectionTimeouts();

        $this->info('=== SKILLSO RUNTIME HEALTH SNAPSHOT ===');
        $this->line('Timestamp: '.now()->toIso8601String());
        $this->line('PHP Version: '.PHP_VERSION);
        $this->line('Laravel Version: '.app()->version());
        $this->line('Environment: '.app()->environment());

        // Memory
        $currentMem = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $this->line("Memory Usage: {$currentMem} MB (Peak: {$peakMem} MB)");

        // Database
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            $this->info("Database: OK ({$latency} ms latency, driver: ".DB::getDriverName().')');
        } catch (\Throwable $exception) {
            $this->error('Database: UNREACHABLE ('.$exception::class.')');
        }

        // Cache read. Deliberately avoids writing a probe key in production.
        try {
            Cache::get('skillso_runtime_snapshot_read_only_probe');
            $this->line('Cache Driver: '.config('cache.default').' [READ OK]');
        } catch (\Throwable $exception) {
            $this->error('Cache: FAIL ('.$exception::class.')');
        }

        // Redis topology/connectivity without printing hostnames or credentials.
        try {
            $pong = Redis::connection()->command('ping');
            $redisOk = $pong === true || strtoupper((string) $pong) === 'PONG';
            $this->line('Redis: '.($redisOk ? 'OK' : 'UNEXPECTED RESPONSE'));
        } catch (\Throwable $exception) {
            $this->error('Redis: UNREACHABLE ('.$exception::class.')');
        }

        // Queue
        $this->line('Queue Connection: '.config('queue.default'));

        // Storage & Log Permissions
        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            $logSize = round(File::size($logPath) / 1024, 2);
            $perms = substr(sprintf('%o', fileperms($logPath)), -4);
            $this->line("Log File: {$logSize} KB (Mode: {$perms})");
        } else {
            $this->line('Log File: Not yet created');
        }

        $this->info('=== SNAPSHOT COMPLETED ===');

        return 0;
    }

    /**
     * Keep an unavailable dependency from stalling host snapshots. These
     * process-local overrides do not alter application or production config.
     */
    private function boundDiagnosticConnectionTimeouts(): void
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (is_array($connection)) {
            $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
            $options[\PDO::ATTR_TIMEOUT] = 3;
            config(["database.connections.{$connectionName}.options" => $options]);
        }

        $redis = config('database.redis');
        if (! is_array($redis)) {
            return;
        }

        foreach ($redis as $name => $configuration) {
            if (! is_string($name) || ! is_array($configuration)) {
                continue;
            }

            config([
                "database.redis.{$name}.timeout" => 3,
                "database.redis.{$name}.read_timeout" => 3,
            ]);
        }
    }
}
