<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\CourseLectureAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LectureAssignmentController extends Controller
{
    /**
     * Store a new assignment for a lecture.
     */
    public function store(Request $request, $courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to add assignments to this lecture. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if lecture type is assignment
        if ($lecture->type !== 'assignment') {
            return response()->json([
                'status' => 'error',
                'message' => 'This lecture is not of type assignment',
            ], 400);
        }

        // Check if assignment already exists
        if ($lecture->assignment) {
            return response()->json([
                'status' => 'error',
                'message' => 'This lecture already has an assignment',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'instructions' => 'nullable|string',
            'due_days' => 'nullable|integer|min:1',
            'max_file_size' => 'nullable|integer|min:1',
            'allowed_file_types' => 'nullable|string',
            'points' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['course_chapter_lecture_id'] = $lectureId;

        // Create assignment
        $assignment = CourseLectureAssignment::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Assignment created successfully',
            'data' => $assignment,
        ], 201);
    }

    /**
     * Display the specified assignment.
     */
    public function show($courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);
        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if assignment exists
        if (!$lecture->assignment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment not found for this lecture',
            ], 404);
        }

        $assignment = $lecture->assignment;

        return response()->json([
            'status' => 'success',
            'data' => $assignment,
        ]);
    }

    /**
     * Update the specified assignment.
     */
    public function update(Request $request, $courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to update this assignment. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if assignment exists
        if (!$lecture->assignment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment not found for this lecture',
            ], 404);
        }

        $assignment = $lecture->assignment;

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'instructions' => 'nullable|string',
            'due_days' => 'nullable|integer|min:1',
            'max_file_size' => 'nullable|integer|min:1',
            'allowed_file_types' => 'nullable|string',
            'points' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update assignment
        $assignment->update($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Assignment updated successfully',
            'data' => $assignment,
        ]);
    }

    /**
     * Remove the specified assignment.
     */
    public function destroy($courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to delete this assignment. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if assignment exists
        if (!$lecture->assignment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment not found for this lecture',
            ], 404);
        }

        $assignment = $lecture->assignment;

        // Delete assignment
        $assignment->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Assignment deleted successfully',
        ]);
    }
}
