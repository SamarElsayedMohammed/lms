<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\CourseProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminCourseProgressController extends AdminCrudApiController
{
    public function __construct(
        private readonly CourseProgressService $progressService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->ensureAdmin();
            return $next($request);
        });
    }

    /**
     * Get enrollment overview for all courses
     * GET /admin/api/courses/enrollment-overview
     */
    public function getEnrollmentOverview(Request $request): JsonResponse
    {
        try {
            $search = $request->input('search');
            $status = $request->input('status');

            $overview = $this->progressService->getAdminOverview($search, $status);

            // Check if there was an error in the service
            if (isset($overview['error_detail'])) {
                return ApiResponseService::errorResponse(
                    'Failed to retrieve overview: ' . $overview['error_detail']['message'],
                    ['debug' => $overview['error_detail']],
                    500
                );
            }

            return ApiResponseService::successResponse('Enrollment overview retrieved successfully.', $overview);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to get enrollment overview', [
                'admin_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse(
                'Failed to retrieve overview: ' . $e->getMessage(),
                [
                    'debug' => [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * Get student progress for a specific course
     * GET /admin/api/courses/{course_id}/student-progress
     */
    public function getCourseStudentProgress(Request $request, int $courseId): JsonResponse
    {
        try {
            $search = $request->input('search');
            $status = $request->input('status');

            $progress = $this->progressService->getAdminCourseStudentProgress($courseId, $search, $status);

            // Add course info
            $course = \App\Models\Course\Course::select(['id', 'name', 'thumbnail'])->find($courseId);
            
            return ApiResponseService::successResponse('Student progress retrieved successfully.', [
                'course' => $course,
                'students' => $progress,
            ]);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to get course student progress', [
                'admin_id' => Auth::id(),
                'course_id' => $courseId,
                'error' => $e->getMessage(),
            ]);
            return ApiResponseService::errorResponse('Failed to retrieve student progress.', [], 500);
        }
    }

    /**
     * Get detailed progress for a specific student in a course
     * GET /admin/api/courses/{course_id}/students/{user_id}/details
     */
    public function getStudentCourseDetails(int $courseId, int $userId): JsonResponse
    {
        try {
            $details = $this->progressService->getDetailedProgress($userId, $courseId);

            // Add user info
            $user = \App\Models\User::select(['id', 'name', 'email', 'phone', 'avatar'])->find($userId);
            $details['user'] = $user;

            return ApiResponseService::successResponse('Student course details retrieved successfully.', $details);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to get student course details', [
                'admin_id' => Auth::id(),
                'course_id' => $courseId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ApiResponseService::errorResponse('Failed to retrieve details.', [], 500);
        }
    }
}
