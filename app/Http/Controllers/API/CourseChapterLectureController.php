<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\ContentAccessService;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CourseChapterLectureController extends Controller
{
    public function __construct(
        private readonly ContentAccessService $contentAccessService,
        private readonly VideoProgressService $videoProgressService,
        private readonly FeatureFlagService $featureFlagService,
    ) {}

    /**
     * Display a listing of the lectures for a chapter.
     */
    public function index($courseId, $chapterId)
    {
        $course = Course::findOrFail($courseId);
        $chapter = $course->chapters()->findOrFail($chapterId);
        $lectures = $chapter->lectures;

        foreach ($lectures as $lecture) {
            if ($lecture->type === 'video') {
                $lecture->load('videos');
            } elseif ($lecture->type === 'document') {
                $lecture->load('documents');
            } elseif ($lecture->type === 'quiz') {
                $lecture->load('quiz');
            } elseif ($lecture->type === 'assignment') {
                $lecture->load('assignment');
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $lectures,
        ]);
    }

    /**
     * Store a newly created lecture.
     */
    public function store(Request $request, $courseId, $chapterId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to add lectures to this course. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:video,document,quiz,assignment',
            'description' => 'nullable|string',
            'duration' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['course_chapter_id'] = $chapterId;

        // If order is not provided, make it the last one
        if (!isset($data['order'])) {
            $data['order'] = ($chapter->lectures()->max('order') ?? 0) + 1;
        }

        $lecture = CourseChapterLecture::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Lecture created successfully',
            'data' => $lecture,
        ], 201);
    }

    /**
     * Display the specified lecture.
     */
    public function show($courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        if (!$this->contentAccessService->canAccessCourse($user, $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        if ($this->featureFlagService->isEnabled('sequential_lecture_unlock')) {
            $previousLecture = $this->videoProgressService->getPreviousLecture($lecture);
            if ($previousLecture !== null) {
                $progress = \App\Models\VideoProgress::forUser($user->id)
                    ->forLecture($previousLecture->id)
                    ->first();
                if (!$progress || !$progress->is_completed) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You must complete the previous lecture first.',
                    ], 403);
                }
            }
        }

        if ($lecture->type === 'video') {
            $lecture->load('videos');
        } elseif ($lecture->type === 'document') {
            $lecture->load('documents');
        } elseif ($lecture->type === 'quiz') {
            $lecture->load(['quiz', 'quiz.questions', 'quiz.questions.answers']);
        } elseif ($lecture->type === 'assignment') {
            $lecture->load('assignment');
        }

        return response()->json([
            'status' => 'success',
            'data' => $lecture,
        ]);
    }

    /**
     * Update the specified lecture.
     */
    public function update(Request $request, $courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to update this lecture. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|in:video,document,quiz,assignment',
            'description' => 'nullable|string',
            'duration' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lecture->update($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Lecture updated successfully',
            'data' => $lecture,
        ]);
    }

    /**
     * Remove the specified lecture.
     */
    public function destroy($courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to delete this lecture. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Delete associated content based on type
        if ($lecture->type === 'video') {
            $lecture->videos()->delete();
        } elseif ($lecture->type === 'document') {
            $lecture->documents()->delete();
        } elseif ($lecture->type === 'quiz') {
            if ($quiz = $lecture->quiz) {
                // Delete associated questions and answers
                foreach ($quiz->questions as $question) {
                    $question->answers()->delete();
                }
                $quiz->questions()->delete();
                $quiz->delete();
            }
        } elseif ($lecture->type === 'assignment') {
            $lecture->assignment()->delete();
        }

        $lecture->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Lecture deleted successfully',
        ]);
    }

    /**
     * Reorder lectures
     */
    public function reorder(Request $request, $courseId, $chapterId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to reorder lectures. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);

        $validator = Validator::make($request->all(), [
            'lectures' => 'required|array',
            'lectures.*.id' => 'required|exists:course_chapters_lectures,id',
            'lectures.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->lectures as $item) {
            $lecture = CourseChapterLecture::find($item['id']);

            if (!$lecture) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lecture not found: ' . $item['id'],
                ], 404);
            }

            if ($lecture->course_chapter_id != $chapterId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more lectures do not belong to this chapter',
                ], 400);
            }

            $lecture->order = $item['order'];
            $lecture->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Lectures reordered successfully',
        ]);
    }
}
