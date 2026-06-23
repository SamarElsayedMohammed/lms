<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\VideoProgressService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LectureProgressApiController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly VideoProgressService $videoProgressService
    ) {}

    /**
     * Update video watch progress using segment tracking.
     */
    public function updateProgress(Request $request, int $lectureId): JsonResponse
    {
        // Support both old format (watched_seconds) and new format (newly_watched_segments)
        $hasSegments = $request->has('newly_watched_segments');

        if ($hasSegments) {
            $validated = $request->validate([
                'current_position' => 'required|integer|min:0',
                'total_duration' => 'required|integer|min:1',
                'newly_watched_segments' => 'required|array|max:' . VideoProgressService::MAX_SEGMENTS_PER_REQUEST,
                'newly_watched_segments.*' => 'integer|min:0',
            ]);
        } else {
            // Legacy format support
            $validated = $request->validate([
                'watched_seconds' => 'required|integer|min:0',
                'last_position' => 'required|integer|min:0',
                'total_seconds' => 'required|integer|min:1',
            ]);
        }

        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        if ($hasSegments) {
            // New segment-based tracking
            $progress = $this->videoProgressService->updateSegmentProgress(
                $user,
                $lecture,
                (int) $validated['current_position'],
                (int) $validated['total_duration'],
                $validated['newly_watched_segments']
            );

            return $this->ok(
                data: [
                    'watch_percentage' => (float) $progress->watch_percentage,
                    'is_completed' => $progress->is_completed,
                    'completed_segments' => $progress->completed_segments,
                    'total_segments' => $progress->total_segments,
                    'last_position' => $progress->last_position,
                    'can_seek_to' => $this->videoProgressService->getMaxSeekablePosition($progress),
                ],
                message: 'Progress updated'
            );
        }

        // Legacy format - use existing method
        $progress = $this->videoProgressService->updateProgress(
            $user,
            $lecture,
            (int) $validated['watched_seconds'],
            (int) $validated['last_position'],
            (int) $validated['total_seconds']
        );

        return $this->ok(
            data: [
                'watched_seconds' => $progress->watched_seconds,
                'watch_percentage' => (float) $progress->watch_percentage,
                'is_completed' => $progress->is_completed,
                'last_position' => $progress->last_position,
            ],
            message: 'Progress updated'
        );
    }

    /**
     * Get video progress for a lecture (with segment info).
     */
    public function getProgress(int $lectureId): JsonResponse
    {
        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        $progress = $this->videoProgressService->getProgressWithSeekInfo($user, $lecture);

        if ($progress === null) {
            return $this->ok(data: [
                'watched_seconds' => 0,
                'total_seconds' => 0,
                'watch_percentage' => 0.0,
                'last_position' => 0,
                'is_completed' => false,
                'watched_segments' => [],
                'total_segments' => 0,
                'completed_segments' => 0,
                'can_seek_to' => 0,
                'resume_from' => 0,
            ]);
        }

        return $this->ok(data: $progress);
    }

    /**
     * Get full course progress breakdown.
     */
    public function getCourseProgress(int $courseId): JsonResponse
    {
        $course = Course::find($courseId);
        if ($course === null) {
            return $this->notFound('Course not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        $overallPercentage = $this->videoProgressService->getCourseProgress($user, $course);

        $lessons = [];
        foreach ($course->chapters()->orderBy('chapter_order')->get() as $chapter) {
            foreach ($chapter->lectures()->orderBy('chapter_order')->get() as $lecture) {
                $progress = $this->videoProgressService->getProgress($user, $lecture);
                $lessons[] = [
                    'lecture_id' => $lecture->id,
                    'title' => $lecture->title,
                    'watch_percentage' => $progress !== null ? (float) $progress['watch_percentage'] : 0,
                    'is_completed' => $progress !== null && $progress['is_completed'],
                ];
            }
        }

        return $this->ok(data: [
            'course_id' => $course->id,
            'overall_percentage' => $overallPercentage,
            'lessons' => $lessons,
        ]);
    }
}
