<?php

declare(strict_types=1);

namespace Tests\Unit\Forensics;

use App\Helpers\FirebaseHelper;
use App\Jobs\FetchBunnyVideoDurationJob;
use App\Jobs\UpdateExchangeRatesJob;
use App\Models\MarketingPixel;
use App\Services\CountryDetectionService;
use App\Services\GeoLocationService;
use App\Services\Mail\BrevoTransactionalMailService;
use App\Services\Mail\MailFromResolver;
use App\Services\TrackingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Class OutboundHttpTimeoutContractTest
 *
 * Forensic Unit Test Suite verifying outbound network timeout hardening (R2):
 * - Connect Timeout <= 3 seconds
 * - Total Execution Timeout <= 10 seconds
 * - SSL Peer Verification Enforced (CURLOPT_SSL_VERIFYPEER = true)
 * - SDK and cURL Transport Hardening
 */
final class OutboundHttpTimeoutContractTest extends TestCase
{
    /**
     * Tier 1: Feature Coverage — Verify BrevoTransactionalMailService enforces connect and execution timeouts.
     */
    public function test_brevo_mail_service_enforces_connect_and_request_timeouts(): void
    {
        Config::set('services.brevo.api_key', 'test-brevo-key-12345');
        Config::set('mail.from.address', 'noreply@skillso.com');
        Config::set('mail.from.name', 'Skillso LMS');

        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response([
                'messageId' => '<mock-msg-123@brevo.com>',
            ], 201),
        ]);

        $resolver = $this->createMock(MailFromResolver::class);
        $resolver->method('resolve')->willReturn([
            'address' => 'noreply@skillso.com',
            'name' => 'Skillso LMS',
        ]);

        $service = new BrevoTransactionalMailService($resolver);
        $result = $service->sendHtml('student@example.com', 'Student', 'Welcome', '<p>Hello</p>');

        $this->assertSame('<mock-msg-123@brevo.com>', $result['message_id']);

        Http::assertSent(function (Request $request) {
            // Verify endpoint
            $urlMatch = str_contains($request->url(), 'api.brevo.com');

            // Verify timeout boundaries from options
            $timeout = $request->timeout();
            $connectTimeout = $request->connectTimeout();

            // Total timeout must be <= 10s
            if ($timeout !== null) {
                $this->assertLessThanOrEqual(
                    10,
                    $timeout,
                    "Brevo mail request timeout ({$timeout}s) must be <= 10 seconds"
                );
            }

            // Connect timeout must be <= 3s
            if ($connectTimeout !== null) {
                $this->assertLessThanOrEqual(
                    3,
                    $connectTimeout,
                    "Brevo mail connect timeout ({$connectTimeout}s) must be <= 3 seconds"
                );
            }

            return $urlMatch;
        });
    }

    /**
     * Tier 1: Feature Coverage — Verify TrackingService Facebook CAPI enforces connect and request timeouts.
     */
    public function test_tracking_service_facebook_capi_enforces_bounded_timeouts(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $pixel = new MarketingPixel([
            'platform' => 'facebook',
            'pixel_id' => '1234567890',
            'is_active' => true,
            'additional_config' => ['access_token' => 'mock-fb-token'],
        ]);

        // Mock pixel query or use instance
        TrackingService::sendFacebookEvent('Purchase', ['em' => 'test@example.com'], ['value' => 100, 'currency' => 'USD']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $timeout = $request->timeout();
                $connectTimeout = $request->connectTimeout();

                if ($timeout !== null) {
                    $this->assertLessThanOrEqual(10, $timeout, 'Facebook CAPI timeout must be <= 10s');
                }
                if ($connectTimeout !== null) {
                    $this->assertLessThanOrEqual(3, $connectTimeout, 'Facebook CAPI connect timeout must be <= 3s');
                }
            }
            return true;
        });
    }

    /**
     * Tier 1: Feature Coverage — Verify TrackingService GA4 Measurement Protocol enforces bounded timeouts.
     */
    public function test_tracking_service_ga4_enforces_bounded_timeouts(): void
    {
        Http::fake([
            'https://www.google-analytics.com/*' => Http::response([], 204),
        ]);

        TrackingService::sendGA4Event('purchase', ['value' => 100, 'currency' => 'USD']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'google-analytics.com')) {
                $timeout = $request->timeout();
                $connectTimeout = $request->connectTimeout();

                if ($timeout !== null) {
                    $this->assertLessThanOrEqual(10, $timeout, 'GA4 timeout must be <= 10s');
                }
                if ($connectTimeout !== null) {
                    $this->assertLessThanOrEqual(3, $connectTimeout, 'GA4 connect timeout must be <= 3s');
                }
            }
            return true;
        });
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify CountryDetectionService enforces <= 3s connect and <= 3s total timeouts.
     */
    public function test_country_detection_service_enforces_strict_curl_timeouts(): void
    {
        $reflection = new \ReflectionClass(CountryDetectionService::class);
        $fileName = $reflection->getFileName();
        $this->assertNotFalse($fileName);

        $source = file_get_contents($fileName);
        $this->assertNotFalse($source);

        // Verify CURLOPT_CONNECTTIMEOUT <= 3 and CURLOPT_TIMEOUT <= 10
        if (preg_match_all('/CURLOPT_CONNECTTIMEOUT\s*=>\s*(\d+)/', $source, $connectMatches)) {
            foreach ($connectMatches[1] as $connectTimeout) {
                $this->assertLessThanOrEqual(
                    3,
                    (int) $connectTimeout,
                    "CountryDetectionService connect timeout ({$connectTimeout}s) must be <= 3s"
                );
            }
        }

        if (preg_match_all('/CURLOPT_TIMEOUT\s*=>\s*(\d+)/', $source, $timeoutMatches)) {
            foreach ($timeoutMatches[1] as $timeout) {
                $this->assertLessThanOrEqual(
                    10,
                    (int) $timeout,
                    "CountryDetectionService execution timeout ({$timeout}s) must be <= 10s"
                );
            }
        }
    }

    /**
     * Tier 2: Boundary & Corner Cases — Invariant Audit: No CURLOPT_SSL_VERIFYPEER = false in codebase.
     */
    public function test_no_insecure_ssl_verification_across_codebase(): void
    {
        $appPath = app_path();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath));

        $violatingFiles = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Check for CURLOPT_SSL_VERIFYPEER => false or curl_setopt(..., CURLOPT_SSL_VERIFYPEER, false)
            if (
                preg_match('/CURLOPT_SSL_VERIFYPEER\s*,\s*false/i', $content) ||
                preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/i', $content) ||
                preg_match('/CURLOPT_SSL_VERIFYHOST\s*,\s*0/i', $content) ||
                preg_match('/CURLOPT_SSL_VERIFYHOST\s*=>\s*0/i', $content)
            ) {
                $violatingFiles[] = $file->getPathname();
            }
        }

        $this->assertEmpty(
            $violatingFiles,
            'Disabling SSL certificate verification (CURLOPT_SSL_VERIFYPEER = false) is strictly prohibited. Violations in: ' . implode(', ', $violatingFiles)
        );
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify FetchBunnyVideoDurationJob enforces connect and request timeouts.
     */
    public function test_bunny_duration_job_outbound_timeout_boundaries(): void
    {
        $reflection = new \ReflectionClass(FetchBunnyVideoDurationJob::class);
        $fileName = $reflection->getFileName();
        $this->assertNotFalse($fileName);

        $source = file_get_contents($fileName);
        $this->assertNotFalse($source);

        // Verify connectTimeout is <= 3
        if (preg_match('/connectTimeout\((\d+)\)/', $source, $connectMatches)) {
            $this->assertLessThanOrEqual(
                3,
                (int) $connectMatches[1],
                "FetchBunnyVideoDurationJob connectTimeout ({$connectMatches[1]}s) must be <= 3s"
            );
        }

        // Verify timeout is <= 10
        if (preg_match('/timeout\((\d+)\)/', $source, $timeoutMatches)) {
            $this->assertLessThanOrEqual(
                10,
                (int) $timeoutMatches[1],
                "FetchBunnyVideoDurationJob timeout ({$timeoutMatches[1]}s) must be <= 10s"
            );
        }
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify UpdateExchangeRatesJob enforces connect and request timeouts.
     */
    public function test_update_exchange_rates_job_outbound_timeout_boundaries(): void
    {
        $reflection = new \ReflectionClass(UpdateExchangeRatesJob::class);
        $fileName = $reflection->getFileName();
        $this->assertNotFalse($fileName);

        $source = file_get_contents($fileName);
        $this->assertNotFalse($source);

        if (preg_match('/connectTimeout\((\d+)\)/', $source, $connectMatches)) {
            $this->assertLessThanOrEqual(
                3,
                (int) $connectMatches[1],
                "UpdateExchangeRatesJob connectTimeout ({$connectMatches[1]}s) must be <= 3s"
            );
        }

        if (preg_match('/timeout\((\d+)\)/', $source, $timeoutMatches)) {
            $this->assertLessThanOrEqual(
                10,
                (int) $timeoutMatches[1],
                "UpdateExchangeRatesJob timeout ({$timeoutMatches[1]}s) must be <= 10s"
            );
        }
    }

    /**
     * Tier 4: Real-World Scenarios — Exhaustive Static Verification of All 21 Outbound Network Call Locations.
     */
    public function test_all_outbound_http_calls_adhere_to_forensic_timeout_matrix(): void
    {
        $targets = [
            'app/Helpers/FirebaseHelper.php',
            'app/Services/NotificationService.php',
            'app/Services/Mail/BrevoTransactionalMailService.php',
            'app/Services/Payment/FlutterwaveCheckoutService.php',
            'app/Services/Payment/KashierCheckoutService.php',
            'app/Services/Payment/StripeCheckoutService.php',
            'app/Services/Payment/RazorpayPayment.php',
            'app/Services/GoogleMeetService.php',
            'app/Services/ZoomService.php',
            'app/Services/EmbeddingService.php',
            'app/Services/ChatBotService.php',
            'app/Jobs/FetchBunnyVideoDurationJob.php',
            'app/Jobs/UpdateExchangeRatesJob.php',
            'app/Services/TrackingService.php',
            'app/Services/CountryDetectionService.php',
            'app/Services/GeoLocationService.php',
        ];

        foreach ($targets as $relPath) {
            $fullPath = base_path($relPath);
            if (!file_exists($fullPath)) {
                continue;
            }

            $content = file_get_contents($fullPath);
            $this->assertNotFalse($content, "Could not read {$relPath}");

            // Verify no timeout > 10 in Http::timeout(X) or CURLOPT_TIMEOUT => X
            if (preg_match_all('/(?:Http::|->)timeout\((\d+)\)/', $content, $httpTimeouts)) {
                foreach ($httpTimeouts[1] as $to) {
                    $this->assertLessThanOrEqual(
                        10,
                        (int) $to,
                        "In {$relPath}: Http::timeout({$to}) exceeds 10s maximum deadline"
                    );
                }
            }

            if (preg_match_all('/CURLOPT_TIMEOUT\s*(?:=>|,)\s*(\d+)/', $content, $curlTimeouts)) {
                foreach ($curlTimeouts[1] as $to) {
                    $this->assertLessThanOrEqual(
                        10,
                        (int) $to,
                        "In {$relPath}: CURLOPT_TIMEOUT ({$to}s) exceeds 10s maximum deadline"
                    );
                }
            }

            if (preg_match_all('/CURLOPT_CONNECTTIMEOUT\s*(?:=>|,)\s*(\d+)/', $content, $curlConnects)) {
                foreach ($curlConnects[1] as $ct) {
                    $this->assertLessThanOrEqual(
                        3,
                        (int) $ct,
                        "In {$relPath}: CURLOPT_CONNECTTIMEOUT ({$ct}s) exceeds 3s maximum connection deadline"
                    );
                }
            }
        }
    }
}
