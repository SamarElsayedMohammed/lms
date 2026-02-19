<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentFile;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Order;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserAssignmentSubmissionController extends Controller
{
    /**
     * Submit an assignment
     */
    public function submitAssignment(Request $request)
    {
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'assignment_id' => 'required|exists:course_chapter_assignments,id',
                    'files' => 'nullable|array',
                    'files.*' => 'file|max:10240', // 10MB max per file
                    'urls' => 'nullable|array',
                    'urls.*' => 'url',
                    'comment' => 'required|string|max:1000',
                ],
                [
                    'assignment_id.required' => 'Assignment ID is required',
                    'comment.required' => 'Comment is required',
                    'files.array' => 'Files must be an array',
                    'files.*.file' => 'Each file must be a valid file',
                    'files.*.max' => 'Each file must not exceed 10MB',
                    'urls.array' => 'URLs must be an array',
                    'urls.*.url' => 'Each URL must be a valid URL',
                ],
            );

            // Custom validation: require either files or URLs
            $validator->after(static function ($validator) use ($request): void {
                $files = $request->file('files');
                $urls = $request->urls ?? [];

                $hasFiles = is_array($files) && count($files) > 0;
                $hasUrls = is_array($urls) && count(array_filter($urls)) > 0;

                if (!$hasFiles && !$hasUrls) {
                    $validator->errors()->add('files', 'You must submit either files or URLs for the assignment');
                    $validator->errors()->add('urls', 'You must submit either files or URLs for the assignment');
                }
            });

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();
            $assignment = CourseChapterAssignment::findOrFail($request->assignment_id);

            // Check if user has purchased the course
            $course = $assignment->chapter->course;
            $hasPurchased = Order::where('user_id', $user?->id)
                ->whereHas('orderCourses', static function ($query) use ($course): void {
                    $query->where('course_id', $course->id);
                })
                ->where('status', 'completed')
                ->exists();

            if (!$hasPurchased) {
                ApiResponseService::errorResponse('You must purchase this course to submit assignments');
            }

            // Check if user already has a submission for this assignment
            $existingSubmission = UserAssignmentSubmission::where('user_id', $user->id)
                ->where('course_chapter_assignment_id', $assignment->id)
                ->first();

            // If suspended, prevent resubmission
            if ($existingSubmission && $existingSubmission->status === 'suspended') {
                ApiResponseService::errorResponse('You cannot resubmit this assignment as it has been suspended');
            }

            // If rejected, allow resubmission - update existing submission
            if ($existingSubmission && $existingSubmission->status === 'rejected') {
                // Load files if not already loaded
                if (!$existingSubmission->relationLoaded('files')) {
                    $existingSubmission->load('files');
                }

                // Delete existing files from storage
                foreach ($existingSubmission->files as $file) {
                    if (!($file->type === 'file' && $file->file)) {
                        continue;
                    }

                    Storage::disk('public')->delete($file->file);
                }
                $existingSubmission->files()->delete();

                // Update submission status to submitted
                $existingSubmission->update([
                    'status' => 'submitted',
                    'points' => 0,
                    'comment' => $request->comment,
                    'feedback' => null, // Clear previous feedback
                ]);

                $submission = $existingSubmission;
            } elseif ($existingSubmission) {
                // If submission exists with other status (accepted, submitted, etc.), prevent resubmission
                ApiResponseService::errorResponse('You have already submitted this assignment');
            } else {
                // Create new submission
                $submission = UserAssignmentSubmission::create([
                    'user_id' => $user->id,
                    'course_chapter_assignment_id' => $assignment->id,
                    'status' => 'submitted',
                    'points' => 0,
                    'comment' => $request->comment,
                ]);
            }

            // Handle file uploads
            if ($request->hasFile('files')) {
                $files = $request->file('files');
                if (is_array($files) && count($files) > 0) {
                    foreach ($files as $file) {
                        if (!($file && $file->isValid())) {
                            continue;
                        }

                        $fileName = time() . '_' . $file->getClientOriginalName();
                        $filePath = $file->storeAs('assignments/' . $assignment->id, $fileName, 'public');

                        UserAssignmentFile::create([
                            'user_id' => $user->id,
                            'user_assignment_submission_id' => $submission->id,
                            'type' => 'file',
                            'file' => $filePath,
                            'file_extension' => $file->getClientOriginalExtension(),
                        ]);
                    }
                }
            }

            // Handle URLs
            if ($request->has('urls') && is_array($request->urls)) {
                foreach ($request->urls as $url) {
                    if (empty($url)) {
                        continue;
                    }

                    UserAssignmentFile::create([
                        'user_id' => $user->id,
                        'user_assignment_submission_id' => $submission->id,
                        'type' => 'url',
                        'url' => $url,
                    ]);
                }
            }

            $submission->load(['files', 'assignment.chapter.course']);

            ApiResponseService::successResponse('Assignment submitted successfully', $submission);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to submit assignment');
            ApiResponseService::errorResponse('Failed to submit assignment');
        }
    }

    /**
     * Get user's assignment submissions
     */
    public function getUserSubmissions(Request $request)
    {
        try {
            // Validate request parameters
            $validator = Validator::make(
                $request->all(),
                [
                    'course_id' => 'nullable|integer|exists:courses,id',
                    'status' => 'nullable|in:pending,submitted,accepted,rejected,suspended',
                    'per_page' => 'nullable|integer|min:1|max:100',
                ],
                [
                    'course_id.exists' => 'Course not found',
                    'status.in' => 'Invalid status. Must be one of: pending, submitted, accepted, rejected, suspended',
                    'per_page.min' => 'Per page must be at least 1',
                    'per_page.max' => 'Per page cannot exceed 100',
                ],
            );

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();

            $query = UserAssignmentSubmission::where('user_id', $user?->id)->with([
                'assignment.chapter.course',
                'files',
            ]);

            // Filter by course if provided
            if ($request->has('course_id') && $request->course_id) {
                $query->whereHas('assignment.chapter', static function ($q) use ($request): void {
                    $q->where('course_id', $request->course_id);
                });
            }

            // Filter by status if provided
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Set pagination
            $perPage = $request->get('per_page', 10);

            $submissions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            ApiResponseService::successResponse('Submissions retrieved successfully', $submissions);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve submissions');
            ApiResponseService::errorResponse('Failed to retrieve submissions');
        }
    }

    /**
     * Get specific submission details
     */
    public function getSubmissionDetails(Request $request, $id = null)
    {
        try {
            // Get submission ID from path parameter or query parameter
            $id = $id ?: $request->get('id');

            if (!$id) {
                ApiResponseService::validationError('Submission ID is required', ['id' => [
                    'Submission ID is required',
                ]]);
            }

            // Validate request parameters
            $validator = Validator::make(
                ['submission_id' => $id],
                [
                    'submission_id' => 'required|integer|exists:user_assignment_submissions,id',
                ],
                [
                    'submission_id.required' => 'Submission ID is required',
                    'submission_id.integer' => 'Submission ID must be a number',
                    'submission_id.exists' => 'Submission not found',
                ],
            );

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();

            $submission = UserAssignmentSubmission::where('id', $id)
                ->where('user_id', $user?->id)
                ->with(['assignment.chapter.course', 'files'])
                ->first();

            if (!$submission) {
                ApiResponseService::errorResponse('Submission not found');
            }

            ApiResponseService::successResponse('Submission details retrieved successfully', $submission);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve submission details');
            ApiResponseService::errorResponse('Failed to retrieve submission details');
        }
    }

    /**
     * Get available assignments for a course
     */
    public function getCourseAssignments(Request $request, $courseId)
    {
        try {
            // Validate request parameters
            $validator = Validator::make(
                ['course_id' => $courseId],
                [
                    'course_id' => 'required|integer|exists:courses,id',
                ],
                [
                    'course_id.required' => 'Course ID is required',
                    'course_id.integer' => 'Course ID must be a number',
                    'course_id.exists' => 'Course not found',
                ],
            );

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();

            // Check if user has purchased the course
            $hasPurchased = Order::where('user_id', $user?->id)
                ->whereHas('orderCourses', static function ($query) use ($courseId): void {
                    $query->where('course_id', $courseId);
                })
                ->where('status', 'completed')
                ->exists();

            if (!$hasPurchased) {
                ApiResponseService::errorResponse('You must purchase this course to view assignments');
            }

            $assignments = CourseChapterAssignment::whereHas('chapter', static function ($query) use ($courseId): void {
                $query->where('course_id', $courseId);
            })
                ->where('is_active', true)
                ->with([
                    'chapter',
                    'submissions' => static function ($query) use ($user): void {
                        $query->where('user_id', $user?->id);
                    },
                ])
                ->get();

            // Add submission status to each assignment
            $assignments->each(static function ($assignment): void {
                $assignment->user_submission = $assignment->submissions->first();
                $assignment->unsetRelation('submissions');
            });

            ApiResponseService::successResponse('Course assignments retrieved successfully', $assignments);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve course assignments');
            ApiResponseService::errorResponse('Failed to retrieve course assignments');
        }
    }

    /**
     * Update submission (only if status is pending)
     */
    public function updateSubmission(Request $request, $id = null)
    {
        try {
            // Get submission ID from path parameter or query parameter
            $id = $id ?: $request->get('id');

            if (!$id) {
                ApiResponseService::validationError('Submission ID is required', ['id' => [
                    'Submission ID is required',
                ]]);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'files.*' => 'nullable|file|max:10240',
                    'urls.*' => 'nullable|url',
                    'comment' => 'required|string|max:1000',
                ],
                [
                    'comment.required' => 'Comment is required',
                    'files.required_without' => 'Either files or URLs must be provided',
                    'urls.required_without' => 'Either files or URLs must be provided',
                ],
            );

            // Custom validation: require either files or URLs
            $validator->after(static function ($validator) use ($request): void {
                if (!$request->hasFile('files') && (!$request->has('urls') || empty(array_filter($request->urls)))) {
                    $validator->errors()->add('files', 'You must submit either files or URLs for the assignment');
                    $validator->errors()->add('urls', 'You must submit either files or URLs for the assignment');
                }
            });

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();
            $submission = UserAssignmentSubmission::where('id', $id)->where('user_id', $user?->id)->first();

            if (!$submission) {
                ApiResponseService::errorResponse('Submission not found or you do not have permission to update it');
            }

            // If suspended, prevent update
            if ($submission->status === 'suspended') {
                ApiResponseService::errorResponse('Cannot update submission that has been suspended');
            }

            // Allow update if status is pending, submitted, or rejected (for resubmission)
            if (!in_array($submission->status, ['pending', 'submitted', 'rejected'])) {
                ApiResponseService::errorResponse('Cannot update submission with current status');
            }

            // If status is rejected, change to submitted when updating
            $updateData = [
                'comment' => $request->comment,
            ];

            if ($submission->status === 'rejected') {
                $updateData['status'] = 'submitted';
                $updateData['points'] = 0;
                $updateData['feedback'] = null; // Clear previous feedback
            }

            // Update submission
            $submission->update($updateData);

            // Load files if not already loaded
            if (!$submission->relationLoaded('files')) {
                $submission->load('files');
            }

            // Delete existing files from storage
            foreach ($submission->files as $file) {
                if (!($file->type === 'file' && $file->file)) {
                    continue;
                }

                Storage::disk('public')->delete($file->file);
            }

            // Delete existing files from database
            $submission->files()->delete();

            // Handle new file uploads
            if ($request->hasFile('files')) {
                $files = $request->file('files');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (!($file && $file->isValid())) {
                            continue;
                        }

                        $fileName = time() . '_' . $file->getClientOriginalName();
                        $filePath = $file->storeAs('assignments/' . $submission->assignment->id, $fileName, 'public');

                        UserAssignmentFile::create([
                            'user_id' => $user->id,
                            'user_assignment_submission_id' => $submission->id,
                            'type' => 'file',
                            'file' => $filePath,
                            'file_extension' => $file->getClientOriginalExtension(),
                        ]);
                    }
                }
            }

            // Handle new URLs
            if ($request->has('urls') && is_array($request->urls)) {
                foreach ($request->urls as $url) {
                    if (empty($url)) {
                        continue;
                    }

                    UserAssignmentFile::create([
                        'user_id' => $user->id,
                        'user_assignment_submission_id' => $submission->id,
                        'type' => 'url',
                        'url' => $url,
                    ]);
                }
            }

            $submission->load(['files', 'assignment.chapter.course']);

            ApiResponseService::successResponse('Submission updated successfully', $submission);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update submission');
            ApiResponseService::errorResponse('Failed to update submission');
        }
    }

    /**
     * Delete submission (only if status is pending)
     */
    public function deleteSubmission(Request $request, $id = null)
    {
        try {
            // Get submission ID from path parameter or query parameter
            $id = $id ?: $request->get('id');

            if (!$id) {
                ApiResponseService::validationError('Submission ID is required', ['id' => [
                    'Submission ID is required',
                ]]);
            }

            // Validate request parameters
            $validator = Validator::make(
                ['submission_id' => $id],
                [
                    'submission_id' => 'required|integer|exists:user_assignment_submissions,id',
                ],
                [
                    'submission_id.required' => 'Submission ID is required',
                    'submission_id.integer' => 'Submission ID must be a number',
                    'submission_id.exists' => 'Submission not found',
                ],
            );

            if ($validator->fails()) {
                ApiResponseService::validationError('Validation failed', $validator->errors());
            }

            $user = Auth::user();
            $submission = UserAssignmentSubmission::where('id', $id)->where('user_id', $user?->id)->first();

            if (!$submission) {
                ApiResponseService::errorResponse('Submission not found');
            }

            if ($submission->status !== 'pending') {
                ApiResponseService::errorResponse('Cannot delete submission that is not pending');
            }

            // Delete associated files from storage
            foreach ($submission->files as $file) {
                if (!($file->type === 'file' && $file->file)) {
                    continue;
                }

                Storage::disk('public')->delete($file->file);
            }

            // Delete submission and related files
            $submission->files()->delete();
            $submission->delete();

            ApiResponseService::successResponse('Submission deleted successfully');
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to delete submission');
            ApiResponseService::errorResponse('Failed to delete submission');
        }
    }
}
