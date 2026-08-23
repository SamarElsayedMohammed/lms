<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CodeLevelStabilityClosureTest extends TestCase
{
    public function test_ffmpeg_does_not_buffer_multi_hour_process_output_and_cleans_partial_files(): void
    {
        $source = $this->source('app/Jobs/EncodeVideoToHLS.php');

        $this->assertStringContainsString('$process->disableOutput();', $source);
        $this->assertStringNotContainsString('$process->getOutput()', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'deleteDirectory($hlsDir)'));
    }

    public function test_subscription_expiry_scan_is_lazy(): void
    {
        $source = $this->source('app/Services/SubscriptionService.php');
        $start = strpos($source, 'function getSubscriptionsForNotificationDays');
        $end = strpos($source, 'function markNotifiedDynamic', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $method = substr($source, $start, $end - $start);
        $this->assertStringContainsString('->lazyById(100)', $method);
        $this->assertStringNotContainsString('->get()', $method);
    }

    public function test_kashier_diagnostics_use_the_rotating_log_channel(): void
    {
        foreach ([
            'app/Http/Controllers/KashierController.php',
            'app/Services/Payment/KashierCheckoutService.php',
        ] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString("Log::channel('daily')->info", $source, $path);
            $this->assertStringNotContainsString("file_put_contents(storage_path('logs/kashier.log')", $source, $path);
        }
    }

    public function test_legacy_report_summaries_do_not_hydrate_all_matching_rows(): void
    {
        $source = $this->source('app/Http/Controllers/ReportsController.php');

        $enrollment = $this->method($source, 'getEnrollmentReportData', 'exportEnrollmentReport');
        $this->assertStringNotContainsString('$allEnrollments = $query->get()', $enrollment);
        $this->assertStringContainsString('->paginate(', $enrollment);

        $revenue = $this->method($source, 'getRevenueSummaryData', 'getRevenueChartData');
        $this->assertStringNotContainsString('$orders = $query->get()', $revenue);
        $this->assertStringContainsString('->lazyById(200)', $revenue);
    }

    public function test_order_validation_rolls_back_after_acquiring_the_user_lock(): void
    {
        $source = $this->source('app/Http/Controllers/API/OrderApiController.php');

        $this->assertGreaterThanOrEqual(4, substr_count($source, 'DB::rollBack();'));
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }

    private function method(string $source, string $method, string $nextMethod): string
    {
        $start = strpos($source, "function {$method}");
        $end = strpos($source, "function {$nextMethod}", $start ?: 0);
        $this->assertNotFalse($start, $method);
        $this->assertNotFalse($end, $nextMethod);

        return substr($source, $start, $end - $start);
    }
}
