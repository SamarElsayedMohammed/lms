<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final class FFmpegService
{
    private const string CACHE_KEY = 'ffmpeg_available';
    private const int CACHE_TTL_SECONDS = 3600; // 1 hour

    /**
     * Check if proc_open function is available
     * Required for running FFmpeg via Symfony Process
     */
    public static function isProcOpenAvailable(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Check if FFmpeg is available on the server
     * Result is cached for 1 hour to avoid repeated system calls
     * Also checks if proc_open is available (required for running FFmpeg)
     */
    public static function isAvailable(): bool
    {
        // First check if proc_open is available
        if (!self::isProcOpenAvailable()) {
            Log::info('proc_open function is disabled - HLS encoding not available');
            Cache::put(self::CACHE_KEY, false, self::CACHE_TTL_SECONDS);
            return false;
        }

        // Check cache first
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return (bool) $cached;
        }

        // Try to find ffmpeg by attempting execution at common paths
        // This approach works even with open_basedir restrictions
        // because proc_open can execute binaries even when file_exists() is blocked
        $commonPaths = [
            'ffmpeg', // Try PATH first
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/bin/ffmpeg',
            '/opt/bin/ffmpeg',
        ];

        $ffmpegPath = null;

        foreach ($commonPaths as $path) {
            try {
                $process = new Process([$path, '-version']);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    $ffmpegPath = $path;
                    break;
                }
            } catch (\Throwable $e) {
                // Continue trying other paths
                continue;
            }
        }

        if ($ffmpegPath === null) {
            Log::info('FFmpeg not found in any common paths or PATH', [
                'tried_paths' => $commonPaths,
            ]);
            Cache::put(self::CACHE_KEY, false, self::CACHE_TTL_SECONDS);
            return false;
        }

        // Verify FFmpeg works and get version
        try {
            $process = new Process([$ffmpegPath, '-version']);
            $process->setTimeout(5);
            $process->run();

            $isAvailable = $process->isSuccessful();

            if ($isAvailable) {
                Log::info('FFmpeg is available', [
                    'path' => $ffmpegPath,
                    'version' => self::extractVersion($process->getOutput()),
                ]);
            } else {
                Log::warning('FFmpeg found but not working', [
                    'path' => $ffmpegPath,
                    'error' => $process->getErrorOutput(),
                ]);
            }

            Cache::put(self::CACHE_KEY, $isAvailable, self::CACHE_TTL_SECONDS);
            return $isAvailable;
        } catch (\Throwable $e) {
            Log::error('FFmpeg availability check failed', [
                'error' => $e->getMessage(),
            ]);
            Cache::put(self::CACHE_KEY, false, self::CACHE_TTL_SECONDS);
            return false;
        }
    }

    /**
     * Get FFmpeg path if available
     */
    public static function getPath(): null|string
    {
        if (!self::isAvailable()) {
            return null;
        }

        // Try common paths directly (same as isAvailable())
        $commonPaths = [
            'ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/bin/ffmpeg',
            '/opt/bin/ffmpeg',
        ];

        foreach ($commonPaths as $path) {
            try {
                $process = new Process([$path, '-version']);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    return $path;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Clear the availability cache (useful for testing or after installing FFmpeg)
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('FFmpeg availability cache cleared');
    }

    /**
     * Extract version from FFmpeg output
     */
    private static function extractVersion(string $output): null|string
    {
        // FFmpeg output typically starts with "ffmpeg version X.Y.Z"
        if (preg_match('/ffmpeg version ([^\s]+)/', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get FFmpeg status information
     *
     * @return array{available: bool, proc_open_available: bool, path: null|string, version: null|string, cached: bool, missing_requirements: array<string>}
     */
    public static function getStatus(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $procOpenAvailable = self::isProcOpenAvailable();
        $isAvailable = self::isAvailable();
        $path = self::getPath();
        $missingRequirements = [];

        // Check what's missing
        if (!$procOpenAvailable) {
            $missingRequirements[] = 'proc_open function (PHP configuration)';
        }

        if ($procOpenAvailable && $path === null) {
            $missingRequirements[] = 'FFmpeg binary (not installed or not in PATH)';
        }

        $version = null;
        if ($isAvailable && $path !== null) {
            try {
                $process = new Process([$path, '-version']);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    $version = self::extractVersion($process->getOutput());
                }
            } catch (\Throwable $e) {
                // Ignore errors when getting version
            }
        }

        return [
            'available' => $isAvailable,
            'proc_open_available' => $procOpenAvailable,
            'path' => $path,
            'version' => $version,
            'cached' => $cached !== null,
            'missing_requirements' => $missingRequirements,
        ];
    }
}
