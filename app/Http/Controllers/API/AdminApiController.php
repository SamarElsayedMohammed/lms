<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Services\ApiResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminApiController extends Controller
{
    /**
     * Get all assignment submissions for admin
     */
    public function getAssignmentSubmissions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'nullable|exists:courses,id',
                'assignment_id' => 'nullable|exists:course_chapter_assignments,id',
                'instructor_id' => 'nullable|exists:users,id',
                'status' => 'nullable|in:pending,submitted,accepted,rejected,suspended',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort_by' => 'nullable|in:id,created_at,points',
                'sort_order' => 'nullable|in:asc,desc',
                'search' => 'nullable|string|max:255',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if user is admin
            if (!Auth::user()->hasRole('Admin')) {
                return ApiResponseService::unauthorizedResponse('Only admins can view all assignment submissions.');
            }

            // Build query for all assignment submissions
            $query = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment.chapter.course:id,title,slug,user_id',
                'assignment.chapter.course.user:id,name,email', // Instructor details
                'files',
            ]);

            // Filter by course
            if ($request->filled('course_id')) {
                $query->whereHas('assignment.chapter', static function ($chapterQuery) use ($request): void {
                    $chapterQuery->where('course_id', $request->course_id);
                });
            }

            // Filter by assignment
            if ($request->filled('assignment_id')) {
                $query->where('course_chapter_assignment_id', $request->assignment_id);
            }

            // Filter by instructor
            if ($request->filled('instructor_id')) {
                $query->whereHas('assignment.chapter.course', static function ($courseQuery) use ($request): void {
                    $courseQuery->where('user_id', $request->instructor_id);
                });
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->whereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignment', static function ($assignmentQuery) use ($search): void {
                            $assignmentQuery->where('title', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignment.chapter.course', static function ($courseQuery) use ($search): void {
                            $courseQuery->where('title', 'LIKE', "%{$search}%");
                        })
                        ->orWhere('comment', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';

            // Map submitted_at to created_at since submitted_at column doesn't exist
            if ($sortField === 'submitted_at') {
                $sortField = 'created_at';
            }

            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $submissions = $query->paginate($perPage);

            if ($submissions->isEmpty()) {
                return ApiResponseService::validationError('No assignment submissions found');
            }

            // Transform data
            $submissions
                ->getCollection()
                ->transform(static fn($submission) => [
                    'id' => $submission->id,
                    'user' => [
                        'id' => $submission->user->id,
                        'name' => $submission->user->name,
                        'email' => $submission->user->email,
                        'profile' => $submission->user->profile,
                    ],
                    'assignment' => [
                        'id' => $submission->assignment->id,
                        'title' => $submission->assignment->title,
                        'points' => $submission->assignment->points,
                    ],
                    'course' => [
                        'id' => $submission->assignment->chapter->course->id,
                        'title' => $submission->assignment->chapter->course->title,
                        'slug' => $submission->assignment->chapter->course->slug,
                    ],
                    'instructor' => [
                        'id' => $submission->assignment->chapter->course->user->id,
                        'name' => $submission->assignment->chapter->course->user->name,
                        'email' => $submission->assignment->chapter->course->user->email,
                    ],
                    'status' => $submission->status,
                    'comment' => $submission->comment,
                    'points' => $submission->points,
                    'feedback' => $submission->feedback,
                    'submitted_at' => $submission->created_at,
                    'updated_at' => $submission->updated_at,
                    'files' => $submission->files->map(static fn($file) => [
                        'id' => $file->id,
                        'type' => $file->type,
                        'file' => !empty($file->file) ? \App\Services\FileService::getFileUrl($file->file) : null,
                        'url' => $file->url,
                        'file_extension' => $file->file_extension,
                    ]),
                ]);

            return ApiResponseService::successResponse('Assignment submissions retrieved successfully', $submissions);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get assignment submissions');
            return ApiResponseService::errorResponse('Failed to retrieve assignment submissions');
        }
    }

    /**
     * Get assignment submission details for admin
     */
    public function getAssignmentSubmissionDetails(Request $request, $submissionId = null)
    {
        try {
            $id = $submissionId ?: $request->get('id');

            if (!$id) {
                return ApiResponseService::validationError('Submission ID is required');
            }

            // Check if user is admin
            if (!Auth::user()->hasRole('Admin')) {
                return ApiResponseService::unauthorizedResponse('Only admins can view assignment submission details.');
            }

            // Get submission with all relationships
            $submission = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment.chapter.course:id,title,slug,user_id',
                'assignment.chapter.course.user:id,name,email',
                'files',
            ])->where('id', $id)->first();

            if (!$submission) {
                return ApiResponseService::validationError('Assignment submission not found');
            }

            $response = [
                'id' => $submission->id,
                'user' => [
                    'id' => $submission->user->id,
                    'name' => $submission->user->name,
                    'email' => $submission->user->email,
                    'profile' => $submission->user->profile,
                ],
                'assignment' => [
                    'id' => $submission->assignment->id,
                    'title' => $submission->assignment->title,
                    'description' => $submission->assignment->description,
                    'instructions' => $submission->assignment->instructions,
                    'points' => $submission->assignment->points,
                ],
                'course' => [
                    'id' => $submission->assignment->chapter->course->id,
                    'title' => $submission->assignment->chapter->course->title,
                    'slug' => $submission->assignment->chapter->course->slug,
                ],
                'instructor' => [
                    'id' => $submission->assignment->chapter->course->user->id,
                    'name' => $submission->assignment->chapter->course->user->name,
                    'email' => $submission->assignment->chapter->course->user->email,
                ],
                'status' => $submission->status,
                'comment' => $submission->comment,
                'points' => $submission->points,
                'feedback' => $submission->feedback,
                'submitted_at' => $submission->created_at,
                'updated_at' => $submission->updated_at,
                'files' => $submission->files->map(static fn($file) => [
                    'id' => $file->id,
                    'type' => $file->type,
                    'file' => $file->file,
                    'url' => $file->url,
                    'file_extension' => $file->file_extension,
                ]),
            ];

            return ApiResponseService::successResponse(
                'Assignment submission details retrieved successfully',
                $response,
            );
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get assignment submission details');
            return ApiResponseService::errorResponse('Failed to retrieve assignment submission details');
        }
    }

    /**
     * Update assignment submission status by admin
     */
    public function updateAssignmentSubmission(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'submission_id' => 'required|exists:user_assignment_submissions,id',
                'status' => 'required|in:accepted,rejected',
                'points' => 'nullable|numeric|min:0',
                'comment' => 'nullable|string|max:1000',
                'admin_comment' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if user is admin
            if (!Auth::user()->hasRole('Admin')) {
                return ApiResponseService::unauthorizedResponse('Only admins can update assignment submissions.');
            }

            // Get submission
            $submission = UserAssignmentSubmission::with(['assignment.chapter.course', 'user'])->where(
                'id',
                $request->submission_id,
            )->first();

            if (!$submission) {
                return ApiResponseService::validationError('Assignment submission not found');
            }

            // Prepare update data
            $updateData = [
                'status' => $request->status,
            ];

            // Add points if provided and status is accepted
            if ($request->status === 'accepted' && $request->has('points')) {
                $updateData['points'] = $request->points;
            }

            // Add comment if provided
            if ($request->has('comment')) {
                $updateData['comment'] = $request->comment;
            }

            // Add admin comment if provided
            if ($request->has('admin_comment')) {
                $updateData['admin_comment'] = $request->admin_comment;
            }

            $submission->update($updateData);

            // Load updated submission with relationships
            $submission->load(['user:id,name,email', 'assignment.chapter.course:id,title', 'files']);

            $response = [
                'id' => $submission->id,
                'user' => [
                    'id' => $submission->user->id,
                    'name' => $submission->user->name,
                    'email' => $submission->user->email,
                ],
                'assignment' => [
                    'id' => $submission->assignment->id,
                    'title' => $submission->assignment->title,
                    'max_points' => $submission->assignment->points,
                ],
                'course' => [
                    'id' => $submission->assignment->chapter->course->id,
                    'title' => $submission->assignment->chapter->course->title,
                ],
                'status' => $submission->status,
                'points' => $submission->points,
                'comment' => $submission->comment,
                'admin_comment' => $submission->admin_comment ?? null,
                'updated_at' => $submission->updated_at,
                'updated_by' => 'Admin',
            ];

            return ApiResponseService::successResponse(
                'Assignment submission updated successfully by admin',
                $response,
            );
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update assignment submission');
            return ApiResponseService::errorResponse('Failed to update assignment submission');
        }
    }

    /**
     * Get assignment submission statistics for admin dashboard
     */
    public function getAssignmentStatistics(Request $request)
    {
        try {
            // Check if user is admin
            if (!Auth::user()->hasRole('Admin')) {
                return ApiResponseService::unauthorizedResponse('Only admins can view assignment statistics.');
            }

            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'course_id' => 'nullable|exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Build base query
            $query = UserAssignmentSubmission::query();

            // Apply date filters
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Apply course filter
            if ($request->filled('course_id')) {
                $query->whereHas('assignment.chapter', static function ($chapterQuery) use ($request): void {
                    $chapterQuery->where('course_id', $request->course_id);
                });
            }

            // Get statistics
            $totalSubmissions = $query->count();
            $pendingSubmissions = (clone $query)->where('status', 'submitted')->count();
            $acceptedSubmissions = (clone $query)->where('status', 'accepted')->count();
            $rejectedSubmissions = (clone $query)->where('status', 'rejected')->count();

            // Get submissions by status
            $statusBreakdown = (clone $query)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Get submissions by course
            $courseBreakdown = (clone $query)
                ->join(
                    'course_chapter_assignments',
                    'user_assignment_submissions.course_chapter_assignment_id',
                    '=',
                    'course_chapter_assignments.id',
                )
                ->join('course_chapters', 'course_chapter_assignments.course_chapter_id', '=', 'course_chapters.id')
                ->join('courses', 'course_chapters.course_id', '=', 'courses.id')
                ->selectRaw('courses.title as course_name, COUNT(*) as count')
                ->groupBy('courses.id', 'courses.title')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->toArray();

            // Get recent submissions
            $recentSubmissions = (clone $query)
                ->with(['user:id,name,email', 'assignment.chapter.course:id,title'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($submission) => [
                    'id' => $submission->id,
                    'user_name' => $submission->user->name,
                    'course_title' => $submission->assignment->chapter->course->title,
                    'status' => $submission->status,
                    'submitted_at' => $submission->created_at,
                ]);

            $statistics = [
                'overview' => [
                    'total_submissions' => $totalSubmissions,
                    'pending_submissions' => $pendingSubmissions,
                    'accepted_submissions' => $acceptedSubmissions,
                    'rejected_submissions' => $rejectedSubmissions,
                    'acceptance_rate' => $totalSubmissions > 0
                        ? round(($acceptedSubmissions / $totalSubmissions) * 100, 2)
                        : 0,
                ],
                'status_breakdown' => $statusBreakdown,
                'course_breakdown' => $courseBreakdown,
                'recent_submissions' => $recentSubmissions,
            ];

            return ApiResponseService::successResponse('Assignment statistics retrieved successfully', $statistics);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get assignment statistics');
            return ApiResponseService::errorResponse('Failed to retrieve assignment statistics');
        }
    }

    /**
     * Bulk update assignment submissions
     */
    public function bulkUpdateAssignmentSubmissions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'submission_ids' => 'required|array|min:1',
                'submission_ids.*' => 'exists:user_assignment_submissions,id',
                'status' => 'required|in:accepted,rejected',
                'points' => 'nullable|numeric|min:0',
                'feedback' => 'nullable|string|max:1000',
                'admin_comment' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if user is admin
            if (!Auth::user()->hasRole('Admin')) {
                return ApiResponseService::unauthorizedResponse('Only admins can bulk update assignment submissions.');
            }

            $submissionIds = $request->submission_ids;
            $status = $request->status;

            // Prepare update data
            $updateData = [
                'status' => $status,
            ];

            if ($status === 'accepted' && $request->has('points')) {
                $updateData['points'] = $request->points;
            }

            if ($status === 'rejected' && $request->has('feedback')) {
                $updateData['feedback'] = $request->feedback;
            }

            if ($request->has('admin_comment')) {
                $updateData['admin_comment'] = $request->admin_comment;
            }

            // Update submissions
            $updatedCount = UserAssignmentSubmission::whereIn('id', $submissionIds)->update($updateData);

            return ApiResponseService::successResponse("Successfully updated {$updatedCount} assignment submissions", [
                'updated_count' => $updatedCount,
                'status' => $status,
                'submission_ids' => $submissionIds,
            ]);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to bulk update assignment submissions');
            return ApiResponseService::errorResponse('Failed to bulk update assignment submissions');
        }
    }
}
