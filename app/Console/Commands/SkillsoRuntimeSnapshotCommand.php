<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SkillsoRuntimeSnapshotCommand extends Command
{
    protected $signature = 'skillso:runtime-snapshot';

    protected $description = 'Capture non-secret runtime diagnostic metrics for production stability observability';

    public function handle(): int
    {
        $this->info('=== SKILLSO RUNTIME HEALTH SNAPSHOT ===');
        $this->line('Timestamp: ' . now()->toIso8601String());
        $this->line('PHP Version: ' . PHP_VERSION);
        $this->line('Laravel Version: ' . app()->version());
        $this->line('Environment: ' . app()->environment());

        // Memory
        $currentMem = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $this->line("Memory Usage: {$currentMem} MB (Peak: {$peakMem} MB)");

        // Database
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $start) * 1000, 2);
            $this->info("Database: OK ({$latency} ms latency, driver: " . DB::getDriverName() . ')');
        } catch (\Throwable $e) {
            $this->error('Database: UNREACHABLE (' . $e->getMessage() . ')');
        }

        // Cache
        try {
            $cacheKey = 'skillso_health_probe_' . time();
            Cache::put($cacheKey, true, 10);
            $cacheOk = Cache::get($cacheKey) === true;
            Cache::forget($cacheKey);
            $this->line('Cache Driver: ' . config('cache.default') . ' [' . ($cacheOk ? 'OK' : 'FAIL') . ']');
        } catch (\Throwable $e) {
            $this->error('Cache: FAIL (' . $e->getMessage() . ')');
        }

        // Queue
        $this->line('Queue Connection: ' . config('queue.default'));

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
}
