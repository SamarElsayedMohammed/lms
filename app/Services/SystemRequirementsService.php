<?php

declare(strict_types=1);

namespace App\Services;

/**
 * System Requirements Checker
 *
 * Use this service in your installer to check system requirements
 *
 * Example usage in installer:
 * $requirements = SystemRequirementsService::check();
 * foreach ($requirements['core'] as $req) {
 *     echo $req['name'] . ': ' . ($req['passed'] ? 'OK' : 'FAILED');
 * }
 */
final class SystemRequirementsService
{
    /**
     * Check all system requirements (core + optional)
     *
     * @return array{core: array<int, array{name: string, passed: bool, required: bool, message: string, type: string}>, optional: array<int, array{name: string, passed: bool, required: bool, message: string, type: string, impact: string}>}
     */
    public static function check(): array
    {
        return [
            'core' => self::checkCoreRequirements(),
            'optional' => self::checkOptionalRequirements(),
        ];
    }

    /**
     * Check core requirements (must have for basic functionality)
     *
     * @return array<int, array{name: string, passed: bool, required: bool, message: string, type: string}>
     */
    public static function checkCoreRequirements(): array
    {
        return [
            [
                'name' => 'PHP Version',
                'passed' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'required' => true,
                'message' => 'PHP 8.3 or higher is required. Current: ' . PHP_VERSION,
                'type' => 'php',
            ],
            [
                'name' => 'BCMath Extension',
                'passed' => extension_loaded('bcmath'),
                'required' => true,
                'message' => 'BCMath PHP extension is required for precise calculations',
                'type' => 'php_extension',
            ],
            [
                'name' => 'Ctype Extension',
                'passed' => extension_loaded('ctype'),
                'required' => true,
                'message' => 'Ctype PHP extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'cURL Extension',
                'passed' => extension_loaded('curl'),
                'required' => true,
                'message' => 'cURL PHP extension is required for HTTP requests',
                'type' => 'php_extension',
            ],
            [
                'name' => 'DOM Extension',
                'passed' => extension_loaded('dom'),
                'required' => true,
                'message' => 'DOM PHP extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'Fileinfo Extension',
                'passed' => extension_loaded('fileinfo'),
                'required' => true,
                'message' => 'Fileinfo PHP extension is required for file type detection',
                'type' => 'php_extension',
            ],
            [
                'name' => 'JSON Extension',
                'passed' => extension_loaded('json'),
                'required' => true,
                'message' => 'JSON PHP extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'Mbstring Extension',
                'passed' => extension_loaded('mbstring'),
                'required' => true,
                'message' => 'Mbstring PHP extension is required for Unicode string handling',
                'type' => 'php_extension',
            ],
            [
                'name' => 'OpenSSL Extension',
                'passed' => extension_loaded('openssl'),
                'required' => true,
                'message' => 'OpenSSL PHP extension is required for encryption',
                'type' => 'php_extension',
            ],
            [
                'name' => 'PDO Extension',
                'passed' => extension_loaded('pdo'),
                'required' => true,
                'message' => 'PDO PHP extension is required for database access',
                'type' => 'php_extension',
            ],
            [
                'name' => 'PDO MySQL Extension',
                'passed' => extension_loaded('pdo_mysql'),
                'required' => true,
                'message' => 'PDO MySQL extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'Tokenizer Extension',
                'passed' => extension_loaded('tokenizer'),
                'required' => true,
                'message' => 'Tokenizer PHP extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'XML Extension',
                'passed' => extension_loaded('xml'),
                'required' => true,
                'message' => 'XML PHP extension is required',
                'type' => 'php_extension',
            ],
            [
                'name' => 'GD Extension',
                'passed' => extension_loaded('gd'),
                'required' => true,
                'message' => 'GD PHP extension is required for image manipulation',
                'type' => 'php_extension',
            ],
            [
                'name' => 'ZIP Extension',
                'passed' => extension_loaded('zip'),
                'required' => true,
                'message' => 'ZIP PHP extension is required for archive handling',
                'type' => 'php_extension',
            ],
        ];
    }

    /**
     * Check optional requirements (enhanced features)
     *
     * @return array<int, array{name: string, passed: bool, required: bool, message: string, type: string, impact: string}>
     */
    public static function checkOptionalRequirements(): array
    {
        $ffmpegStatus = FFmpegService::getStatus();

        return [
            [
                'name' => 'proc_open Function',
                'passed' => FFmpegService::isProcOpenAvailable(),
                'required' => false,
                'message' => FFmpegService::isProcOpenAvailable()
                    ? 'proc_open is enabled'
                    : 'proc_open is disabled in PHP configuration (disable_functions)',
                'type' => 'php_function',
                'impact' => 'Required for HLS video encoding. Without it, videos will use direct streaming (less secure).',
            ],
            [
                'name' => 'FFmpeg',
                'passed' => $ffmpegStatus['available'],
                'required' => false,
                'message' => $ffmpegStatus['available']
                    ? 'FFmpeg is installed: ' . ($ffmpegStatus['version'] ?? 'unknown version')
                    : 'FFmpeg is not installed or not in system PATH',
                'type' => 'system_binary',
                'impact' => 'Required for HLS video encoding. Without it, videos will use direct streaming (less secure).',
            ],
        ];
    }

    /**
     * Get a summary of requirements status
     *
     * @return array{core_passed: bool, optional_passed: bool, core_failed_count: int, optional_failed_count: int}
     */
    public static function getSummary(): array
    {
        $requirements = self::check();

        $coreFailedCount = 0;
        $optionalFailedCount = 0;

        foreach ($requirements['core'] as $req) {
            if (!$req['passed']) {
                $coreFailedCount++;
            }
        }

        foreach ($requirements['optional'] as $req) {
            if (!$req['passed']) {
                $optionalFailedCount++;
            }
        }

        return [
            'core_passed' => $coreFailedCount === 0,
            'optional_passed' => $optionalFailedCount === 0,
            'core_failed_count' => $coreFailedCount,
            'optional_failed_count' => $optionalFailedCount,
        ];
    }
}
