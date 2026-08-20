<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\ReportingPeriodService;
use Carbon\Carbon;
use Tests\TestCase;

final class ReportingPeriodServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_this_year_and_last_30_days_use_application_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 15:00:00', 'UTC'));

        $service = new ReportingPeriodService();
        $year = $service->resolve(['preset' => 'this_year']);
        $month = $service->resolve(['preset' => '30d']);

        $this->assertSame('UTC', $year->timezone);
        $this->assertSame('2026-01-01 00:00:00', $year->start->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 23:59:59', $year->end->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-22 00:00:00', $month->start->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame($year->timezone, $month->timezone);
    }

    public function test_dashboard_aliases_map_to_the_same_windows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $service = new ReportingPeriodService();

        $canonical = $service->resolve(['preset' => '7d']);
        $alias = $service->resolve(['preset' => 'last_7_days']);

        $this->assertTrue($canonical->start->equalTo($alias->start));
        $this->assertTrue($canonical->end->equalTo($alias->end));
    }
}
