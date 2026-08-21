<?php

declare(strict_types=1);

namespace Tests\Feature\Forensics;

use App\Exceptions\ApiException;
use App\Exceptions\PromoQuotaExceededException;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

/**
 * Class ApiExceptionRenderingTest
 *
 * Forensic Feature Test Suite verifying direct JSON exception rendering
 * and HTTP status preservation across all exception types (R1).
 */
final class ApiExceptionRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-api-exception-400', function () {
            throw new ApiException('Invalid coupon payload', ['code' => 'DISCOUNT50'], 400);
        });

        Route::get('/api/test-api-exception-404', function () {
            throw new ApiException('Requested entity was not located', null, 404);
        });

        Route::get('/api/test-promo-quota-exceeded', function () {
            throw new PromoQuotaExceededException('كوبون الخصم استنفذ الحد الأقصى للاستخدام');
        });

        Route::get('/api/test-validation-exception', function () {
            throw ValidationException::withMessages([
                'email' => ['The email address format is invalid.'],
                'password' => ['The password must be at least 8 characters.'],
            ]);
        });

        Route::get('/api/test-auth-exception', function () {
            throw new AuthenticationException('Unauthenticated.');
        });

        Route::get('/api/test-forbidden-exception', function () {
            throw new AccessDeniedHttpException('Access denied for this resource.');
        });

        Route::get('/api/test-model-not-found', function () {
            $e = new ModelNotFoundException();
            $e->setModel('App\Models\Course\Course', [99999]);
            throw $e;
        });

        Route::get('/api/test-rate-limit', function () {
            throw new TooManyRequestsHttpException(60, 'Too many requests. Please slow down.');
        });

        Route::get('/api/test-generic-exception', function () {
            throw new Exception('Unexpected system failure');
        });
    }

    /**
     * Tier 1: Feature Coverage — Verify ApiException preserves status code 400 and structured data.
     */
    public function test_api_exception_preserves_custom_status_code_and_data_payload(): void
    {
        $response = $this->getJson('/api/test-api-exception-400');

        // Custom ApiException must preserve status 400 (not masked as 500)
        $response->assertStatus(400);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('Invalid coupon payload', $data['message'] ?? null);
    }

    /**
     * Tier 1: Feature Coverage — Verify ApiException with 404 preserves 404 status.
     */
    public function test_api_exception_preserves_custom_404_status(): void
    {
        $response = $this->getJson('/api/test-api-exception-404');

        $response->assertStatus(404);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * Tier 1: Feature Coverage — Verify PromoQuotaExceededException preserves 422 Unprocessable Entity status.
     */
    public function test_promo_quota_exceeded_exception_returns_422_status(): void
    {
        $response = $this->getJson('/api/test-promo-quota-exceeded');

        // Custom PromoQuotaExceededException must preserve 422
        $response->assertStatus(422);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertStringContainsString('استنفذ', $data['message'] ?? '');
    }

    /**
     * Tier 1: Feature Coverage — Verify ValidationException returns 422 with validation errors JSON.
     */
    public function test_validation_exception_returns_422_with_validation_errors(): void
    {
        $response = $this->getJson('/api/test-validation-exception');

        $response->assertStatus(422);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('email', $data['errors']);
        $this->assertArrayHasKey('password', $data['errors']);
    }

    /**
     * Tier 1: Feature Coverage — Verify AuthenticationException on API route returns 401 without redirecting.
     */
    public function test_authentication_exception_on_api_route_returns_401_json_without_redirect(): void
    {
        $response = $this->getJson('/api/test-auth-exception');

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame('Unauthenticated.', $data['message'] ?? null);
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify AccessDeniedHttpException returns 403 JSON.
     */
    public function test_access_denied_exception_returns_403_json(): void
    {
        $response = $this->getJson('/api/test-forbidden-exception');

        $response->assertStatus(403);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify ModelNotFoundException returns 404 JSON.
     */
    public function test_model_not_found_exception_returns_404_json(): void
    {
        $response = $this->getJson('/api/test-model-not-found');

        $response->assertStatus(404);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify TooManyRequestsHttpException returns 429 JSON.
     */
    public function test_too_many_requests_exception_returns_429_json(): void
    {
        $response = $this->getJson('/api/test-rate-limit');

        $response->assertStatus(429);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify generic Throwable returns 500 JSON without throwing nested response exceptions.
     */
    public function test_generic_throwable_returns_500_json_cleanly(): void
    {
        $response = $this->getJson('/api/test-generic-exception');

        $response->assertStatus(500);
        $response->assertHeader('content-type', 'application/json');

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertFalse($data['success'] ?? true);
    }

    /**
     * Tier 4: Real-World Scenarios — Invariant Audit: Handler::render does not invoke ApiResponseService::errorResponse() which throws HttpResponseException.
     */
    public function test_handler_render_source_does_not_throw_nested_response_exception(): void
    {
        $handlerFile = app_path('Exceptions/Handler.php');
        $content = file_get_contents($handlerFile);
        $this->assertNotFalse($content);

        // Handler::render() must return response directly, not throw HttpResponseException via ApiResponseService::errorResponse()
        $hasNestedThrow = str_contains($content, 'ApiResponseService::errorResponse');
        $this->assertFalse(
            $hasNestedThrow,
            'Handler::render() must not call ApiResponseService::errorResponse() which throws HttpResponseException inside exception render cycles'
        );
    }
}
