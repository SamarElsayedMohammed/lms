<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LegacyReportsCrashStabilityTest extends TestCase
{
    public function test_legacy_report_controller_defines_hard_pdf_and_csv_limits(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('private const PDF_EXPORT_LIMIT = 500;', $source);
        $this->assertStringContainsString('private const CSV_EXPORT_LIMIT = 5000;', $source);
    }

    #[DataProvider('exportMethodProvider')]
    public function test_every_legacy_export_checks_the_limit_before_hydrating_rows(string $method): void
    {
        $methodSource = $this->methodSource($method);

        $this->assertStringContainsString(
            'getBoundedExportRows($query',
            $methodSource,
            "{$method} must reject oversized exports before calling get().",
        );
    }

    public function test_legacy_sales_detail_uses_database_pagination(): void
    {
        $methodSource = $this->methodSource('getDetailedSalesData');

        $this->assertStringContainsString('->paginate(', $methodSource);
        $this->assertStringNotContainsString('->slice(', $methodSource);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exportMethodProvider(): array
    {
        return [
            'sales' => ['exportSalesReport'],
            'commission' => ['exportCommissionReport'],
            'course' => ['exportCourseReport'],
            'instructor' => ['exportInstructorReport'],
            'enrollment' => ['exportEnrollmentReport'],
            'revenue' => ['exportRevenueReport'],
        ];
    }

    private function controllerSource(): string
    {
        $source = file_get_contents(app_path('Http/Controllers/ReportsController.php'));
        $this->assertIsString($source);

        return $source;
    }

    private function methodSource(string $method): string
    {
        $source = $this->controllerSource();
        $start = strpos($source, "function {$method}(");
        $this->assertNotFalse($start, "Missing {$method}.");
        $nextMethod = strpos($source, "\n    private function ", $start + 1);
        if ($nextMethod === false) {
            $nextMethod = strpos($source, "\n    public function ", $start + 1);
        }

        return substr($source, $start, $nextMethod === false ? null : $nextMethod - $start);
    }
}
