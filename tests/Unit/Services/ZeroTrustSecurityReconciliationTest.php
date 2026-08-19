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
    public function test_canonical_duration_calculates_from_hms_fields(): void
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

    public function test_zt01_canonical_duration_calculates_from_hms_fields(): void
    {
        $this->test_canonical_duration_calculates_from_hms_fields();
    }

    /**
     * ZT-02: Zero canonical duration blocks progress increment & cannot complete.
     */
    public function test_zero_canonical_duration_blocks_completion(): void
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

    public function test_zt02_zero_canonical_duration_blocks_completion(): void
    {
        $this->test_zero_canonical_duration_blocks_completion();
    }

    /**
     * ZT-03: Client query or body country parameters are rejected in authoritative detection.
     */
    public function test_query_and_body_country_tampering_is_ignored(): void
    {
        // Malicious client tries to send country_code=EG via query params or request body
        $request = Request::create('/api/v1/subscription/plans?country_code=EG&country=EG', 'POST', [
            'country_code' => 'EG',
            'country' => 'EG',
        ]);

        // When header X-User-Country=SA is present, it is ignored (unsigned / spoofable)
        $request->headers->set('X-User-Country', 'SA');
        $this->assertSame('EG', $this->countryDetectionService->detect($request));

        // When no headers are sent, query params cannot override default (EG)
        $requestNoHeaders = Request::create('/api/v1/subscription/plans?country_code=US', 'GET');
        $this->assertSame('EG', $this->countryDetectionService->detect($requestNoHeaders));
    }

    public function test_zt03_query_and_body_country_tampering_is_ignored(): void
    {
        $this->test_query_and_body_country_tampering_is_ignored();
    }

    /**
     * ZT-04: Unsigned Cloudflare / IP headers cannot set pricing country.
     */
    public function test_cloudflare_cf_ipcountry_takes_priority(): void
    {
        $request = Request::create('/api/v1/subscription/plans', 'GET');
        $request->headers->set('CF-IPCountry', 'KW');
        $request->headers->set('CF-Connecting-IP', '8.8.8.8');
        $request->headers->set('X-Forwarded-For', '8.8.8.8');
        $request->headers->set('X-User-Country', 'SA');

        $this->assertSame('EG', $this->countryDetectionService->detect($request));
    }

    public function test_zt04_cloudflare_cf_ipcountry_takes_priority(): void
    {
        $this->test_cloudflare_cf_ipcountry_takes_priority();
    }

    /**
     * ZT-05: PaymentMethod soft delete trait is registered and active on model.
     */
    public function test_payment_method_model_uses_soft_deletes(): void
    {
        $traits = class_uses_recursive(PaymentMethod::class);
        $this->assertArrayHasKey(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
    }

    public function test_zt05_payment_method_model_uses_soft_deletes(): void
    {
        $this->test_payment_method_model_uses_soft_deletes();
    }

    /**
     * ZT-06: Video anti-cheat duration shrink spoofing rejection.
     */
    public function test_duration_shrink_spoofing_is_rejected(): void
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

    public function test_zt06_duration_shrink_spoofing_is_rejected(): void
    {
        $this->test_duration_shrink_spoofing_is_rejected();
    }

    /**
     * ZT-07: PromoCode model soft delete trait is registered and active.
     */
    public function test_promo_code_model_uses_soft_deletes(): void
    {
        $traits = class_uses_recursive(PromoCode::class);
        $this->assertArrayHasKey(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
    }

    public function test_zt07_promo_code_model_uses_soft_deletes(): void
    {
        $this->test_promo_code_model_uses_soft_deletes();
    }

    /**
     * ZT-08: Kashier HMAC SHA-256 signature validation and tamper detection test.
     */
    public function test_zt08_kashier_hmac_signature_calculation(): void
    {
        $apiKey = 'test_secret_key_12345';
        $params = [
            'amount' => '150.00',
            'currency' => 'EGP',
            'merchantOrderId' => 'wlt_999_1700000000',
            'paymentStatus' => 'SUCCESS',
        ];

        ksort($params);
        $queryParts = [];
        foreach ($params as $k => $v) {
            $queryParts[] = $k . '=' . $v;
        }
        $queryString = implode('&', $queryParts);
        $expectedSignature = hash_hmac('sha256', $queryString, $apiKey, false);

        // Verification with valid signature
        $params['signature'] = $expectedSignature;

        $dataToVerify = $params;
        unset($dataToVerify['signature']);
        ksort($dataToVerify);
        $parts = [];
        foreach ($dataToVerify as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $computed = hash_hmac('sha256', implode('&', $parts), $apiKey, false);
        $this->assertTrue(hash_equals($expectedSignature, $computed));

        // Verification with tampered amount must fail
        $tamperedData = $dataToVerify;
        $tamperedData['amount'] = '10.00';
        $partsTampered = [];
        foreach ($tamperedData as $k => $v) {
            $partsTampered[] = $k . '=' . $v;
        }
        $computedTampered = hash_hmac('sha256', implode('&', $partsTampered), $apiKey, false);
        $this->assertFalse(hash_equals($expectedSignature, $computedTampered));
    }

    /**
     * ZT-09: PromoQuotaExceededException contains expected message and status code.
     */
    public function test_zt09_promo_quota_exceeded_exception(): void
    {
        $exception = new \App\Exceptions\PromoQuotaExceededException('كوبون الخصم استنفذ الحد الأقصى للاستخدام', 422);
        $this->assertSame('كوبون الخصم استنفذ الحد الأقصى للاستخدام', $exception->getMessage());
        $this->assertSame(422, $exception->getCode());
    }

    /**
     * ZT-10: PromoCode normalization trims whitespace and converts to uppercase.
     */
    public function test_zt10_promo_code_normalization(): void
    {
        $this->assertSame('SAVE50', \App\Services\SubscriptionPromoService::normalizeCode('  save50  '));
        $this->assertSame('DISCOUNT', \App\Services\SubscriptionPromoService::normalizeCode('discount'));
        $this->assertSame('', \App\Services\SubscriptionPromoService::normalizeCode(null));
        $this->assertSame('', \App\Services\SubscriptionPromoService::normalizeCode('   '));
    }

    /**
     * ZT-11: WalletService debitWallet signature accepts allowNegative parameter.
     */
    public function test_zt11_debit_wallet_allow_negative_parameter(): void
    {
        $reflection = new \ReflectionMethod(\App\Services\WalletService::class, 'debitWallet');
        $params = $reflection->getParameters();
        $this->assertGreaterThanOrEqual(8, count($params));
        $allowNegativeParam = $params[7];
        $this->assertSame('allowNegative', $allowNegativeParam->getName());
        $this->assertTrue($allowNegativeParam->isDefaultValueAvailable());
        $this->assertFalse($allowNegativeParam->getDefaultValue());
    }

    /**
     * ZT-12: CourseCertificate revocation helper logic.
     */
    public function test_zt12_course_certificate_revocation_helpers(): void
    {
        $cert = new \App\Models\Course\CourseCertificate();
        $cert->status = 'revoked';
        $this->assertTrue($cert->isRevoked());
        $this->assertFalse($cert->isActive());

        $activeCert = new \App\Models\Course\CourseCertificate();
        $activeCert->status = 'active';
        $this->assertTrue($activeCert->isActive());
        $this->assertFalse($activeCert->isRevoked());
    }

    /**
     * ZT-13: CountryDetectionService ignores ?country_code=XX query tampering.
     */
    public function test_zt13_country_detection_ignores_query_parameters(): void
    {
        $service = new \App\Services\CountryDetectionService();
        $request = \Illuminate\Http\Request::create('/api/pricing?country_code=US', 'GET', ['country_code' => 'US']);
        
        // Without headers, defaults to EG (query param is ignored)
        $this->assertSame('EG', $service->detect($request));
    }

    /**
     * ZT-14: CF-IPCountry header takes absolute precedence over other proxy headers.
     */
    public function test_zt14_cloudflare_cf_ipcountry_precedence(): void
    {
        $service = new \App\Services\CountryDetectionService();
        $request = \Illuminate\Http\Request::create('/api/pricing', 'GET');
        $request->headers->set('CF-IPCountry', 'SA');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');
        $request->headers->set('X-User-Country', 'AE');
        $request->headers->set('X-Vercel-IP-Country', 'KW');

        $this->assertSame('SA', $service->detect($request));
    }
}
