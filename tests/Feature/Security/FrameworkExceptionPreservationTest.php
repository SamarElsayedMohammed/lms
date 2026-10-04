<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\ApiResponseService;
use App\Services\ResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class FrameworkExceptionPreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-caught-validation-exception', function () {
            try {
                throw ValidationException::withMessages(['email' => ['Invalid email']]);
            } catch (\Throwable $e) {
                return ApiResponseService::errorResponse('Caught error', null, 500, $e);
            }
        });

        Route::get('/api/test-caught-authorization-exception', function () {
            try {
                throw new AuthorizationException('This action is unauthorized.');
            } catch (\Throwable $e) {
                return ApiResponseService::errorResponse('Caught error', null, 500, $e);
            }
        });

        Route::get('/api/test-caught-model-not-found', function () {
            try {
                $e = new ModelNotFoundException();
                $e->setModel('App\Models\User', [9999]);
                throw $e;
            } catch (\Throwable $e) {
                return ApiResponseService::errorResponse('Caught error', null, 500, $e);
            }
        });

        Route::get('/api/test-response-service-caught-validation', function () {
            try {
                throw ValidationException::withMessages(['phone' => ['Invalid phone']]);
            } catch (\Throwable $e) {
                ResponseService::errorResponse('Caught error', null, 500, $e);
            }
        });
    }

    public function test_caught_validation_exception_in_api_response_service_returns_422(): void
    {
        $response = $this->getJson('/api/test-caught-validation-exception');
        $response->assertStatus(422);
        $this->assertArrayHasKey('errors', $response->json());
    }

    public function test_caught_authorization_exception_in_api_response_service_returns_403(): void
    {
        $response = $this->getJson('/api/test-caught-authorization-exception');
        $response->assertStatus(403);
    }

    public function test_caught_model_not_found_exception_in_api_response_service_returns_404(): void
    {
        $response = $this->getJson('/api/test-caught-model-not-found');
        $response->assertStatus(404);
    }

    public function test_caught_validation_in_response_service_returns_422(): void
    {
        $response = $this->getJson('/api/test-response-service-caught-validation');
        $response->assertStatus(422);
    }
}
