<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class RuntimeForensicCaptureScriptTest extends TestCase
{
    public function test_capture_script_preserves_all_matching_containers_and_incident_evidence(): void
    {
        $script = file_get_contents(base_path('scripts/capture-runtime-health.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('docker ps -aq --no-trunc', $script);
        $this->assertStringNotContainsString('head -n 1', $script);
        $this->assertStringContainsString("journalctl -k --since '6 hours ago'", $script);
        $this->assertStringContainsString('docker events --filter type=container', $script);
        $this->assertStringContainsString("docker inspect --format '{{json .State.Health}}'", $script);
        $this->assertStringContainsString('skillso:runtime-snapshot --no-interaction', $script);
        $this->assertStringContainsString('timeout 15s docker exec', $script);
        $this->assertStringContainsString('redact_stream', $script);
        $this->assertStringContainsString('probe-url must not contain credentials or a query string', $script);
    }

    public function test_runtime_snapshot_uses_read_only_dependency_checks_and_redacts_errors(): void
    {
        $command = file_get_contents(base_path('app/Console/Commands/SkillsoRuntimeSnapshotCommand.php'));

        $this->assertIsString($command);
        $this->assertStringContainsString("DB::select('SELECT 1')", $command);
        $this->assertStringContainsString("command('ping')", $command);
        $this->assertStringContainsString('PDO::ATTR_TIMEOUT', $command);
        $this->assertStringNotContainsString('Cache::put(', $command);
        $this->assertStringNotContainsString('->getMessage()', $command);
    }
}
