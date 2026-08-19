<?php

namespace App\Http\Controllers\API\Concerns;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseChapterLectureResource;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\QuizOption;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Models\Course\CourseDiscussion;
use App\Models\Course\CourseLanguage;
use App\Models\Course\UserCourseTrack;
use App\Models\CourseView;
use App\Models\FeatureSection;
use App\Models\HelpdeskQuestion;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Rating;
use App\Models\RefundRequest;
use App\Models\SearchHistory;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use App\Services\ApiResponseService;
use App\Services\CertificateService;
use App\Services\CourseProgressService;
use App\Services\EarningsService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\PricingCalculationService;
use App\Services\UserEnrollmentService;
use Carbon\Carbon;
use Exception;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

trait ServesCourseCertificates
{
    public function generateCourseCertificate(Request $request)
    {
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    "course_id" => "required|exists:courses,id",
                    "certificate_id" => "nullable|exists:certificates,id",
                ],
                [
                    "course_id.required" => "Course ID is required",
                    "course_id.exists" => "Course not found",
                    "certificate_id.exists" => "Certificate template not found",
                ],
            );

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            // Get authenticated user (should be available due to auth:sanctum middleware)
            $user = Auth::guard("sanctum")->user() ?? Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse(
                    "User not authenticated. Please provide a valid authorization token.",
                    null,
                    401,
                );
            }

            $courseId = $request->course_id;
            $certificateId = $request->certificate_id;

            $existingCertificate = CourseCertificate::with("course:id,title")
                ->where("user_id", $user->id)
                ->where("course_id", $courseId)
                ->first();

            if ($existingCertificate) {
                if ($existingCertificate->isRevoked()) {
                    return ApiResponseService::errorResponse(
                        "Your certificate for this course has been revoked. Please contact support.",
                        null,
                        403,
                    );
                }

                return ApiResponseService::successResponse(
                    "Certificate already exists",
                    [
                        "certificate_url" => url(
                            "/api/certificate/course/download?course_id={$courseId}",
                        ),
                        "certificate_data" => [
                            "certificate_number" =>
                                $existingCertificate->certificate_number,
                            "course_id" => $existingCertificate->course_id,
                            "course_name" =>
                                $existingCertificate->course->title ?? null,
                            "issued_date" => $existingCertificate->issued_date?->format(
                                "Y-m-d",
                            ),
                            "status" => $existingCertificate->status,
                        ],
                    ],
                );
            }

            // Generate certificate (service will check course completion using user_curriculum_trackings)
            $certificateService = new CertificateService();
            $result = $certificateService->generateCourseCompletionCertificate(
                $user->id,
                $courseId,
                $certificateId,
            );

            if ($result["success"]) {
                return ApiResponseService::successResponse(
                    "Certificate generated successfully",
                    [
                        "certificate_url" => $result["file_url"],
                        "certificate_data" => $result["certificate_data"],
                    ],
                );
            } else {
                return ApiResponseService::errorResponse(
                    "Failed to generate certificate: " . $result["error"],
                );
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> generateCourseCertificate Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to generate certificate",
            );
        }
    }

    /**
     * Generate exam completion certificate
     */
    public function generateExamCertificate(Request $request)
    {
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    "course_id" => "required|exists:courses,id",
                    "certificate_id" => "nullable|exists:certificates,id",
                ],
                [
                    "course_id.required" => "Course ID is required",
                    "course_id.exists" => "Course not found",
                    "certificate_id.exists" => "Certificate template not found",
                ],
            );

            if ($validator->fails()) {
                return ApiResponseService::validationError(
                    $validator->errors()->first(),
                );
            }

            $user = Auth::user();
            $courseId = $request->course_id;
            $certificateId = $request->certificate_id;

            // Check if user has completed the exam (you may need to implement this check)
            // For now, we'll assume exam completion is checked elsewhere
            $isExamCompleted = true; // Implement your exam completion logic here

            if (!$isExamCompleted) {
                return ApiResponseService::errorResponse(
                    "Exam must be completed before generating certificate",
                    null,
                    400,
                );
            }

            // Generate certificate
            $certificateService = new CertificateService();
            $result = $certificateService->generateExamCompletionCertificate(
                $user?->id,
                $courseId,
                $certificateId,
            );

            if ($result["success"]) {
                return ApiResponseService::successResponse(
                    "Certificate generated successfully",
                    [
                        "certificate_url" => $result["file_url"],
                        "certificate_data" => $result["certificate_data"],
                    ],
                );
            } else {
                return ApiResponseService::errorResponse(
                    "Failed to generate certificate: " . $result["error"],
                );
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> generateExamCertificate Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to generate certificate",
            );
        }
    }

    /**
     * Get available certificate templates
     */
    public function getCertificateTemplates(Request $request)
    {
        try {
            $type = $request->get("type"); // course_completion, exam_completion, or null for all

            $certificateService = new CertificateService();
            $templates = $certificateService->getAvailableTemplates($type);

            $formattedTemplates = $templates->map(
                static fn($certificate) => [
                    "id" => $certificate->id,
                    "name" => $certificate->name,
                    "type" => $certificate->type,
                    "title" => $certificate->title,
                    "subtitle" => $certificate->subtitle,
                    "background_image_url" =>
                        $certificate->background_image_url,
                    "template_settings" => $certificate->template_settings,
                    "signature_image_url" => $certificate->signature_image_url,
                    "signature_text" => $certificate->signature_text,
                    "is_active" => $certificate->is_active,
                    "created_at" => $certificate->created_at->format(
                        "Y-m-d H:i:s",
                    ),
                ],
            );

            return ApiResponseService::successResponse(
                "Certificate templates fetched successfully",
                $formattedTemplates,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                "API Course Controller -> getCertificateTemplates Method",
            );

            return ApiResponseService::errorResponse(
                "Failed to fetch certificate templates",
            );
        }
    }

    /**
     * Check if user has completed the course
     */
    private function checkCourseCompletion($userId, $courseId)
    {
        // Check if course exists
        $course = Course::find($courseId);
        if (!$course) {
            return false;
        }

        // Check if user has purchased/enrolled in the course
        $hasPurchased = Order::where("user_id", $userId)
            ->whereHas("orderCourses", static function ($query) use (
                $courseId,
            ): void {
                $query->where("course_id", $courseId);
            })
            ->where("status", "completed")
            ->exists();

        if (!$hasPurchased) {
            return false;
        }

        // Use the same logic as checkCourseCompletion API
        // Check if all curriculum items are completed
        $course = Course::with([
            "chapters" => static function ($query): void {
                $query->where("is_active", 1)->orderBy("chapter_order");
            },
            "chapters.lectures" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.quizzes" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.assignments" => static function ($query): void {
                $query->where("is_active", 1);
            },
            "chapters.resources" => static function ($query): void {
                $query->where("is_active", 1);
            },
        ])->find($courseId);

        if (!$course) {
            return false;
        }

        // Count total curriculum items
        $totalLectures = 0;
        $totalQuizzes = 0;
        $totalResources = 0;

        foreach ($course->chapters as $chapter) {
            $totalLectures += $chapter->lectures->count();
            $totalQuizzes += $chapter->quizzes->count();
            $totalResources += $chapter->resources->count();
        }

        // Check completed items from user_curriculum_trackings
        $completedTracking = UserCurriculumTracking::where("user_id", $userId)
            ->whereIn("course_chapter_id", $course->chapters->pluck("id"))
            ->where("status", "completed")
            ->get();

        $completedLectures = $completedTracking
            ->where("model_type", CourseChapterLecture::class)
            ->count();
        $completedQuizzes = $completedTracking
            ->where("model_type", CourseChapterQuiz::class)
            ->count();
        $completedResources = $completedTracking
            ->where("model_type", CourseChapterResource::class)
            ->count();

        // Check if all curriculum items are completed
        $curriculumItemsTotal =
            $totalLectures + $totalQuizzes + $totalResources;
        $curriculumItemsCompleted =
            $completedLectures + $completedQuizzes + $completedResources;
        $allCurriculumCompleted =
            $curriculumItemsTotal == 0 ||
            $curriculumItemsCompleted >= $curriculumItemsTotal;

        // Check assignment submissions (must be submitted or accepted, or can_skip = 1)
        $assignmentIds = [];
        $skippableAssignmentIds = [];
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->assignments as $assignment) {
                $assignmentIds[] = $assignment->id;
                if ($assignment->can_skip) {
                    $skippableAssignmentIds[] = $assignment->id;
                }
            }
        }

        $totalAssignments = count($assignmentIds);
        $skippableAssignments = count($skippableAssignmentIds);
        $submittedAssignments = 0;

        if (!empty($assignmentIds)) {
            // Count assignments that have been submitted/accepted (excluding skippable ones)
            $nonSkippableAssignmentIds = array_diff(
                $assignmentIds,
                $skippableAssignmentIds,
            );
            if (!empty($nonSkippableAssignmentIds)) {
                $submittedAssignments = UserAssignmentSubmission::where(
                    "user_id",
                    $userId,
                )
                    ->whereIn(
                        "course_chapter_assignment_id",
                        $nonSkippableAssignmentIds,
                    )
                    ->whereIn("status", ["submitted", "accepted"])
                    ->count();
            }
        }

        $allAssignmentsSubmitted = \App\Services\CourseCompletionService::allAssignmentsSubmitted(
            $totalAssignments,
            $skippableAssignments,
            $submittedAssignments,
        );

        // Course is completed only if both conditions are met
        return $allCurriculumCompleted && $allAssignmentsSubmitted;
    }

    /**
     * Get category IDs including parent and all child categories (recursively)
     */
    private function getCategoryIdsWithChildren(array $categorySlugs): array
    {
        // Trim and filter empty slugs
        $categorySlugs = array_filter(array_map("trim", $categorySlugs));

        if (empty($categorySlugs)) {
            return [];
        }

        // Find categories by slugs
        $categories = Category::whereIn("slug", $categorySlugs)->get();

        if ($categories->isEmpty()) {
            return [];
        }

        $categoryIds = $categories->pluck("id")->toArray();

        // Recursively get all child category IDs
        $allCategoryIds = $categoryIds;
        foreach ($categories as $category) {
            $childIds = $this->getAllChildCategoryIds($category->id);
            $allCategoryIds = array_merge($allCategoryIds, $childIds);
        }

        // Remove duplicates and return
        return array_unique($allCategoryIds);
    }

    /**
     * Recursively get all child category IDs for a given category ID (with cycle protection)
     */
    private function getAllChildCategoryIds(int $categoryId, array &$visited = [], int $depth = 0): array
    {
        if ($depth > 10 || in_array($categoryId, $visited, true)) {
            return [];
        }
        $visited[] = $categoryId;

        $childIds = [];

        // Get direct children
        $children = Category::where("parent_category_id", $categoryId)->get();

        foreach ($children as $child) {
            $childId = (int) $child->id;
            if (!in_array($childId, $visited, true)) {
                $childIds[] = $childId;
                // Recursively get grandchildren
                $grandchildIds = $this->getAllChildCategoryIds($childId, $visited, $depth + 1);
                $childIds = array_merge($childIds, $grandchildIds);
            }
        }

        return array_unique($childIds);
    }

    /**
     * Get detailed progress for a specific course (user)
     * GET /api/my-learning/{course_id}
     */
    public function getMyCourseProgressDetail(int $courseId): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse(
                    "Authentication required.",
                    [],
                    401,
                );
            }

            $course = Course::find($courseId);
            if (!$course) {
                return ApiResponseService::errorResponse("Course not found.", [], 404);
            }

            $contentAccessService = app(\App\Services\ContentAccessService::class);
            if (!$contentAccessService->canAccessCourse($user, $course)) {
                return ApiResponseService::errorResponse(
                    "You are not enrolled in this course or your subscription has expired.",
                    [],
                    403,
                );
            }

            $details = $this->progressService->getDetailedProgress(
                $user->id,
                $courseId,
            );

            // Check if there was an error in the response
            if (isset($details["error"])) {
                return ApiResponseService::errorResponse(
                    "Failed to retrieve progress: " . $details["error"],
                    ["debug" => $details["debug"] ?? null],
                    500,
                );
            }

            return ApiResponseService::successResponse(
                "Course progress retrieved successfully.",
                $details,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Failed to get course progress details", [
                "user_id" => Auth::id(),
                "course_id" => $courseId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse(
                "Failed to retrieve progress: " . $e->getMessage(),
                [
                    "debug" => [
                        "message" => $e->getMessage(),
                        "file" => $e->getFile(),
                        "line" => $e->getLine(),
                    ],
                ],
                500,
            );
        }
    }
}
