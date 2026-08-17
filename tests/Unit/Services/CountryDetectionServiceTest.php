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

    public function test_cloudflare_cf_ipcountry_takes_priority_over_all_other_headers(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('CF-IPCountry', 'EG');
        $request->headers->set('X-User-Country', 'SA');
        $request->headers->set('X-Vercel-IP-Country', 'AE');

        $this->assertSame('EG', $this->service->detect($request));
    }

    public function test_ignores_country_code_query_parameter_tampering(): void
    {
        $request = Request::create('/?country_code=US', 'GET', ['country_code' => 'US']);
        $request->headers->set('CF-IPCountry', 'SA');

        $this->assertSame('SA', $this->service->detect($request));

        // When no headers are present, query param is still ignored, defaulting to EG
        $requestNoHeaders = Request::create('/?country_code=US', 'GET', ['country_code' => 'US']);
        $this->assertSame('EG', $this->service->detect($requestNoHeaders));
    }

    public function test_sanitizes_invalid_country_codes(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('CF-IPCountry', 'XX'); // Cloudflare unknown code
        $request->headers->set('X-User-Country', 'SA');

        $this->assertSame('SA', $this->service->detect($request));
    }
}
