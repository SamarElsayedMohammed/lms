<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\UserCourseChapterTrack;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\UserCurriculumTracking;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\ApiResponseService;
use App\Services\FeatureFlagService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CourseChapterApiController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService
    ) {}

    /**
     * Display a listing of the chapters for a course.
     */
    // public function index($courseId) {
    //     $course = Course::with('taxes')->findOrFail($courseId);
    //     $chapters = $course->chapters()->with('lectures')->get();

    //     return response()->json([
    //         'status' => 'success',
    //         'data' => $chapters
    //     ]);
    // }

    /**
     * Store a newly created chapter.
     */
    // public function store(Request $request, $courseId) {
    //     $course = Course::findOrFail($courseId);

    //     // Check if user is the instructor of this course
    //     if ($course->instructor_id !== Auth::id() && !Auth::user()->isAdmin()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'You do not have permission to add chapters to this course'
    //         ], 403);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'title' => 'required|string|max:255',
    //         'description' => 'nullable|string',
    //         'free_preview' => 'boolean',
    //         'is_active' => 'boolean',
    //         'order' => 'integer|min:0',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     $data = $validator->validated();
    //     $data['course_id'] = $courseId;

    //     // If order is not provided, make it the last one
    //     if (!isset($data['order'])) {
    //         $data['order'] = $course->chapters()->max('order') + 1;
    //     }

    //     $chapter = CourseChapter::create($data);

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Chapter created successfully',
    //         'data' => $chapter
    //     ], 201);
    // }

    /**
     * Display the specified chapter.
     */
    public function show($courseId, $chapterId)
    {
        $course = Course::with('taxes')->findOrFail($courseId);
        $chapter = $course->chapters()->with('lectures')->findOrFail($chapterId);

        return response()->json([
            'status' => 'success',
            'data' => $chapter,
        ]);
    }

    /**
     * Update the specified chapter.
     */
    // public function update(Request $request) {
    //     $course = Course::findOrFail($request->courseId);

    //     // Check if user is the instructor of this course
    //     if ($course->user_id !== Auth::id() ) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'You do not have permission to update this chapter'
    //         ], 403);
    //     }

    //     $chapter = $course->chapters()->findOrFail($request->chapterId);

    //     $validator = Validator::make($request->all(), [
    //         'title' => 'sometimes|required|string|max:255',
    //         'description' => 'nullable|string',
    //         'free_preview' => 'boolean',
    //         'is_active' => 'boolean',
    //         'order' => 'integer|min:0',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     $chapter->update($validator->validated());

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Chapter updated successfully',
    //         'data' => $chapter
    //     ]);
    // }

    /**
     * Remove the specified chapter.
     */
    // public function destroy($courseId, $chapterId) {
    //     $course = Course::findOrFail($courseId);

    //     // Check if user is the instructor of this course
    //     if ($course->instructor_id !== Auth::id() && !Auth::user()->isAdmin()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'You do not have permission to delete this chapter'
    //         ], 403);
    //     }

    //     $chapter = $course->chapters()->findOrFail($chapterId);
    //     $chapter->delete();

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Chapter deleted successfully'
    //     ]);
    // }

    /**
     * Reorder chapters
     */
    public function reorder(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if ($course->instructor_id !== Auth::id() && !Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to reorder chapters',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'chapters' => 'required|array',
            'chapters.*.id' => 'required|exists:course_chapters,id',
            'chapters.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->chapters as $item) {
            $chapter = CourseChapter::find($item['id']);

            // Check if chapter belongs to this course
            if ($chapter->course_id != $courseId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more chapters do not belong to this course',
                ], 400);
            }

            $chapter->order = $item['order'];
            $chapter->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Chapters reordered successfully',
        ]);
    }

    public function getAddedCourseChapters(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|exists:courses,id',
                'id' => 'nullable|exists:course_chapters,id',
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,title,description,free_preview,is_active,chapter_order',
                'sort_order' => 'nullable|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $course = Course::findOrFail($request->course_id);

            $authUser = Auth::user();
            $isAdmin = $authUser->hasRole('admin');
            $isCourseOwner = $course->user_id === $authUser?->id;

            // Check if user is a team member of the course instructor OR course owner is team member of auth instructor
            $isTeamMember = false;
            if (!$isCourseOwner && !$isAdmin) {
                // Case 1: Auth user is a team member of the course owner's instructor
                $courseOwnerInstructor = \App\Models\Instructor::where('user_id', $course->user_id)->first();

                if ($courseOwnerInstructor) {
                    $isTeamMember = \App\Models\TeamMember::where('instructor_id', $courseOwnerInstructor->id)
                        ->where('user_id', $authUser->id)
                        ->where('status', 'approved')
                        ->exists();
                }

                // Case 2: Auth user is an instructor and course owner is their team member
                if (!$isTeamMember) {
                    $authInstructor = \App\Models\Instructor::where('user_id', $authUser->id)->first();

                    if ($authInstructor) {
                        $isTeamMember = \App\Models\TeamMember::where('instructor_id', $authInstructor->id)
                            ->where('user_id', $course->user_id)
                            ->where('status', 'approved')
                            ->exists();
                    }
                }
            }

            // ✅ Authorization check: Admin, Course Owner, or Approved Team Member
            if (!$isAdmin && !$isCourseOwner && !$isTeamMember) {
                return ApiResponseService::validationError('You are not authorized to view chapters of this course');
            }

            $query = CourseChapter::where('course_id', $request->course_id);

            // If a specific chapter id is passed, filter by it
            if ($request->filled('id')) {
                $query->where('id', $request->id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q->where('title', 'LIKE', "%{$search}%")->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            $sortField = $request->sort_by ?? 'chapter_order';
            $sortOrder = $request->sort_order ?? 'asc';
            $query->orderBy($sortField, $sortOrder);

            $perPage = $request->per_page ?? 15;
            $page = $request->page ?? 1;

            // Ensure per_page is within valid range
            $perPage = max(1, min(100, (int) $perPage));
            $page = max(1, (int) $page);

            $chapters = $query->paginate($perPage, ['*'], 'page', $page);

            if ($chapters->isEmpty()) {
                return ApiResponseService::successResponse('No Chapters Found', [
                    'data' => [],
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 0,
                    'from' => null,
                    'to' => null,
                ]);
            }

            return ApiResponseService::successResponse('Chapters retrieved successfully', $chapters);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> getAddedCourseChapters Method');

            return ApiResponseService::errorResponse();
        }
    }

    /**
     * Soft delete a course chapter by id.
     */
    public function deleteCourseChapter(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|exists:course_chapters,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $chapter = CourseChapter::findOrFail($request->id);
            $course = $chapter->course;

            $authUser = Auth::user();
            $isAdmin = $authUser->hasRole('admin');
            $isCourseOwner = $course->user_id === $authUser?->id;

            // Check if user is a team member of the course instructor OR course owner is team member of auth instructor
            $isTeamMember = false;
            if (!$isCourseOwner && !$isAdmin) {
                // Case 1: Auth user is a team member of the course owner's instructor
                $courseOwnerInstructor = \App\Models\Instructor::where('user_id', $course->user_id)->first();

                if ($courseOwnerInstructor) {
                    $isTeamMember = \App\Models\TeamMember::where('instructor_id', $courseOwnerInstructor->id)
                        ->where('user_id', $authUser->id)
                        ->where('status', 'approved')
                        ->exists();
                }

                // Case 2: Auth user is an instructor and course owner is their team member
                if (!$isTeamMember) {
                    $authInstructor = \App\Models\Instructor::where('user_id', $authUser->id)->first();

                    if ($authInstructor) {
                        $isTeamMember = \App\Models\TeamMember::where('instructor_id', $authInstructor->id)
                            ->where('user_id', $course->user_id)
                            ->where('status', 'approved')
                            ->exists();
                    }
                }
            }

            if (!$isAdmin && !$isCourseOwner && !$isTeamMember) {
                return ApiResponseService::errorResponse('You do not have permission to delete this chapter.', 403);
            }

            $chapter->delete();

            return ApiResponseService::successResponse('Chapter deleted successfully');
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> deleteCourseChapter Method');

            return ApiResponseService::errorResponse('Failed to delete the chapter.');
        }
    }

    /**
     * Get course chapters with complete curriculum for students (public access)
     */
    public function getCourseChapters(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|exists:courses,id',
                'chapter_id' => 'nullable|exists:course_chapters,id',
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,title,description,chapter_order',
                'sort_order' => 'nullable|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Get authenticated user
            $user = Auth::user();

            // Check if course exists and is active
            $course = Course::where('id', $request->course_id)
                ->where('is_active', true)
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->first();

            if (!$course) {
                return ApiResponseService::validationError('Course not found or not available');
            }

            // Get user's curriculum completion tracking data
            $userCurriculumTracking = [];
            if ($user) {
                $chapterIds = $course->chapters->pluck('id')->toArray();
                $userCurriculumTracking = UserCurriculumTracking::where('user_id', $user->id)
                    ->whereIn('course_chapter_id', $chapterIds)
                    ->get()
                    ->groupBy(
                        static fn($item) => $item->course_chapter_id . '_' . $item->model_type . '_' . $item->model_id,
                    );
            }

            // Helper function to check if curriculum item is completed
            $isItemCompleted = static function ($chapterId, $modelType, $modelId) use ($userCurriculumTracking) {
                if (empty($userCurriculumTracking)) {
                    return false;
                }
                $key = $chapterId . '_' . $modelType . '_' . $modelId;

                return (
                    isset($userCurriculumTracking[$key])
                    && $userCurriculumTracking[$key]->first()->status === 'completed'
                );
            };

            // Build query for chapters
            $query = CourseChapter::where('course_id', $request->course_id)
                ->where('is_active', true)
                ->with([
                    // Lectures with their resources
                    'lectures' => static function ($lectureQuery): void {
                        $lectureQuery
                            ->where('is_active', true)
                            ->orderBy('chapter_order')
                            ->with(['resources' => static function ($resourceQuery): void {
                                $resourceQuery->where('is_active', true)->orderBy('order');
                            }]);
                    },
                    // Quizzes with questions and options
                    'quizzes' => static function ($quizQuery): void {
                        $quizQuery
                            ->where('is_active', true)
                            ->orderBy('chapter_order')
                            ->with([
                                'questions' => static function ($questionQuery): void {
                                    $questionQuery
                                        ->where('is_active', true)
                                        ->orderBy('order')
                                        ->with(['options' => static function ($optionQuery): void {
                                            $optionQuery->where('is_active', true)->orderBy('order');
                                        }]);
                                },
                            ]);
                    },
                    // Assignments
                    'assignments' => static function ($assignmentQuery): void {
                        $assignmentQuery->where('is_active', true)->orderBy('chapter_order');
                    },
                    // Resources (Documents)
                    'resources' => static function ($resourceQuery): void {
                        $resourceQuery->where('is_active', true)->orderBy('chapter_order');
                    },
                ]);

            // Filter by specific chapter if provided
            if ($request->filled('chapter_id')) {
                $query->where('id', $request->chapter_id);
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q->where('title', 'LIKE', "%{$search}%")->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField = $request->sort_by ?? 'chapter_order';
            $sortOrder = $request->sort_order ?? 'asc';
            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $chapters = $query->paginate($perPage);

            if ($chapters->isEmpty()) {
                return ApiResponseService::validationError('No chapters found for this course');
            }

            // Format the response with complete curriculum data
            $formattedChapters = $chapters->map(static function ($chapter) use ($isItemCompleted, $user) {
                // Combine all content types and sort by chapter_order
                $allContent = collect();

                // Add lectures
                $lectures = $chapter->lectures->map(static function ($lecture) use ($chapter, $isItemCompleted) {
                    $lectureData = (new \App\Http\Resources\CourseChapterLectureResource($lecture))->resolve();

                    // Add curriculum-specific fields
                    $lectureData['is_completed'] = $isItemCompleted(
                        $chapter->id,
                        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class,
                        $lecture->id,
                    );
                    $lectureData['has_resources'] = $lecture->resources->count() > 0;
                    $lectureData['resources'] = $lecture->resources->map(static fn($resource) => [
                        'id' => $resource->id,
                        'title' => $resource->title,
                        'type' => $resource->type,
                        'file' => $resource->file,
                        'file_extension' => $resource->file_extension,
                        'url' => $resource->url,
                        'file_url' => $resource->file_url,
                        'order' => $resource->order,
                        'is_active' => $resource->is_active,
                    ]);
                    $lectureData['created_at'] = $lecture->created_at;
                    $lectureData['updated_at'] = $lecture->updated_at;

                    return $lectureData;
                });
                $allContent = $allContent->merge($lectures);

                // Add quizzes
                $quizzes = $chapter->quizzes->map(static fn($quiz) => [
                    'id' => $quiz->id,
                    'type' => 'quiz',
                    'title' => $quiz->title,
                    'slug' => $quiz->slug,
                    'description' => $quiz->description,
                    'time_limit' => $quiz->time_limit,
                    'total_points' => $quiz->total_points,
                    'passing_score' => $quiz->passing_score,
                    'can_skip' => $quiz->can_skip,
                    'is_active' => $quiz->is_active,
                    'chapter_order' => $quiz->chapter_order,
                    'is_completed' => $isItemCompleted(
                        $chapter->id,
                        \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class,
                        $quiz->id,
                    ),
                    'has_questions' => $quiz->questions->count() > 0,
                    'questions' => $quiz->questions->map(static fn($question) => [
                        'id' => $question->id,
                        'question' => $question->question,
                        'points' => $question->points,
                        'order' => $question->order,
                        'is_active' => $question->is_active,
                        'options' => $question->options->map(static fn($option) => [
                            'id' => $option->id,
                            'option' => $option->option,
                            'order' => $option->order,
                            'is_active' => $option->is_active,
                        ]),
                    ]),
                    'created_at' => $quiz->created_at,
                    'updated_at' => $quiz->updated_at,
                ]);
                $allContent = $allContent->merge($quizzes);

                // Add assignments
                $assignments = $chapter->assignments->map(static function ($assignment) use (
                    $chapter,
                    $isItemCompleted,
                    $user,
                ) {
                    // Get assignment submission status for the user
                    $submissionStatus = null;
                    $submissionId = null;
                    $submittedAt = null;

                    if ($user) {
                        $submission = UserAssignmentSubmission::where('course_chapter_assignment_id', $assignment->id)
                            ->where('user_id', $user->id)
                            ->latest()
                            ->first();

                        if ($submission) {
                            $submissionStatus = $submission->status;
                            $submissionId = $submission->id;
                        }
                    }

                    return [
                        'id' => $assignment->id,
                        'type' => 'assignment',
                        'title' => $assignment->title,
                        'slug' => $assignment->slug,
                        'description' => $assignment->description,
                        'instructions' => $assignment->instructions,
                        'max_file_size' => $assignment->max_file_size,
                        'allowed_file_types' => $assignment->allowed_file_types,
                        'media' => $assignment->media,
                        'media_extension' => $assignment->media_extension,
                        'media_url' => $assignment->media ? asset('storage/' . $assignment->media) : null,
                        'points' => $assignment->points,
                        'can_skip' => $assignment->can_skip,
                        'is_active' => $assignment->is_active,
                        'chapter_order' => $assignment->chapter_order,
                        'is_completed' => $isItemCompleted(
                            $chapter->id,
                            \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class,
                            $assignment->id,
                        ),
                        'submission_status' => $submissionStatus, // pending, submitted, graded, etc.
                        'submission_id' => $submissionId,
                        'is_submitted' => !is_null($submissionStatus), // Boolean for easy checking
                        'created_at' => $assignment->created_at,
                        'updated_at' => $assignment->updated_at,
                    ];
                });
                $allContent = $allContent->merge($assignments);

                // Add resources (documents)
                $resources = $chapter->resources->map(static fn($resource) => [
                    'id' => $resource->id,
                    'type' => 'document',
                    'title' => $resource->title,
                    'slug' => $resource->slug,
                    'description' => $resource->description,
                    'file' => $resource->file,
                    'file_extension' => $resource->file_extension,
                    'url' => $resource->url,
                    'is_active' => $resource->is_active,
                    'chapter_order' => $resource->chapter_order,
                    'is_completed' => $isItemCompleted(
                        $chapter->id,
                        \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class,
                        $resource->id,
                    ),
                    'created_at' => $resource->created_at,
                    'updated_at' => $resource->updated_at,
                ]);
                $allContent = $allContent->merge($resources);

                // Sort all content by chapter_order
                $sortedContent = $allContent->sortBy('chapter_order')->values();

                return [
                    'id' => $chapter->id,
                    'course_id' => $chapter->course_id,
                    'title' => $chapter->title,
                    'slug' => $chapter->slug,
                    'description' => $chapter->description,
                    'is_active' => $chapter->is_active,
                    'chapter_order' => $chapter->chapter_order,
                    'total_content' => $sortedContent->count(),
                    'lectures_count' => $chapter->lectures->count(),
                    'quizzes_count' => $chapter->quizzes->count(),
                    'assignments_count' => $chapter->assignments->count(),
                    'documents_count' => $chapter->resources->count(),
                    'curriculum' => $sortedContent,
                    'created_at' => $chapter->created_at,
                    'updated_at' => $chapter->updated_at,
                ];
            });

            return ApiResponseService::successResponse('Course chapters retrieved successfully', $formattedChapters);
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> getCourseChapters Method');

            return ApiResponseService::errorResponse('Failed to retrieve course chapters');
        }
    }

    /**
     * Track a course chapter view or progress.
     * Expects: chapter_id, user_id, and optionally progress or status.
     */
    public function trackCourseChapter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_chapter_id' => 'required|exists:course_chapters,id',
            'status' => 'nullable|string|in:in_progress,completed',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            // Prevent tracking on refunded courses (approved refunds)
            $chapter = CourseChapter::find($request->course_chapter_id);
            if (!$chapter) {
                return ApiResponseService::validationError('Chapter not found.');
            }

            $userId = Auth::user()?->id;
            $hasApprovedRefund = RefundRequest::where('user_id', $userId)
                ->where('course_id', $chapter->course_id)
                ->where('status', 'approved')
                ->exists();
            if ($hasApprovedRefund) {
                return ApiResponseService::validationError(
                    'Refund is approved for this course. Progress tracking is disabled.',
                );
            }

            // Assuming you have a CourseChapterTracking model and table
            $tracking = UserCourseChapterTrack::updateOrCreate([
                'course_chapter_id' => $request->course_chapter_id,
                'user_id' => $userId,
            ], [
                'status' => $request->status ?? 'in_progress',
                'completed_at' => $request->status === 'completed' ? now() : null,
            ]);

            return ApiResponseService::successResponse('Chapter tracking updated successfully', $tracking);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> trackCourseChapter Method');

            return ApiResponseService::errorResponse('Failed to track the chapter.');
        }
    }

    /**
     * Get curriculum progress for a course
     */
    public function getCurriculumProgress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $userId = Auth::user()?->id;
            $courseId = $request->course_id;

            // Get all chapters for the course
            $chapters = CourseChapter::where('course_id', $courseId)
                ->where('is_active', true)
                ->with(['lectures', 'quizzes', 'assignments', 'resources'])
                ->orderBy('chapter_order')
                ->get();

            // Get user's tracking data
            $userTracking = UserCourseChapterTrack::where('user_id', $userId)
                ->whereIn('course_chapter_id', $chapters->pluck('id'))
                ->get()
                ->keyBy('course_chapter_id');

            $progressData = [];
            $totalChapters = $chapters->count();
            $completedChapters = 0;

            foreach ($chapters as $chapter) {
                $tracking = $userTracking->get($chapter->id);
                $isCompleted = $tracking && $tracking->status === 'completed';

                if ($isCompleted) {
                    $completedChapters++;
                }

                // Calculate content progress within chapter
                $totalContent =
                    $chapter->lectures->count()
                    + $chapter->quizzes->count()
                    + $chapter->assignments->count()
                    + $chapter->resources->count();

                $progressData[] = [
                    'chapter_id' => $chapter->id,
                    'chapter_title' => $chapter->title,
                    'chapter_order' => $chapter->chapter_order,
                    'status' => $tracking ? $tracking->status : 'not_started',
                    'is_completed' => $isCompleted,
                    'completed_at' => $tracking ? $tracking->completed_at : null,
                    'total_content' => $totalContent,
                    'lectures_count' => $chapter->lectures->count(),
                    'quizzes_count' => $chapter->quizzes->count(),
                    'assignments_count' => $chapter->assignments->count(),
                    'documents_count' => $chapter->resources->count(),
                ];
            }

            $overallProgress = $totalChapters > 0 ? round(($completedChapters / $totalChapters) * 100, 2) : 0;

            $response = [
                'course_id' => $courseId,
                'overall_progress' => $overallProgress,
                'total_chapters' => $totalChapters,
                'completed_chapters' => $completedChapters,
                'remaining_chapters' => $totalChapters - $completedChapters,
                'chapters_progress' => $progressData,
            ];

            return ApiResponseService::successResponse('Curriculum progress retrieved successfully', $response);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> getCurriculumProgress Method');

            return ApiResponseService::errorResponse('Failed to retrieve curriculum progress.' . $e->getMessage());
        }
    }

    /**
     * Get detailed curriculum tracking for a specific chapter
     */
    public function getChapterCurriculumDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_chapter_id' => 'required|exists:course_chapters,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $userId = Auth::user()?->id;
            $chapterId = $request->course_chapter_id;

            // Get chapter with all content
            $chapter = CourseChapter::where('id', $chapterId)
                ->where('is_active', true)
                ->with([
                    'lectures' => static function ($query): void {
                        $query->where('is_active', true)->orderBy('chapter_order');
                    },
                    'quizzes' => static function ($query): void {
                        $query->where('is_active', true)->orderBy('chapter_order');
                    },
                    'assignments' => static function ($query): void {
                        $query->where('is_active', true)->orderBy('chapter_order');
                    },
                    'resources' => static function ($query): void {
                        $query->where('is_active', true)->orderBy('chapter_order');
                    },
                ])
                ->first();

            if (!$chapter) {
                return ApiResponseService::validationError('Chapter not found or not active');
            }

            // Get user tracking for this chapter
            $tracking = UserCourseChapterTrack::where('user_id', $userId)
                ->where('course_chapter_id', $chapterId)
                ->first();

            // Format curriculum content
            $curriculum = collect();

            // Add lectures
            foreach ($chapter->lectures as $lecture) {
                $curriculum->push([
                    'id' => $lecture->id,
                    'type' => 'lecture',
                    'title' => $lecture->title,
                    'description' => $lecture->description,
                    'duration' => $lecture->duration,
                    'free_preview' => $lecture->free_preview,
                    'chapter_order' => $lecture->chapter_order,
                    'is_active' => $lecture->is_active,
                ]);
            }

            // Add quizzes
            foreach ($chapter->quizzes as $quiz) {
                $curriculum->push([
                    'id' => $quiz->id,
                    'type' => 'quiz',
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'time_limit' => $quiz->time_limit,
                    'total_points' => $quiz->total_points,
                    'passing_score' => $quiz->passing_score,
                    'chapter_order' => $quiz->chapter_order,
                    'is_active' => $quiz->is_active,
                ]);
            }

            // Add assignments
            foreach ($chapter->assignments as $assignment) {
                $curriculum->push([
                    'id' => $assignment->id,
                    'type' => 'assignment',
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'points' => $assignment->points,
                    'media' => $assignment->media,
                    'media_extension' => $assignment->media_extension,
                    'media_url' => $assignment->media ? asset('storage/' . $assignment->media) : null,
                    'chapter_order' => $assignment->chapter_order,
                    'is_active' => $assignment->is_active,
                ]);
            }

            // Add resources
            foreach ($chapter->resources as $resource) {
                $curriculum->push([
                    'id' => $resource->id,
                    'type' => 'document',
                    'title' => $resource->title,
                    'description' => $resource->description,
                    'file' => $resource->file,
                    'file_extension' => $resource->file_extension,
                    'chapter_order' => $resource->chapter_order,
                    'is_active' => $resource->is_active,
                ]);
            }

            // Sort by chapter_order
            $sortedCurriculum = $curriculum->sortBy('chapter_order')->values();

            $response = [
                'chapter_id' => $chapter->id,
                'chapter_title' => $chapter->title,
                'chapter_description' => $chapter->description,
                'course_id' => $chapter->course_id,
                'chapter_order' => $chapter->chapter_order,
                'status' => $tracking ? $tracking->status : 'not_started',
                'is_completed' => $tracking && $tracking->status === 'completed',
                'completed_at' => $tracking ? $tracking->completed_at : null,
                'total_content' => $sortedCurriculum->count(),
                'curriculum' => $sortedCurriculum,
            ];

            return ApiResponseService::successResponse('Chapter curriculum details retrieved successfully', $response);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                'API CourseChapterController -> getChapterCurriculumDetails Method',
            );

            return ApiResponseService::errorResponse('Failed to retrieve chapter curriculum details.');
        }
    }

    /**
     * Get lecture attachments (only when feature_attachments is enabled).
     */
    public function getLectureAttachments(int $lectureId)
    {
        if (!$this->featureFlagService->isEnabled('lecture_attachments', false)) {
            return response()->json([
                'error' => false,
                'message' => 'Success',
                'data' => ['attachments' => []],
                'code' => 200,
            ]);
        }

        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return response()->json([
                'error' => true,
                'message' => 'Lecture not found',
                'code' => 404,
            ], 404);
        }

        $attachments = $lecture->attachments->map(fn ($a) => [
            'id' => $a->id,
            'file_name' => $a->file_name,
            'file_url' => $a->file_url,
            'file_size' => $a->file_size,
            'file_type' => $a->file_type,
        ]);

        return response()->json([
            'error' => false,
            'message' => 'Success',
            'data' => ['attachments' => $attachments],
            'code' => 200,
        ]);
    }

    /**
     * Mark curriculum item as completed using model_id and model_type
     */
    public function markCurriculumItemCompleted(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_chapter_id' => 'required|exists:course_chapters,id',
            'model_id' => 'required|integer',
            'model_type' => 'required|string|in:lecture,quiz,assignment,document',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $userId = Auth::user()?->id;
            $chapterId = $request->course_chapter_id;
            $modelId = $request->model_id;
            $modelTypeKey = $request->model_type;

            // Map short model_type to full class
            $modelTypeMap = [
                'lecture' => \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class,
                'quiz' => \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class,
                'assignment' => \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class,
                'document' => \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class,
            ];

            $modelType = $modelTypeMap[$modelTypeKey] ?? null;

            // Verify the chapter exists and load relationships
            $chapter = CourseChapter::with(['lectures', 'quizzes', 'assignments', 'resources', 'course'])->find(
                $chapterId,
            );
            if (!$chapter) {
                return ApiResponseService::validationError('Chapter not found');
            }

            $itemData = [];
            $itemExists = false;

            // Handle based on type
            switch ($modelTypeKey) {
                case 'lecture':
                    $lecture = $chapter->lectures()->where('id', $modelId)->first();
                    if ($lecture) {
                        $itemExists = true;
                        $itemData = [
                            'id' => $lecture->id,
                            'title' => $lecture->title,
                            'type' => 'lecture',
                            'duration' => $lecture->duration,
                            'free_preview' => $lecture->free_preview,
                            'chapter_order' => $lecture->chapter_order,
                        ];
                    }
                    break;

                case 'quiz':
                    $quiz = $chapter->quizzes()->where('id', $modelId)->first();
                    if ($quiz) {
                        $itemExists = true;
                        $itemData = [
                            'id' => $quiz->id,
                            'title' => $quiz->title,
                            'type' => 'quiz',
                            'time_limit' => $quiz->time_limit,
                            'total_points' => $quiz->total_points,
                            'passing_score' => $quiz->passing_score,
                            'chapter_order' => $quiz->chapter_order,
                        ];
                    }
                    break;

                case 'assignment':
                    $assignment = $chapter->assignments()->where('id', $modelId)->first();
                    if ($assignment) {
                        $itemExists = true;
                        $itemData = [
                            'id' => $assignment->id,
                            'title' => $assignment->title,
                            'type' => 'assignment',
                            'points' => $assignment->points,
                            'chapter_order' => $assignment->chapter_order,
                        ];
                    }
                    break;

                case 'document':
                    $resource = $chapter->resources()->where('id', $modelId)->first();
                    if ($resource) {
                        $itemExists = true;
                        $itemData = [
                            'id' => $resource->id,
                            'title' => $resource->title,
                            'type' => 'document',
                            'file' => $resource->file,
                            'file_extension' => $resource->file_extension,
                            'chapter_order' => $resource->chapter_order,
                        ];
                    }
                    break;
            }

            if (!$itemExists) {
                return ApiResponseService::validationError(ucfirst($modelTypeKey) . ' not found in this chapter');
            }

            // Update or create detailed tracking record for the specific item
            $itemTracking = UserCurriculumTracking::updateOrCreate(
                [
                    'user_id' => $userId,
                    'course_chapter_id' => $chapterId,
                    'model_id' => $modelId,
                    'model_type' => $modelType, // Full class name stored in DB
                ],
                [
                    'status' => 'completed',
                    'completed_at' => now(),
                    'started_at' => now(),
                ],
            );

            // Update or create chapter-level tracking
            $chapterTracking = UserCourseChapterTrack::updateOrCreate([
                'course_chapter_id' => $chapterId,
                'user_id' => $userId,
            ], [
                'status' => 'in_progress', // Keep as in_progress until entire chapter is completed
            ]);

            // Find next curriculum item based on chapter_order
            $currentChapterOrder = $itemData['chapter_order'];
            $nextItem = null;

            // Helper function to format full curriculum item data
            $formatFullCurriculumItem = static function ($item, $type, $chapter, $userId) {
                $baseData = [
                    'id' => $item->id,
                    'type' => $type,
                    'title' => $item->title,
                    'slug' => $item->slug ?? null,
                    'description' => $item->description ?? null,
                    'is_active' => $item->is_active ?? true,
                    'chapter_order' => $item->chapter_order ?? 0,
                    'created_at' => $item->created_at ?? null,
                    'updated_at' => $item->updated_at ?? null,
                ];

                // Check if item is completed
                $isCompleted = false;
                if ($userId) {
                    $modelType = '';
                    switch ($type) {
                        case 'lecture':
                            $modelType = \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class;
                            break;
                        case 'quiz':
                            $modelType = \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class;
                            break;
                        case 'assignment':
                            $modelType = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class;
                            break;
                        case 'document':
                            $modelType = \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class;
                            break;
                    }

                    if ($modelType) {
                        $tracking = UserCurriculumTracking::where('user_id', $userId)
                            ->where('course_chapter_id', $chapter->id)
                            ->where('model_id', $item->id)
                            ->where('model_type', $modelType)
                            ->where('status', 'completed')
                            ->first();
                        $isCompleted = $tracking ? true : false;
                    }
                }
                $baseData['is_completed'] = $isCompleted;

                // Add type-specific fields
                switch ($type) {
                    case 'lecture':
                        $lectureResource = (new \App\Http\Resources\CourseChapterLectureResource($item))->resolve();
                        // Merge lecture resource data with base data
                        $baseData = array_merge($baseData, $lectureResource);

                        // Load resources if not already loaded
                        if (!$item->relationLoaded('resources')) {
                            $item->load('resources');
                        }
                        $baseData['has_resources'] = $item->resources->count() > 0;
                        $baseData['resources'] = $item->resources->map(static fn($resource) => [
                            'id' => $resource->id,
                            'title' => $resource->title,
                            'type' => $resource->type,
                            'file' => $resource->file,
                            'file_extension' => $resource->file_extension,
                            'url' => $resource->url,
                            'file_url' => $resource->file_url,
                            'order' => $resource->order,
                            'is_active' => $resource->is_active,
                        ])->toArray();
                        break;

                    case 'quiz':
                        $baseData['time_limit'] = $item->time_limit ?? null;
                        $baseData['total_points'] = $item->total_points ?? null;
                        $baseData['passing_score'] = $item->passing_score ?? null;
                        $baseData['can_skip'] = $item->can_skip ?? false;
                        break;

                    case 'assignment':
                        $baseData['instructions'] = $item->instructions ?? null;
                        $baseData['max_file_size'] = $item->max_file_size ?? null;
                        $baseData['allowed_file_types'] = $item->allowed_file_types ?? null;
                        $baseData['media'] = $item->media ?? null;
                        $baseData['media_extension'] = $item->media_extension ?? null;
                        $baseData['media_url'] = $item->media ? asset('storage/' . $item->media) : null;
                        $baseData['points'] = $item->points ?? null;
                        $baseData['can_skip'] = $item->can_skip ?? false;

                        // Get submission status
                        $submissionStatus = null;
                        $submissionId = null;
                        if ($userId) {
                            $submission = UserAssignmentSubmission::where('course_chapter_assignment_id', $item->id)
                                ->where('user_id', $userId)
                                ->latest()
                                ->first();

                            if ($submission) {
                                $submissionStatus = $submission->status;
                                $submissionId = $submission->id;
                            }
                        }
                        $baseData['submission_status'] = $submissionStatus;
                        $baseData['submission_id'] = $submissionId;
                        $baseData['is_submitted'] = !is_null($submissionStatus);
                        break;

                    case 'document':
                        $baseData['file'] = $item->file ? FileService::getFileUrl($item->file) : null;
                        $baseData['file_extension'] = $item->file_extension ?? null;
                        $baseData['url'] = $item->url ?? null;
                        break;
                }

                return $baseData;
            };

            // Get all active curriculum items from current chapter
            $allCurriculumItems = collect();

            // Add lectures (only active ones)
            foreach ($chapter->lectures->where('is_active', 1) as $lecture) {
                $allCurriculumItems->push([
                    'id' => $lecture->id,
                    'type' => 'lecture',
                    'type_short' => 'lecture',
                    'chapter_order' => $lecture->chapter_order,
                    'title' => $lecture->title,
                    'item' => $lecture,
                ]);
            }

            // Add quizzes (only active ones)
            foreach ($chapter->quizzes->where('is_active', 1) as $quiz) {
                $allCurriculumItems->push([
                    'id' => $quiz->id,
                    'type' => \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class,
                    'type_short' => 'quiz',
                    'chapter_order' => $quiz->chapter_order,
                    'title' => $quiz->title,
                    'item' => $quiz,
                ]);
            }

            // Add assignments (only active ones)
            foreach ($chapter->assignments->where('is_active', 1) as $assignment) {
                $allCurriculumItems->push([
                    'id' => $assignment->id,
                    'type' => \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class,
                    'type_short' => 'assignment',
                    'chapter_order' => $assignment->chapter_order,
                    'title' => $assignment->title,
                    'item' => $assignment,
                ]);
            }

            // Add documents/resources (only active ones)
            foreach ($chapter->resources->where('is_active', 1) as $resource) {
                $allCurriculumItems->push([
                    'id' => $resource->id,
                    'type' => \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class,
                    'type_short' => 'document',
                    'chapter_order' => $resource->chapter_order,
                    'title' => $resource->title,
                    'item' => $resource,
                ]);
            }

            // Sort by chapter_order and find next item
            $sortedItems = $allCurriculumItems->sortBy('chapter_order')->values();
            $nextItemInChapter = $sortedItems->where('chapter_order', '>', $currentChapterOrder)->first();

            if ($nextItemInChapter) {
                // Next item found in current chapter - format with full data
                $nextItem = $formatFullCurriculumItem(
                    $nextItemInChapter['item'],
                    $nextItemInChapter['type_short'],
                    $chapter,
                    $userId,
                );
            } else {
                // No more items in current chapter, find next chapter
                $course = $chapter->course;
                $nextChapter = CourseChapter::with([
                    'lectures.resources',
                    'quizzes',
                    'assignments',
                    'resources',
                ])
                    ->where('course_id', $course->id)
                    ->where('chapter_order', '>', $chapter->chapter_order)
                    ->where('is_active', 1)
                    ->orderBy('chapter_order', 'asc')
                    ->first();

                if ($nextChapter) {
                    // Get first active item from next chapter
                    $nextChapterItems = collect();

                    foreach ($nextChapter->lectures->where('is_active', 1) as $lecture) {
                        $nextChapterItems->push([
                            'id' => $lecture->id,
                            'type_short' => 'lecture',
                            'chapter_order' => $lecture->chapter_order,
                            'title' => $lecture->title,
                            'item' => $lecture,
                        ]);
                    }

                    foreach ($nextChapter->quizzes->where('is_active', 1) as $quiz) {
                        $nextChapterItems->push([
                            'id' => $quiz->id,
                            'type_short' => 'quiz',
                            'chapter_order' => $quiz->chapter_order,
                            'title' => $quiz->title,
                            'item' => $quiz,
                        ]);
                    }

                    foreach ($nextChapter->assignments->where('is_active', 1) as $assignment) {
                        $nextChapterItems->push([
                            'id' => $assignment->id,
                            'type_short' => 'assignment',
                            'chapter_order' => $assignment->chapter_order,
                            'title' => $assignment->title,
                            'item' => $assignment,
                        ]);
                    }

                    foreach ($nextChapter->resources->where('is_active', 1) as $resource) {
                        $nextChapterItems->push([
                            'id' => $resource->id,
                            'type_short' => 'document',
                            'chapter_order' => $resource->chapter_order,
                            'title' => $resource->title,
                            'item' => $resource,
                        ]);
                    }

                    $firstItemInNextChapter = $nextChapterItems->sortBy('chapter_order')->first();

                    if ($firstItemInNextChapter) {
                        // Format with full data
                        $nextItem = $formatFullCurriculumItem(
                            $firstItemInNextChapter['item'],
                            $firstItemInNextChapter['type_short'],
                            $nextChapter,
                            $userId,
                        );
                    } else {
                        // Next chapter has no curriculum items
                        $nextItem = [
                            'id' => null,
                            'type' => null,
                            'title' => null,
                            'slug' => null,
                            'description' => null,
                            'is_active' => null,
                            'chapter_order' => null,
                            'is_completed' => false,
                            'created_at' => null,
                            'updated_at' => null,
                            'message' => 'No more curriculum items available',
                        ];
                    }
                } else {
                    // No more chapters, course completed
                    $nextItem = [
                        'id' => null,
                        'type' => null,
                        'title' => null,
                        'slug' => null,
                        'description' => null,
                        'is_active' => null,
                        'chapter_order' => null,
                        'is_completed' => false,
                        'created_at' => null,
                        'updated_at' => null,
                        'message' => 'Course completed! No more curriculum items.',
                    ];
                }
            }

            $response = [
                'chapter_id' => $chapterId,
                'chapter_title' => $chapter->title,
                'model_id' => $modelId,
                'model_type' => $modelType, // full class name
                'model_type_short' => $modelTypeKey, // short name
                'model_class_name' => class_basename($modelType),
                'item' => $itemData,
                'item_tracking' => [
                    'id' => $itemTracking->id,
                    'status' => $itemTracking->status,
                    'started_at' => $itemTracking->started_at,
                    'completed_at' => $itemTracking->completed_at,
                    'time_spent' => $itemTracking->time_spent,
                ],
                'chapter_tracking_status' => $chapterTracking->status,
                'tracked_at' => now(),
                'next_curriculum' => $nextItem,
            ];

            return ApiResponseService::successResponse('Curriculum item marked as completed', $response);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                'API CourseChapterController -> markCurriculumItemCompleted Method',
            );

            return ApiResponseService::errorResponse('Failed to mark curriculum item as completed.' . $e->getMessage());
        }
    }

    /**
     * Get detailed curriculum tracking for a user
     */
    public function getDetailedCurriculumTracking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'nullable|exists:courses,id',
            'course_chapter_id' => 'nullable|exists:course_chapters,id',
            'model_type' => 'nullable|string|in:lecture,quiz,assignment,document',
            'status' => 'nullable|string|in:in_progress,completed',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $userId = Auth::user()?->id;
            $query = UserCurriculumTracking::where('user_id', $userId)->with(['chapter', 'user']);

            // Filter by course
            if ($request->filled('course_id')) {
                $query->whereHas('chapter', static function ($q) use ($request): void {
                    $q->where('course_id', $request->course_id);
                });
            }

            // Filter by chapter
            if ($request->filled('course_chapter_id')) {
                $query->where('course_chapter_id', $request->course_chapter_id);
            }

            // Filter by model type
            if ($request->filled('model_type')) {
                $modelTypeMap = [
                    'lecture' => \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class,
                    'quiz' => \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class,
                    'assignment' => \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class,
                    'document' => \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class,
                ];

                $fullModelType = $modelTypeMap[$request->model_type] ?? null;
                if ($fullModelType) {
                    $query->where('model_type', $fullModelType);
                }
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $trackingData = $query->orderBy('created_at', 'desc')->get();

            // Group by chapter for better organization
            $groupedData = $trackingData->groupBy('course_chapter_id')->map(static function ($items, $chapterId) {
                $chapter = $items->first()->chapter;

                return [
                    'chapter_id' => $chapterId,
                    'chapter_title' => $chapter ? $chapter->title : 'Unknown Chapter',
                    'course_id' => $chapter ? $chapter->course_id : null,
                    'total_items' => $items->count(),
                    'completed_items' => $items->where('status', 'completed')->count(),
                    'in_progress_items' => $items->where('status', 'in_progress')->count(),
                    'completion_percentage' => $items->count() > 0
                        ? round(($items->where('status', 'completed')->count() / $items->count()) * 100, 2)
                        : 0,
                    'items' => $items->map(static fn($item) => [
                        'id' => $item->id,
                        'model_id' => $item->model_id,
                        'model_type' => $item->model_type,
                        'model_type_short' => $item->model_type_short,
                        'model_class_name' => $item->model_class_name,
                        'status' => $item->status,
                        'started_at' => $item->started_at,
                        'completed_at' => $item->completed_at,
                        'time_spent' => $item->time_spent,
                        'metadata' => $item->metadata,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]),
                ];
            });

            $response = [
                'user_id' => $userId,
                'total_tracked_items' => $trackingData->count(),
                'completed_items' => $trackingData->where('status', 'completed')->count(),
                'in_progress_items' => $trackingData->where('status', 'in_progress')->count(),
                'overall_completion_percentage' => $trackingData->count() > 0
                    ? round(($trackingData->where('status', 'completed')->count() / $trackingData->count()) * 100, 2)
                    : 0,
                'chapters' => $groupedData->values(),
            ];

            return ApiResponseService::successResponse(
                'Detailed curriculum tracking retrieved successfully',
                $response,
            );
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                'API CourseChapterController -> getDetailedCurriculumTracking Method',
            );

            return ApiResponseService::errorResponse('Failed to retrieve detailed curriculum tracking.');
        }
    }

    /**
     * Get current (last completed) curriculum item for authenticated user
     */
    public function getCurrentCurriculum(Request $request)
    {
        try {
            $user = Auth::user();

            // Get the last completed curriculum item
            $currentCurriculum = UserCurriculumTracking::where('user_id', $user?->id)
                ->where('status', 'completed')
                ->with(['chapter.course'])
                ->orderBy('completed_at', 'desc')
                ->first();

            if (!$currentCurriculum) {
                return ApiResponseService::successResponse('No curriculum completed yet', [
                    'current_curriculum_id' => null,
                    'curriculum_name' => null,
                    'chapter_id' => null,
                    'chapter_title' => null,
                    'course_id' => null,
                    'course_title' => null,
                    'model_id' => null,
                    'model_type' => null,
                    'completed_at' => null,
                ]);
            }

            // Get curriculum item details based on model_type
            $curriculumItem = null;
            $modelTypeShort = null;

            switch ($currentCurriculum->model_type) {
                case \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class:
                    $curriculumItem = \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::find($currentCurriculum->model_id);
                    $modelTypeShort = 'lecture';
                    break;
                case \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class:
                    $curriculumItem = \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::find($currentCurriculum->model_id);
                    $modelTypeShort = 'quiz';
                    break;
                case \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class:
                    $curriculumItem = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::find($currentCurriculum->model_id);
                    $modelTypeShort = 'assignment';
                    break;
                case \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class:
                    $curriculumItem = \App\Models\Course\CourseChapter\Resource\CourseChapterResource::find($currentCurriculum->model_id);
                    $modelTypeShort = 'resource';
                    break;
            }

            $response = [
                'current_curriculum_id' => $currentCurriculum->id,
                'curriculum_name' => $curriculumItem ? $curriculumItem->title : 'Unknown',
                'chapter_id' => $currentCurriculum->course_chapter_id,
                'chapter_title' => $currentCurriculum->chapter ? $currentCurriculum->chapter->title : 'Unknown Chapter',
                'course_id' => $currentCurriculum->chapter ? $currentCurriculum->chapter->course_id : null,
                'course_title' => $currentCurriculum->chapter && $currentCurriculum->chapter->course
                    ? $currentCurriculum->chapter->course->title
                    : 'Unknown Course',
                'model_id' => $currentCurriculum->model_id,
                'model_type' => $modelTypeShort,
                'model_type_full' => $currentCurriculum->model_type,
                'completed_at' => $currentCurriculum->completed_at,
                'completed_at_formatted' => $currentCurriculum->completed_at
                    ? $currentCurriculum->completed_at->format('Y-m-d H:i:s')
                    : null,
                'completed_at_human' => $currentCurriculum->completed_at
                    ? $currentCurriculum->completed_at->diffForHumans()
                    : null,
                'time_spent' => $currentCurriculum->time_spent,
            ];

            return ApiResponseService::successResponse('Current curriculum retrieved successfully', $response);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> getCurrentCurriculum Method');

            return ApiResponseService::errorResponse('Failed to retrieve current curriculum.');
        }
    }

    /**
     * Get assignment submission history for all assignments in a course
     */
    public function getAssignmentSubmissionHistory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|exists:courses,id',
                'user_id' => 'nullable|exists:users,id', // Optional: if not provided, use authenticated user
                'chapter_id' => 'nullable|exists:course_chapters,id', // Optional: filter by specific chapter
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $userId = $request->user_id ?? Auth::id();
            $courseId = $request->course_id;
            $chapterId = $request->chapter_id;

            // Check if user is authenticated
            if (!$userId) {
                return ApiResponseService::errorResponse('User authentication required.');
            }

            // Get user details
            $user = \App\Models\User::find($userId);
            if (!$user) {
                return ApiResponseService::errorResponse('User not found.');
            }

            // If chapter_id is provided, validate it belongs to the course
            if ($chapterId) {
                $chapter = \App\Models\Course\CourseChapter\CourseChapter::where('id', $chapterId)
                    ->where('course_id', $courseId)
                    ->first();

                if (!$chapter) {
                    return ApiResponseService::validationError('Chapter not found or does not belong to this course.');
                }
            }

            // Get all assignments for this course (for chapters array)
            $allAssignmentsQuery = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::with([
                'chapter.course',
            ])->whereHas('chapter', static function ($query) use ($courseId): void {
                $query->where('course_id', $courseId);
            });

            $allAssignments = $allAssignmentsQuery->orderBy('created_at', 'desc')->get();

            // Get filtered assignments for current_chapter_assignments if chapter_id is provided
            $filteredAssignments = $allAssignments;
            if ($chapterId) {
                $filteredAssignments = $allAssignments->where('course_chapter_id', $chapterId);
            }

            // Get all submissions for all assignments in this course by this user
            $assignmentIds = $allAssignments->pluck('id')->toArray();
            $submissions = UserAssignmentSubmission::whereIn('course_chapter_assignment_id', $assignmentIds)
                ->where('user_id', $userId)
                ->with(['user', 'assignment.chapter.course', 'files'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Group submissions by assignment
            $submissionsByAssignment = $submissions->groupBy('course_chapter_assignment_id');

            // Group all assignments by chapter (for chapters array)
            $allAssignmentsByChapter = $allAssignments->groupBy('course_chapter_id');

            // Format chapters with their assignments (always show all chapters)
            $chapters = $allAssignmentsByChapter->map(static function ($chapterAssignments, $chapterId) use (
                $submissionsByAssignment,
            ) {
                $firstAssignment = $chapterAssignments->first();
                $chapter = $firstAssignment->chapter;

                $formattedAssignments = $chapterAssignments->map(static function ($assignment) use (
                    $submissionsByAssignment,
                ) {
                    $assignmentSubmissions = $submissionsByAssignment->get($assignment->id, collect());

                    // Format submissions for this assignment
                    $formattedSubmissions = $assignmentSubmissions->map(static function ($submission) {
                        // Get the first file (or primary file) from the files relationship
                        $firstFile = $submission->files->first();

                        // Format file data
                        $submittedFile = null;
                        $fileName = null;
                        $fileSize = null;
                        $fileType = null;

                        if ($firstFile) {
                            if ($firstFile->type === 'file' && $firstFile->file) {
                                $submittedFile = FileService::getFileUrl($firstFile->file);
                                $fileName = basename((string) $firstFile->file);
                                $fileType = $firstFile->file_extension;
                                // Try to get file size from storage
                                try {
                                    $filePath = storage_path('app/public/' . $firstFile->file);
                                    if (file_exists($filePath)) {
                                        $fileSize = filesize($filePath);
                                    }
                                } catch (\Exception) {
                                    // File size not available
                                }
                            } elseif ($firstFile->type === 'url' && $firstFile->url) {
                                $submittedFile = $firstFile->url;
                                $fileName = 'External Link';
                                $fileType = 'url';
                            }
                        }

                        return [
                            'id' => $submission->id,
                            'submitted_file' => $submittedFile,
                            'file_name' => $fileName,
                            'file_size' => $fileSize,
                            'file_type' => $fileType,
                            'files' => $submission->files->map(static fn($file) => [
                                'id' => $file->id,
                                'type' => $file->type,
                                'file' => $file->type === 'file' && $file->file
                                    ? FileService::getFileUrl($file->file)
                                    : null,
                                'file_name' => $file->type === 'file' && $file->file
                                    ? basename((string) $file->file)
                                    : null,
                                'file_extension' => $file->file_extension,
                                'url' => $file->type === 'url' ? $file->url : null,
                            ]),
                            'status' => $submission->status, // pending, accepted, rejected
                            'status_label' => ucfirst((string) $submission->status),
                            'feedback' => $submission->feedback ?? null,
                            'grade' => $submission->points ?? null,
                            'comment' => $submission->comment ?? null,
                            'submitted_at' => $submission->created_at,
                            'submitted_at_formatted' => $submission->created_at->format('Y-m-d H:i:s'),
                            'time_ago' => $submission->created_at->diffForHumans(),
                            'updated_at' => $submission->updated_at,
                            'can_resubmit' => $submission->status === 'rejected',
                            'can_edit' => $submission->status === 'pending' || $submission->status === 'rejected',
                            'can_delete' => $submission->status === 'pending' || $submission->status === 'rejected',
                        ];
                    });

                    // Get submission statistics for this assignment
                    $totalSubmissions = $assignmentSubmissions->count();
                    $acceptedSubmissions = $assignmentSubmissions->where('status', 'accepted')->count();
                    $rejectedSubmissions = $assignmentSubmissions->where('status', 'rejected')->count();
                    $pendingSubmissions = $assignmentSubmissions->where('status', 'pending')->count();

                    // Get latest submission status for this assignment
                    $latestSubmission = $assignmentSubmissions->first();
                    $latestStatus = $latestSubmission ? $latestSubmission->status : null;

                    return [
                        'id' => $assignment->id,
                        'assignment_id' => $assignment->id, // Add assignment_id field
                        'title' => $assignment->title,
                        'description' => $assignment->description,
                        'instructions' => $assignment->instructions,
                        'points' => $assignment->points,
                        'max_file_size' => $assignment->max_file_size,
                        'allowed_file_types' => $assignment->allowed_file_types,
                        'media' => $assignment->media,
                        'media_extension' => $assignment->media_extension,
                        'media_url' => $assignment->media ? asset('storage/' . $assignment->media) : null,
                        'can_skip' => $assignment->can_skip,
                        'is_active' => $assignment->is_active,
                        'created_at' => $assignment->created_at,
                        'created_at_formatted' => $assignment->created_at->format('Y-m-d H:i:s'),
                        'time_ago' => $assignment->created_at->diffForHumans(),
                        'submissions' => $formattedSubmissions,
                        'submission_stats' => [
                            'total_submissions' => $totalSubmissions,
                            'accepted_submissions' => $acceptedSubmissions,
                            'rejected_submissions' => $rejectedSubmissions,
                            'pending_submissions' => $pendingSubmissions,
                            'latest_status' => $latestStatus,
                            'has_submissions' => $totalSubmissions > 0,
                        ],
                    ];
                });

                return [
                    'chapter_id' => $chapter->id,
                    'chapter_title' => $chapter->title,
                    'course_name' => $chapter->course->title ?? 'Course Not Found',
                    'course_image' => $chapter->course->thumbnail
                        ? (
                            str_starts_with((string) $chapter->course->thumbnail, 'http')
                                ? $chapter->course->thumbnail
                                : asset('storage/' . $chapter->course->thumbnail)
                        )
                        : null,
                    'course_id' => $chapter->course->id ?? null,
                    'assignments' => $formattedAssignments->values()->toArray(),
                ];
            });

            // Get current chapter assignments
            if ($chapterId) {
                // If chapter_id is provided, show only that chapter's assignments using filtered assignments
                $filteredAssignmentsByChapter = $filteredAssignments->groupBy('course_chapter_id');
                $currentChapterAssignments = $filteredAssignmentsByChapter->map(static function (
                    $chapterAssignments,
                    $chapterId,
                ) use ($submissionsByAssignment) {
                    $firstAssignment = $chapterAssignments->first();
                    $chapter = $firstAssignment->chapter;

                    $formattedAssignments = $chapterAssignments->map(static function ($assignment) use (
                        $submissionsByAssignment,
                    ) {
                        $assignmentSubmissions = $submissionsByAssignment->get($assignment->id, collect());

                        // Format submissions for this assignment
                        $formattedSubmissions = $assignmentSubmissions->map(static function ($submission) {
                            // Get the first file (or primary file) from the files relationship
                            $firstFile = $submission->files->first();

                            // Format file data
                            $submittedFile = null;
                            $fileName = null;
                            $fileSize = null;
                            $fileType = null;

                            if ($firstFile) {
                                if ($firstFile->type === 'file' && $firstFile->file) {
                                    $submittedFile = FileService::getFileUrl($firstFile->file);
                                    $fileName = basename((string) $firstFile->file);
                                    $fileType = $firstFile->file_extension;
                                    // Try to get file size from storage
                                    try {
                                        $filePath = storage_path('app/public/' . $firstFile->file);
                                        if (file_exists($filePath)) {
                                            $fileSize = filesize($filePath);
                                        }
                                    } catch (\Exception) {
                                        // File size not available
                                    }
                                } elseif ($firstFile->type === 'url' && $firstFile->url) {
                                    $submittedFile = $firstFile->url;
                                    $fileName = 'External Link';
                                    $fileType = 'url';
                                }
                            }

                            return [
                                'id' => $submission->id,
                                'submitted_file' => $submittedFile,
                                'file_name' => $fileName,
                                'file_size' => $fileSize,
                                'file_type' => $fileType,
                                'files' => $submission->files->map(static fn($file) => [
                                    'id' => $file->id,
                                    'type' => $file->type,
                                    'file' => $file->type === 'file' && $file->file
                                        ? FileService::getFileUrl($file->file)
                                        : null,
                                    'file_name' => $file->type === 'file' && $file->file
                                        ? basename((string) $file->file)
                                        : null,
                                    'file_extension' => $file->file_extension,
                                    'url' => $file->type === 'url' ? $file->url : null,
                                ]),
                                'status' => $submission->status, // pending, accepted, rejected
                                'status_label' => ucfirst((string) $submission->status),
                                'feedback' => $submission->feedback ?? null,
                                'grade' => $submission->points ?? null,
                                'comment' => $submission->comment ?? null,
                                'submitted_at' => $submission->created_at,
                                'submitted_at_formatted' => $submission->created_at->format('Y-m-d H:i:s'),
                                'time_ago' => $submission->created_at->diffForHumans(),
                                'updated_at' => $submission->updated_at,
                                'can_resubmit' => $submission->status === 'rejected',
                                'can_edit' => $submission->status === 'pending' || $submission->status === 'rejected',
                                'can_delete' => $submission->status === 'pending' || $submission->status === 'rejected',
                            ];
                        });

                        // Get submission statistics for this assignment
                        $totalSubmissions = $assignmentSubmissions->count();
                        $acceptedSubmissions = $assignmentSubmissions->where('status', 'accepted')->count();
                        $rejectedSubmissions = $assignmentSubmissions->where('status', 'rejected')->count();
                        $pendingSubmissions = $assignmentSubmissions->where('status', 'pending')->count();

                        // Get latest submission status for this assignment
                        $latestSubmission = $assignmentSubmissions->first();
                        $latestStatus = $latestSubmission ? $latestSubmission->status : null;

                        return [
                            'id' => $assignment->id,
                            'assignment_id' => $assignment->id, // Add assignment_id field
                            'title' => $assignment->title,
                            'description' => $assignment->description,
                            'instructions' => $assignment->instructions,
                            'points' => $assignment->points,
                            'max_file_size' => $assignment->max_file_size,
                            'allowed_file_types' => $assignment->allowed_file_types,
                            'media' => $assignment->media,
                            'media_extension' => $assignment->media_extension,
                            'media_url' => $assignment->media ? asset('storage/' . $assignment->media) : null,
                            'can_skip' => $assignment->can_skip,
                            'is_active' => $assignment->is_active,
                            'created_at' => $assignment->created_at,
                            'created_at_formatted' => $assignment->created_at->format('Y-m-d H:i:s'),
                            'time_ago' => $assignment->created_at->diffForHumans(),
                            'submissions' => $formattedSubmissions,
                            'submission_stats' => [
                                'total_submissions' => $totalSubmissions,
                                'accepted_submissions' => $acceptedSubmissions,
                                'rejected_submissions' => $rejectedSubmissions,
                                'pending_submissions' => $pendingSubmissions,
                                'latest_status' => $latestStatus,
                                'has_submissions' => $totalSubmissions > 0,
                            ],
                        ];
                    });

                    return [
                        'chapter_id' => $chapter->id,
                        'chapter_title' => $chapter->title,
                        'course_name' => $chapter->course->title ?? 'Course Not Found',
                        'course_image' => $chapter->course->thumbnail
                            ? (
                                str_starts_with((string) $chapter->course->thumbnail, 'http')
                                    ? $chapter->course->thumbnail
                                    : asset('storage/' . $chapter->course->thumbnail)
                            )
                            : null,
                        'course_id' => $chapter->course->id ?? null,
                        'assignments' => $formattedAssignments->values()->toArray(),
                    ];
                })->values();
            } else {
                // If no chapter_id provided, show first chapter's assignments (default behavior)
                $currentChapterAssignments = $chapters->take(1)->values();
            }

            $responseData = [
                'chapters' => $chapters->values()->toArray(),
                'current_chapter_assignments' => $currentChapterAssignments->toArray(),
            ];

            return ApiResponseService::successResponse(
                'Course assignment submission history retrieved successfully',
                $responseData,
            );
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse(
                $e,
                'API CourseChapterController -> getAssignmentSubmissionHistory Method',
            );

            return ApiResponseService::errorResponse('Failed to retrieve assignment submission history.'
            . $e->getMessage());
        }
    }

    /**
     * Check if user has completed all course curriculum and assignments
     * For certificate eligibility
     */
    public function checkCourseCompletion(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|integer|exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $userId = Auth::id();
            $courseId = $request->course_id;

            // Get course with chapters and curriculum items
            $course = Course::with([
                'chapters' => static function ($query): void {
                    $query->where('is_active', 1)->orderBy('chapter_order');
                },
                'chapters.lectures' => static function ($query): void {
                    $query->where('is_active', 1);
                },
                'chapters.quizzes' => static function ($query): void {
                    $query->where('is_active', 1);
                },
                'chapters.assignments' => static function ($query): void {
                    $query->where('is_active', 1);
                },
                'chapters.resources' => static function ($query): void {
                    $query->where('is_active', 1);
                },
            ])->find($courseId);

            if (!$course) {
                return ApiResponseService::errorResponse('Course not found.');
            }

            // Check if user is enrolled (through orders -> order_courses)
            $isEnrolled = \App\Models\Order::where('user_id', $userId)
                ->whereHas('orderCourses', static function ($query) use ($courseId): void {
                    $query->where('course_id', $courseId);
                })
                ->where('status', 'completed')
                ->exists();

            if (!$isEnrolled) {
                return ApiResponseService::errorResponse('You are not enrolled in this course.');
            }

            // Count total curriculum items
            $totalLectures = 0;
            $totalQuizzes = 0;
            $totalAssignments = 0;
            $totalResources = 0;

            foreach ($course->chapters as $chapter) {
                $totalLectures += $chapter->lectures->count();
                $totalQuizzes += $chapter->quizzes->count();
                $totalAssignments += $chapter->assignments->count();
                $totalResources += $chapter->resources->count();
            }

            // Check completed items from user_curriculum_trackings
            $completedTracking = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                ->whereIn('course_chapter_id', $course->chapters->pluck('id'))
                ->where('status', 'completed')
                ->get();

            $completedLectures = $completedTracking
                ->where('model_type', \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class)
                ->count();
            $completedQuizzes = $completedTracking
                ->where('model_type', \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class)
                ->count();
            $completedResources = $completedTracking
                ->where('model_type', \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class)
                ->count();

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

            $submittedAssignments = 0;
            $skippableAssignments = count($skippableAssignmentIds);

            if (!empty($assignmentIds)) {
                // Count assignments that have been submitted/accepted (excluding skippable ones)
                $nonSkippableAssignmentIds = array_diff($assignmentIds, $skippableAssignmentIds);
                if (!empty($nonSkippableAssignmentIds)) {
                    $submittedAssignments = \App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission::where(
                        'user_id',
                        $userId,
                    )
                        ->whereIn('course_chapter_assignment_id', $nonSkippableAssignmentIds)
                        ->whereIn('status', ['submitted', 'accepted'])
                        ->count();
                }
            }

            // Check if all curriculum items are completed (excluding assignments)
            $curriculumItemsTotal = $totalLectures + $totalQuizzes + $totalResources;
            $curriculumItemsCompleted = $completedLectures + $completedQuizzes + $completedResources;
            $allCurriculumCompleted = $curriculumItemsTotal == 0 || $curriculumItemsCompleted >= $curriculumItemsTotal;

            $allAssignmentsSubmitted = \App\Services\CourseCompletionService::allAssignmentsSubmitted(
                $totalAssignments,
                $skippableAssignments,
                $submittedAssignments,
            );

            // Determine certificate status based on course type
            $courseType = $course->course_type ?? 'paid';
            $isFreeCourse = $courseType === 'free';

            // Default for paid courses
            $certificateFeePaid = false;
            $certificateFee = null;
            $certificateTaxPercentage = null;
            $certificateTaxAmount = null;
            $certificateTotal = null;

            if ($isFreeCourse) {
                // Free course: certificate requires payment
                $certificateStatus = 'paid';
                $certificateFee = (float) ($course->certificate_fee ?? 0);

                // Get tax info for free courses
                $pricingService = app(\App\Services\PricingCalculationService::class);
                $certificateTaxPercentage = $pricingService->getTaxPercentageFromRequest($request);

                // Calculate tax amount
                if ($certificateFee > 0 && $certificateTaxPercentage > 0) {
                    $certificateTaxAmount = round(($certificateFee * $certificateTaxPercentage) / 100, 2);
                } else {
                    $certificateTaxAmount = 0;
                }
                $certificateTotal = round($certificateFee + $certificateTaxAmount, 2);

                // Check if user has paid certificate fee
                $certificateFeePaid = OrderCourse::where('course_id', $courseId)
                    ->whereHas('order', static function ($query) use ($userId): void {
                        $query->where('user_id', $userId)->where('status', 'completed');
                    })
                    ->where('certificate_purchased', true)
                    ->exists();
            } else {
                // Paid course: certificate is free (included)
                $certificateStatus = 'free';
            }

            $response = [
                'all_curriculum_completed' => $allCurriculumCompleted,
                'all_assignments_submitted' => $allAssignmentsSubmitted,
                'certificate' => $certificateStatus,
                'certificate_fee_paid' => $certificateFeePaid,
                'certificate_fee' => $certificateFee,
                'certificate_tax_percentage' => $certificateTaxPercentage,
                'certificate_tax_amount' => $certificateTaxAmount,
                'certificate_total' => $certificateTotal,
            ];

            return ApiResponseService::successResponse('Course completion status retrieved successfully.', $response);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API CourseChapterController -> checkCourseCompletion Method');

            return ApiResponseService::errorResponse('Failed to check course completion.' . $e->getMessage());
        }
    }
}
