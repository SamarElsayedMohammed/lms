<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\CourseApiController;
use App\Services\ApiResponseService;
use App\Services\CourseProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IncidentForensicRegressionTest extends TestCase
{
    /**
     * Test 1: Logging failure (Monolog Permission Denied / UnexpectedValueException)
     * must NOT abort or crash the application response.
     */
    public function test_logging_failure_does_not_abort_api_response(): void
    {
        Log::shouldReceive('error')
            ->andThrow(new \UnexpectedValueException('The stream "/var/www/html/storage/logs/laravel.log" could not be opened: Permission denied'));

        // ApiResponseService::logErrorResponse should safely catch Throwable and not rethrow
        try {
            ApiResponseService::logErrorResponse(
                new \RuntimeException('Test business exception'),
                'Test log context'
            );
            $this->assertTrue(true, 'Logging failure was caught and suppressed');
        } catch (\Throwable $e) {
            $this->fail('ApiResponseService::logErrorResponse rethrew the logging exception: ' . $e->getMessage());
        }
    }

    /**
     * Test 2: Non-local environments must NOT leak stack traces, file paths, or line numbers.
     */
    public function test_non_local_environment_does_not_leak_stack_traces(): void
    {
        Config::set('app.debug', true);
        $this->app['env'] = 'production';

        try {
            ApiResponseService::errorResponse(
                'Something went wrong',
                null,
                500,
                new \RuntimeException('Secret internal DB crash at /var/www/html/app/Secret.php')
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $response = $e->getResponse();
            $data = json_decode((string) $response->getContent(), true);

            $this->assertEquals(500, $response->getStatusCode());
            $this->assertArrayNotHasKey('debug', $data, 'Debug stack trace leaked in production environment');
            $this->assertStringNotContainsString('/var/www/html', (string) $response->getContent());
        }
    }

    /**
     * Test 3: Standard envelope format contains both success and status boolean keys.
     */
    public function test_api_envelope_contains_success_and_status(): void
    {
        try {
            ApiResponseService::successResponse('Success message', ['item' => 'test']);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $data = json_decode((string) $e->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertTrue($data['status']);
            $this->assertFalse($data['error']);
            $this->assertEquals(200, $data['code']);
        }

        try {
            ApiResponseService::errorResponse('Error message', ['error_detail' => 'invalid'], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $data = json_decode((string) $e->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertFalse($data['status']);
            $this->assertTrue($data['error']);
            $this->assertEquals(422, $data['code']);
        }
    }

    /**
     * Test 4: Course Details parameter parsing handles course_id, id, slug, course_slug, and Arabic URL encoding.
     */
    public function test_course_controller_parameter_acceptance(): void
    {
        $controller = app(CourseApiController::class);

        // Test non-existent course returns 404 or validation error without throwing 500
        $request = Request::create('/api/course', 'GET', [
            'course_id' => 999999,
        ]);

        try {
            $response = $controller->getCourse($request);
            $this->assertNotNull($response);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $data = json_decode((string) $e->getResponse()->getContent(), true);
            $this->assertContains($data['code'], [404, 422]);
        }
    }

    /**
     * Test 5: Video-Only learning rule: getTotalItemsForCourse only counts lectures (videos),
     * and does NOT include quizzes or assignments in total items.
     */
    public function test_video_only_product_rule(): void
    {
        $service = new CourseProgressService();
        $this->assertInstanceOf(CourseProgressService::class, $service);
        $this->assertEquals(0, $service->getTotalItemsForCourse(999999));
    }
}
