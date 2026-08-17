<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\PaymentMethod;
use App\Models\PromoCode;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\CountryDetectionService;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ZeroTrustSecurityReconciliationTest extends TestCase
{
    private VideoProgressService $videoProgressService;
    private CountryDetectionService $countryDetectionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->videoProgressService = new VideoProgressService(new FeatureFlagService());
        $this->countryDetectionService = new CountryDetectionService();
    }

    /**
     * ZT-01: Canonical duration calculates HMS (hours, minutes, seconds) correctly
     * when duration_seconds is not directly populated.
     */
    public function test_zt01_canonical_duration_calculates_from_hms_fields(): void
    {
        $lecture = new CourseChapterLecture([
            'hours' => 1,
            'minutes' => 30,
            'seconds' => 15,
            'duration_seconds' => 0,
        ]);

        $duration = $this->videoProgressService->getCanonicalDuration($lecture);
        $this->assertSame(5415, $duration); // (1*3600) + (30*60) + 15
    }

    /**
     * ZT-02: Zero canonical duration blocks progress increment & cannot complete.
     */
    public function test_zt02_zero_canonical_duration_blocks_completion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lecture duration is not yet set by the server. Progress tracking is temporarily unavailable.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture([
            'id' => 888,
            'duration_seconds' => 0,
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
        ]);

        $this->videoProgressService->updateSegmentProgress(
            $user,
            $lecture,
            10, // Attempted duration
            10,
            [0],
            []
        );
    }

    /**
     * ZT-03: Client query or body country parameters are rejected in authoritative detection.
     */
    public function test_zt03_query_and_body_country_tampering_is_ignored(): void
    {
        // Malicious client tries to send country_code=EG via query params or request body
        $request = Request::create('/api/v1/subscription/plans?country_code=EG&country=EG', 'POST', [
            'country_code' => 'EG',
            'country' => 'EG',
        ]);

        // When header X-User-Country=SA is present, it takes SA
        $request->headers->set('X-User-Country', 'SA');
        $this->assertSame('SA', $this->countryDetectionService->detect($request));

        // When no headers are sent, query params cannot override default (EG)
        $requestNoHeaders = Request::create('/api/v1/subscription/plans?country_code=US', 'GET');
        $this->assertSame('EG', $this->countryDetectionService->detect($requestNoHeaders));
    }

    /**
     * ZT-04: Cloudflare header CF-IPCountry takes priority over proxy header X-User-Country.
     */
    public function test_zt04_cloudflare_cf_ipcountry_takes_priority(): void
    {
        $request = Request::create('/api/v1/subscription/plans', 'GET');
        $request->headers->set('CF-IPCountry', 'KW');
        $request->headers->set('X-User-Country', 'SA');

        $this->assertSame('KW', $this->countryDetectionService->detect($request));
    }

    /**
     * ZT-05: PaymentMethod soft delete trait is registered and active on model.
     */
    public function test_zt05_payment_method_model_uses_soft_deletes(): void
    {
        $traits = class_uses_recursive(PaymentMethod::class);
        $this->assertArrayHasKey(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
    }

    /**
     * ZT-06: Video anti-cheat duration shrink spoofing rejection.
     */
    public function test_zt06_duration_shrink_spoofing_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The reported video duration cannot shrink canonical lecture duration.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture([
            'id' => 888,
            'duration_seconds' => 3600,
        ]);

        $this->videoProgressService->updateSegmentProgress(
            $user,
            $lecture,
            10, // Trying to shrink 3600s lecture to 10s to claim completion
            10,
            [0],
            []
        );
    }

    /**
     * ZT-07: PromoCode model soft delete trait is registered and active.
     */
    public function test_zt07_promo_code_model_uses_soft_deletes(): void
    {
        $traits = class_uses_recursive(PromoCode::class);
        $this->assertArrayHasKey(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
    }
}
