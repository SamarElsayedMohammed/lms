<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\CourseLectureVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LectureVideoController extends Controller
{
    /**
     * Store a new video for a lecture.
     */
    public function store(Request $request, $courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to add videos to this lecture. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if lecture type is video
        if ($lecture->type !== 'video') {
            return response()->json([
                'status' => 'error',
                'message' => 'This lecture is not of type video',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'url' => 'required|string|max:255',
            'duration' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
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
        $data['course_chapter_lecture_id'] = $lectureId;

        // If order is not provided, make it the last one
        if (!isset($data['order'])) {
            $data['order'] = $lecture->videos()->max('order') + 1;
        }

        // Create video
        $video = CourseLectureVideo::create($data);

        // Update lecture duration if not set
        if (empty($lecture->duration) && !empty($data['duration'])) {
            $lecture->update(['duration' => $data['duration']]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Video created successfully',
            'data' => $video,
        ], 201);
    }

    /**
     * Display the specified video.
     */
    public function show($courseId, $chapterId, $lectureId, $videoId)
    {
        $course = Course::findOrFail($courseId);
        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $video = $lecture->videos()->findOrFail($videoId);

        return response()->json([
            'status' => 'success',
            'data' => $video,
        ]);
    }

    /**
     * Update the specified video.
     */
    public function update(Request $request, $courseId, $chapterId, $lectureId, $videoId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to update this video. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $video = $lecture->videos()->findOrFail($videoId);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|string|max:255',
            'duration' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
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

        // Update video
        $video->update($data);

        // Update lecture duration if changed
        if (isset($data['duration']) && $data['duration'] != $video->duration) {
            // Calculate total duration of all videos in this lecture
            $totalDuration = $lecture->videos()->sum('duration');
            $lecture->update(['duration' => $totalDuration]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Video updated successfully',
            'data' => $video,
        ]);
    }

    /**
     * Remove the specified video.
     */
    public function destroy($courseId, $chapterId, $lectureId, $videoId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to delete this video. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $video = $lecture->videos()->findOrFail($videoId);

        // Delete video
        $video->delete();

        // Recalculate lecture duration
        $totalDuration = $lecture->videos()->sum('duration');
        $lecture->update(['duration' => $totalDuration]);

        return response()->json([
            'status' => 'success',
            'message' => 'Video deleted successfully',
        ]);
    }
}
