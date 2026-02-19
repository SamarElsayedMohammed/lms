<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\CourseChapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CourseChapterController extends Controller
{
    /**
     * Display a listing of the chapters for a course.
     */
    public function index($courseId)
    {
        $course = Course::findOrFail($courseId);
        $chapters = $course->chapters()->with('lectures')->get();

        return response()->json([
            'status' => 'success',
            'data' => $chapters,
        ]);
    }

    /**
     * Store a newly created chapter.
     */
    public function store(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to add chapters to this course. Only course owners and team members can modify course content.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'free_preview' => 'boolean',
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
        $data['course_id'] = $courseId;

        // If order is not provided, make it the last one
        if (!isset($data['order'])) {
            $data['order'] = $course->chapters()->max('order') + 1;
        }

        $chapter = CourseChapter::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Chapter created successfully',
            'data' => $chapter,
        ], 201);
    }

    /**
     * Display the specified chapter.
     */
    public function show($courseId, $chapterId)
    {
        $course = Course::findOrFail($courseId);
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
    public function destroy($courseId, $chapterId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to delete this chapter. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $chapter->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Chapter deleted successfully',
        ]);
    }

    /**
     * Reorder chapters
     */
    public function reorder(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to reorder chapters. Only course owners and team members can modify course content.',
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

    public function getAddedCourses(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:courses,id',
                'level' => 'nullable|in:beginner,intermediate,advanced',
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,name,price,course_type',
                'sort_order' => 'nullable|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'course_type' => 'nullable|in:free,paid',
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Course::where('user_id', Auth::user()?->id)
                ->with(['category', 'user', 'learnings', 'requirements', 'tags', 'language', 'instructors'])
                ->where('is_active', true);

            if ($request->id) {
                $query->where('id', $request->id);
            }

            if ($request->filled('level')) {
                $query->where('level', $request->level);
            }

            if ($request->filled('course_type')) {
                $query->where('course_type', $request->course_type);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('short_description', 'LIKE', "%{$search}%")
                        ->orWhere('level', 'LIKE', "%{$search}%")
                        ->orWhereHas('language', static function ($langQuery) use ($search): void {
                            $langQuery->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('category', static function ($categoryQuery) use ($search): void {
                            $categoryQuery->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('tags', static function ($tagQuery) use ($search): void {
                            $tagQuery->where('tag', 'LIKE', "%{$search}%");
                        });
                });
            }

            $sortField = $request->sort_by ?? 'id';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortField, $sortOrder);

            $perPage = $request->per_page ?? 15;
            $courses = $query->paginate($perPage);

            if ($courses->isEmpty()) {
                ApiResponseService::validationError('No Courses Found');
            }
            ApiResponseService::successResponse('Courses retrieved successfully', $courses);
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Course Controller -> getAddedCourses Method');
            ApiResponseService::errorResponse();
        }
    }
}
