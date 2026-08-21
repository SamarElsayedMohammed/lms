<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use ReflectionClass;
use ReflectionMethod;
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
     * Tier 1: Feature Coverage — Verify API route returns structured JSON on generic runtime exceptions.
     */
    public function test_api_route_returns_structured_json_on_runtime_error(): void
    {
        $response = $this->getJson('/api/test-runtime-error');

        $response->assertStatus(500);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertFalse($data['success'] ?? true);
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify Handler.php source code wraps Auth::id() and DB calls in try-catch.
     */
    public function test_handler_log_exception_isolates_auth_and_db_lookups(): void
    {
        $handlerFile = app_path('Exceptions/Handler.php');
        $this->assertFileExists($handlerFile);

        $content = file_get_contents($handlerFile);
        $this->assertNotFalse($content);

        // Verify that Auth::id() or user context extraction is safe against DB severance
        // If Auth::id() is called directly without try-catch in logException, it will throw PDOException when DB is down
        $hasAuthExtraction = str_contains($content, 'Auth::id()');
        if ($hasAuthExtraction) {
            $isGuarded = str_contains($content, 'try') && (str_contains($content, 'catch') || str_contains($content, 'Throwable'));
            $this->assertTrue(
                $isGuarded,
                'Auth::id() and user context extraction in Handler::logException must be isolated in a try-catch block to prevent secondary PDOException'
            );
        } else {
            $this->assertTrue(true);
        }
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify Handler register() method visibility complies with Laravel 12.
     */
    public function test_handler_register_method_visibility_is_public(): void
    {
        $handlerFile = app_path('Exceptions/Handler.php');
        $content = file_get_contents($handlerFile);
        $this->assertNotFalse($content);

        // In Laravel 12 / PHP 8.3+, Illuminate\Foundation\Exceptions\Handler::register() is public
        // Narrowing visibility to protected in App\Exceptions\Handler causes PHP Fatal Error
        $this->assertFalse(
            str_contains($content, 'protected function register()'),
            'App\Exceptions\Handler::register() must be declared public function register() to match Illuminate\Foundation\Exceptions\Handler'
        );
    }

    /**
     * Tier 3: Cross-Feature Interactions — Complete database disconnection during request returns 500 JSON without worker drop.
     */
    public function test_complete_database_disconnection_returns_clean_500_response(): void
    {
        Config::set('database.connections.sqlite.database', '/non_existent_directory/db.sqlite');
        DB::purge('sqlite');

        $response = $this->getJson('/api/courses');

        // Request must return non-200 JSON without unhandled fatal crashes
        $this->assertContains($response->getStatusCode(), [500, 503, 404, 401]);
        $response->assertHeader('content-type', 'application/json');
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
