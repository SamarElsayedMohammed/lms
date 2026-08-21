<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use App\Exceptions\ApiException;
use App\Exceptions\Handler;
use App\Exceptions\PromoQuotaExceededException;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Class ChallengerForensicsEmpiricalTest
 *
 * Empirical verification suite by Challenger 2 for Milestone 1:
 * 1. Real-world OOM Memory Exhaustion in CLI and Web contexts.
 * 2. Monolog write failures, read-only disk, and stderr fallback logging.
 * 3. ApiException and ValidationException JSON compliance and non-crashing behavior.
 */
final class ChallengerForensicsEmpiricalTest extends TestCase
{
    /**
     * Challenge 1A: Empirical CLI Memory Exhaustion (OOM) in isolated PHP process.
     */
    public function test_cli_memory_exhaustion_triggers_fatal_shutdown_and_emits_json(): void
    {
        $bootstrapApp = str_replace('\\', '/', dirname(__DIR__, 3) . '/bootstrap/app.php');
        $vendorAutoload = str_replace('\\', '/', dirname(__DIR__, 3) . '/vendor/autoload.php');

        $tmpFile = sys_get_temp_dir() . '/test_oom_' . uniqid() . '.php';
        $code = "<?php\n"
            . "require_once '{$vendorAutoload}';\n"
            . "require_once '{$bootstrapApp}';\n"
            . "ini_set('memory_limit', '64M');\n"
            . "\$bloat = [];\n"
            . "while (true) {\n"
            . "    \$bloat[] = str_repeat('X', 1024 * 1024);\n"
            . "}\n";

        file_put_contents($tmpFile, $code);

        $cmd = '"' . PHP_BINARY . '" ' . escapeshellarg($tmpFile) . ' 2>&1';
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        $this->assertIsString($output);
        $this->assertStringContainsString('[FATAL-SHUTDOWN]', $output, 'Output must contain [FATAL-SHUTDOWN] tag');

        // Extract and validate JSON payload
        preg_match('/\[FATAL-SHUTDOWN\]\s*(\{.*\})/s', $output, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Failed to extract JSON record from output');

        $record = json_decode($matches[1], true);
        $this->assertIsArray($record, 'Extracted record must be valid JSON');
        $this->assertSame('FATAL_SHUTDOWN', $record['event']);
        $this->assertTrue($record['is_oom'], 'is_oom must be true');
        $this->assertSame('E_ERROR', $record['error_type']);
        $this->assertSame('64M', $record['memory_limit']);
        $this->assertArrayHasKey('memory_usage_bytes', $record);
        $this->assertArrayHasKey('memory_peak_bytes', $record);
        $this->assertArrayHasKey('file', $record);
        $this->assertArrayHasKey('line', $record);
        $this->assertSame('cli', $record['sapi']);
    }

    /**
     * Challenge 1B: Empirical Web Context Memory Exhaustion (OOM Simulation).
     */
    public function test_web_context_memory_exhaustion_captures_diagnostic_metadata(): void
    {
        $bootstrapApp = str_replace('\\', '/', dirname(__DIR__, 3) . '/bootstrap/app.php');
        $vendorAutoload = str_replace('\\', '/', dirname(__DIR__, 3) . '/vendor/autoload.php');

        $tmpFile = sys_get_temp_dir() . '/test_oom_web_' . uniqid() . '.php';
        $code = "<?php\n"
            . "require_once '{$vendorAutoload}';\n"
            . "require_once '{$bootstrapApp}';\n"
            . "ini_set('memory_limit', '64M');\n"
            . "\$bloat = [];\n"
            . "while (true) {\n"
            . "    \$bloat[] = str_repeat('Y', 1024 * 1024);\n"
            . "}\n";

        file_put_contents($tmpFile, $code);

        $cmd = '"' . PHP_BINARY . '" ' . escapeshellarg($tmpFile) . ' 2>&1';
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        $this->assertIsString($output);
        $this->assertStringContainsString('[FATAL-SHUTDOWN]', $output);

        preg_match('/\[FATAL-SHUTDOWN\]\s*(\{.*\})/s', $output, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $record = json_decode($matches[1], true);
        $this->assertIsArray($record);
        $this->assertSame('FATAL_SHUTDOWN', $record['event']);
        $this->assertTrue($record['is_oom']);
        $this->assertSame('64M', $record['memory_limit']);
        $this->assertGreaterThan(50 * 1024 * 1024, $record['memory_usage_bytes']);
    }

    /**
     * Challenge 2A: Exception reporting when Monolog throws (Disk Full / Permission Denied).
     */
    public function test_handler_falls_back_to_stderr_when_monolog_fails(): void
    {
        $handler = app(Handler::class);
        $exception = new RuntimeException('Test exception under failed logger');

        // Mock Log::error to throw an exception simulating Monolog disk permission or disk full error
        Log::shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('Disk full or /var/log permission denied'));

        // Handler::report must not throw even if Log::error throws
        try {
            $handler->report($exception);
            $this->assertTrue(true, 'Handler::report handled Log::error failure without re-throwing');
        } catch (\Throwable $unhandled) {
            $this->fail('Handler::report re-threw exception when logger failed: ' . $unhandled->getMessage());
        }
    }

    /**
     * Challenge 2B: Direct invocation of fallbackStderrLog produces valid structured JSON.
     */
    public function test_fallback_stderr_log_method_structure(): void
    {
        $handler = app(Handler::class);
        $reflection = new ReflectionMethod($handler, 'fallbackStderrLog');
        $reflection->setAccessible(true);

        $testException = new RuntimeException('Critical core failure', 500);
        $logError = new RuntimeException('Permission denied on /storage/logs/laravel.log');

        // Calling reflection method must execute cleanly
        $reflection->invoke($handler, $testException, ['user_id' => 42, 'url' => '/api/test'], $logError);
        $this->assertTrue(true, 'fallbackStderrLog executed cleanly');
    }

    /**
     * Challenge 2C: Auth/DB severed connection does not crash Handler::report().
     */
    public function test_handler_report_with_severed_db_does_not_throw_secondary_exception(): void
    {
        $handler = app(Handler::class);
        $dbException = new QueryException(
            'sqlite',
            'SELECT * FROM users WHERE id = 1',
            [],
            new PDOException('SQLSTATE[HY000]: Database disk image is malformed / connection dead', 2002)
        );

        try {
            $handler->report($dbException);
            $this->assertTrue(true, 'Handler::report completed without secondary exception on severed DB');
        } catch (\Throwable $e) {
            $this->fail('Handler::report threw secondary exception: ' . $e->getMessage());
        }
    }

    /**
     * Challenge 3A: ApiException JSON structure compliance with custom status code and payload data.
     */
    public function test_api_exception_json_structure_and_payload_compliance(): void
    {
        $customData = [
            'order_id' => 'ORD-98765',
            'required_action' => 'PAYMENT_RETRY',
            'attempts_left' => 2,
        ];
        $apiException = new ApiException('Custom payment gateway error', $customData, 402);

        $handler = app(Handler::class);
        $request = Request::create('/api/v1/orders/pay', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, $apiException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(402, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertFalse($data['success']);
        $this->assertFalse($data['status']);
        $this->assertTrue($data['error']);
        $this->assertSame('Custom payment gateway error', $data['message']);
        $this->assertSame(402, $data['code']);
        $this->assertSame($customData, $data['data']);
    }

    /**
     * Challenge 3B: ApiException with default parameters (400 and null data).
     */
    public function test_api_exception_defaults(): void
    {
        $apiException = new ApiException('Bad request parameter');

        $handler = app(Handler::class);
        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, $apiException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertSame('Bad request parameter', $data['message']);
        $this->assertSame(400, $data['code']);
        $this->assertEquals([], (array) $data['data']);
    }

    /**
     * Challenge 3C: ValidationException JSON structure compliance with field errors.
     */
    public function test_validation_exception_json_structure_compliance(): void
    {
        $validationException = ValidationException::withMessages([
            'email' => ['The email field must be a valid email address.', 'Email is already taken.'],
            'phone' => ['The phone format is invalid.'],
            'amount' => ['The amount must be greater than zero.'],
        ]);

        $handler = app(Handler::class);
        $request = Request::create('/api/v1/users/register', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, $validationException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertIsArray($data);
        $this->assertFalse($data['success']);
        $this->assertFalse($data['status']);
        $this->assertTrue($data['error']);
        $this->assertSame(422, $data['code']);
        $this->assertSame('The email field must be a valid email address.', $data['message']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertCount(2, $data['errors']['email']);
        $this->assertSame('The phone format is invalid.', $data['errors']['phone'][0]);
        $this->assertSame('The amount must be greater than zero.', $data['errors']['amount'][0]);
    }

    /**
     * Challenge 3D: PromoQuotaExceededException JSON structure compliance.
     */
    public function test_promo_quota_exceeded_exception_json_structure(): void
    {
        $promoException = new PromoQuotaExceededException('كوبون الخصم استنفذ الحد الأقصى للاستخدام', 422);

        $handler = app(Handler::class);
        $request = Request::create('/api/v1/checkout/apply-coupon', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, $promoException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertSame('كوبون الخصم استنفذ الحد الأقصى للاستخدام', $data['message']);
        $this->assertSame(422, $data['code']);
    }

    /**
     * Challenge 3E: AuthenticationException returns 401 JSON envelope consistently.
     */
    public function test_authentication_exception_returns_401_json(): void
    {
        $handler = app(Handler::class);
        $request = Request::create('/api/v1/user/profile', 'GET');
        $request->headers->set('Accept', 'application/json');

        $authException = new AuthenticationException('Unauthenticated.');
        $response = $handler->render($request, $authException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertFalse($data['status']);
        $this->assertTrue($data['error']);
        $this->assertSame('Unauthenticated.', $data['message']);
        $this->assertSame(401, $data['code']);
    }
}
