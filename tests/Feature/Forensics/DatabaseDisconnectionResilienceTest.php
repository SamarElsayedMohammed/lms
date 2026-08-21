<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use App\Exceptions\Handler;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Class DatabaseDisconnectionResilienceTest
 *
 * Forensic Feature Test Suite verifying database disconnection resilience
 * and pre-crash logging safety in exception handling (R1).
 */
final class DatabaseDisconnectionResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-db-failure', function () {
            throw new QueryException(
                'mysql',
                'SELECT * FROM users WHERE id = 1',
                [],
                new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)
            );
        });

        Route::get('/api/test-runtime-error', function () {
            throw new Exception('Unexpected service failure');
        });
    }

    /**
     * Tier 1: Feature Coverage — Verify API route returns JSON response when database is disconnected.
     */
    public function test_api_route_returns_structured_json_on_database_disconnection(): void
    {
        $response = $this->getJson('/api/test-db-failure');

        $response->assertStatus(500);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertFalse($data['success'] ?? true);
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Tier 1: Feature Coverage — Verify Handler::render returns Response instance directly.
     */
    public function test_handler_render_directly_returns_response_instance(): void
    {
        $handler = app(Handler::class);
        $request = Request::create('/api/courses', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);

        $response = $handler->render($request, $exception);

        $this->assertInstanceOf(
            Response::class,
            $response,
            'Handler::render() must directly return an instance of ' . Response::class
        );
        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify Handler handles null request / CLI context without secondary errors.
     */
    public function test_handler_log_exception_safe_in_cli_or_empty_request_context(): void
    {
        $handler = app(Handler::class);
        $exception = new QueryException(
            'sqlite',
            'SELECT 1',
            [],
            new PDOException('Database connection lost', 2006)
        );

        // Invoking report must not throw any secondary fatal exception
        try {
            $handler->report($exception);
            $this->assertTrue(true, 'Exception reported cleanly without secondary exceptions');
        } catch (\Throwable $secondaryException) {
            $this->fail('Handler::report() threw secondary fatal exception: ' . $secondaryException->getMessage());
        }
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify Auth::id() isolation during severed database state.
     */
    public function test_auth_context_extraction_isolated_from_database_failure(): void
    {
        // Mock Auth guard to throw PDOException on id() call (simulating DB session lookup crash)
        Auth::shouldReceive('id')
            ->andThrow(new PDOException('PDO Connection closed', 2006));

        $handler = app(Handler::class);
        $exception = new Exception('Primary application error');

        try {
            $handler->report($exception);
            $this->assertTrue(true, 'Exception handler safely isolated Auth failure');
        } catch (\Throwable $secondaryException) {
            $this->fail('Handler threw secondary exception when Auth guard failed: ' . $secondaryException->getMessage());
        }
    }

    /**
     * Tier 3: Cross-Feature Interactions — Logging channel failure falls back safely without crashing.
     */
    public function test_logging_channel_failure_does_not_crash_exception_handling(): void
    {
        Log::shouldReceive('error')
            ->andThrow(new \RuntimeException('Disk full or unwritable stream'));

        $handler = app(Handler::class);
        $exception = new Exception('Test critical fault');

        try {
            $handler->report($exception);
            $this->assertTrue(true, 'Logging failure did not abort handler');
        } catch (\Throwable $secondaryException) {
            // Handler must gracefully catch logger failures and fallback to stderr
            $this->fail('Handler did not catch logging driver failure: ' . $secondaryException->getMessage());
        }
    }

    /**
     * Tier 4: Real-World Scenarios — Rapid sequence of database-failed requests returns 500 without worker drops.
     */
    public function test_multiple_consecutive_database_failure_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson('/api/test-db-failure');
            $response->assertStatus(500);
            $response->assertHeader('content-type', 'application/json');
        }
    }
}
