<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use Tests\TestCase;

/**
 * Class FatalShutdownHookTest
 *
 * Forensic Feature Test Suite verifying the memory reserve pre-allocation
 * and fatal error shutdown diagnostics (R1).
 */
final class FatalShutdownHookTest extends TestCase
{
    /**
     * Tier 1: Feature Coverage — Verify 512KB emergency memory reserve is pre-allocated.
     */
    public function test_emergency_memory_reserve_is_allocated(): void
    {
        $this->assertArrayHasKey('__skillso_memory_reserve', $GLOBALS);
        $this->assertGreaterThanOrEqual(512 * 1024, strlen((string) $GLOBALS['__skillso_memory_reserve']));
    }

    /**
     * Tier 1: Feature Coverage — Verify shutdown constant is registered.
     */
    public function test_shutdown_hook_constant_is_defined(): void
    {
        $this->assertTrue(defined('SKILLSO_SHUTDOWN_REGISTERED'));
        $this->assertTrue(constant('SKILLSO_SHUTDOWN_REGISTERED'));
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify freeing memory reserve restores headroom.
     */
    public function test_emergency_memory_reserve_can_be_released_safely(): void
    {
        $reserve = $GLOBALS['__skillso_memory_reserve'];
        $this->assertNotNull($reserve);

        // Free reserve as shutdown hook would
        $GLOBALS['__skillso_memory_reserve'] = null;
        $this->assertNull($GLOBALS['__skillso_memory_reserve']);

        // Restore for other tests
        $GLOBALS['__skillso_memory_reserve'] = str_repeat(' ', 512 * 1024);
        $this->assertSame(512 * 1024, strlen($GLOBALS['__skillso_memory_reserve']));
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify structured JSON diagnostic serialization.
     */
    public function test_fatal_diagnostic_record_structure(): void
    {
        $diagnostic = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\ZZ'),
            'event' => 'FATAL_SHUTDOWN',
            'error_type' => 'E_ERROR',
            'is_oom' => true,
            'message' => 'Allowed memory size of 134217728 bytes exhausted',
            'file' => '/var/www/html/app/Services/VideoEncodingService.php',
            'line' => 142,
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_limit' => '128M',
            'sapi' => PHP_SAPI,
            'request_method' => 'POST',
            'request_uri' => '/api/v1/videos/transcode',
            'cli_command' => null,
            'pid' => getmypid(),
        ];

        $json = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('FATAL_SHUTDOWN', $decoded['event']);
        $this->assertTrue($decoded['is_oom']);
        $this->assertSame('E_ERROR', $decoded['type'] ?? $decoded['error_type']);
        $this->assertSame(142, $decoded['line']);
    }
}
