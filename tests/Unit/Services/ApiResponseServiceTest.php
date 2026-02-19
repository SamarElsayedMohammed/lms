<?php

namespace Tests\Unit\Services;

use App\Services\ApiResponseService;
use Tests\TestCase;

class ApiResponseServiceTest extends TestCase
{
    public function test_success_response_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'successResponse'));
    }

    public function test_error_response_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'errorResponse'));
    }

    public function test_validation_error_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'validationError'));
    }

    public function test_unauthorized_response_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'unauthorizedResponse'));
    }

    public function test_no_permission_then_redirect_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'noPermissionThenRedirect'));
    }

    public function test_no_permission_then_send_json_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'noPermissionThenSendJson'));
    }

    public function test_no_any_permission_then_send_json_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'noAnyPermissionThenSendJson'));
    }

    public function test_log_error_response_method_exists()
    {
        $this->assertTrue(method_exists(ApiResponseService::class, 'logErrorResponse'));
    }

    public function test_service_class_is_instantiable()
    {
        $this->assertTrue(class_exists(ApiResponseService::class));
    }
}
