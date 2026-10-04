<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\ApiResponseService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProductionExceptionSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        app()->detectEnvironment(fn () => 'production');

        Route::get('/api/test-production-exception', function () {
            throw new \RuntimeException('Internal crash in /var/www/app/SecretController.php on line 42');
        });

        Route::get('/api/test-production-sql-leak', function () {
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'SELECT * FROM secret_credentials WHERE token = "xyz"',
                [],
                new \Exception('SQLSTATE[42S02]: Base table or view not found: 1146 Table secret_credentials does not exist')
            );
        });

        Route::get('/api/test-controller-error-response-with-sql', function () {
            try {
                throw new \Exception('SQLSTATE[HY000] General error: 1364 Field user_passwords doesn\'t have a default in /var/www/Models/User.php');
            } catch (\Throwable $e) {
                return ApiResponseService::errorResponse('Failed database update: ' . $e->getMessage(), null, 500);
            }
        });

        Route::get('/api/test-response-service-with-path', function () {
            try {
                throw new \Exception('Fatal error in /var/www/skillso/vendor/autoload.php: cannot find class SecretClass');
            } catch (\Throwable $e) {
                ResponseService::errorResponse('Service failure: ' . $e->getMessage(), null, 500);
            }
        });
    }

    public function test_api_unhandled_exception_in_production_masks_trace_and_paths(): void
    {
        Log::shouldReceive('error')->atLeast()->once();

        $response = $this->getJson('/api/test-production-exception');

        $response->assertStatus(500);
        $content = $response->getContent();

        $this->assertStringNotContainsString('SecretController.php', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('trace', $content);
        $this->assertStringNotContainsString('line 42', $content);
        $this->assertArrayNotHasKey('debug', $response->json());
        $this->assertSame('Internal server error.', $response->json('message'));
    }

    public function test_api_database_query_exception_in_production_masks_sql_and_table_names(): void
    {
        Log::shouldReceive('error')->atLeast()->once();

        $response = $this->getJson('/api/test-production-sql-leak');

        $response->assertStatus(500);
        $content = $response->getContent();

        $this->assertStringNotContainsString('secret_credentials', $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('SELECT * FROM', $content);
        $this->assertArrayNotHasKey('debug', $response->json());
    }

    public function test_api_response_service_sanitizes_leaked_sql_in_controller_catch_blocks(): void
    {
        $response = $this->getJson('/api/test-controller-error-response-with-sql');

        $response->assertStatus(500);
        $content = $response->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('user_passwords', $content);
        $this->assertStringNotContainsString('User.php', $content);
        $this->assertSame('Internal server error.', $response->json('message'));
    }

    public function test_response_service_sanitizes_leaked_file_paths_in_production(): void
    {
        $response = $this->getJson('/api/test-response-service-with-path');

        $response->assertStatus(500);
        $content = $response->getContent();

        $this->assertStringNotContainsString('/var/www/', $content);
        $this->assertStringNotContainsString('SecretClass', $content);
        $this->assertSame('Internal server error.', $response->json('message'));
    }
}
