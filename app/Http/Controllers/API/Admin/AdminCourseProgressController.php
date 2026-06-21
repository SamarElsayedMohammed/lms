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

class AdminCourseProgressController extends Controller
{
    public function __construct(
        private readonly CourseProgressService $progressService,
    ) {}

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

            return ApiResponseService::successResponse('Enrollment overview retrieved successfully.', $overview);

        } catch (\Throwable $e) {
            Log::error('Failed to get enrollment overview', [
                'admin_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            return ApiResponseService::errorResponse('Failed to retrieve overview.', [], 500);
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
