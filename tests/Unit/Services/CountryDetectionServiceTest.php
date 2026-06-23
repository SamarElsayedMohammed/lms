<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CountryDetectionService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CountryDetectionServiceTest extends TestCase
{
    private CountryDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CountryDetectionService::class);
    }

    public function test_detects_x_user_country_header(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-User-Country', 'SA');

        $this->assertSame('SA', $this->service->detect($request));
    }

    public function test_detects_x_country_header_as_fallback(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Country', 'AE');

        $this->assertSame('AE', $this->service->detect($request));
    }

    public function test_x_user_country_takes_priority_over_x_country(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-User-Country', 'SA');
        $request->headers->set('X-Country', 'AE');

        $this->assertSame('SA', $this->service->detect($request));
    }

    public function test_detects_cf_ipcountry_header(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('CF-IPCountry', 'US');

        $this->assertSame('US', $this->service->detect($request));
    }
}
