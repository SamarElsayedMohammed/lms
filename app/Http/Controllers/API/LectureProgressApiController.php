<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\ContentAccessService;
use App\Services\CourseProgressService;
use App\Services\VideoProgressService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LectureProgressApiController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly VideoProgressService $videoProgressService,
        private readonly CourseProgressService $courseProgressService,
        private readonly ContentAccessService $contentAccessService,
    ) {}

    /**
     * Update video watch progress using segment tracking.
     */
    public function updateProgress(Request $request, int $lectureId): JsonResponse
    {
        $hasSegments = $request->has('newly_watched_segments');

        if (!$hasSegments) {
            return response()->json([
                'success' => false,
                'message' => 'Segment-based tracking is required for video progress.',
            ], 422);
        }

        $metadataRules = [
            'session_id' => 'nullable|string|max:255',
            'device' => 'nullable|string|max:255',
            'browser' => 'nullable|string|max:255',
            'progress_state' => 'nullable|string|in:playing,paused,seeking,ended',
        ];

        $validated = $request->validate(array_merge([
            'current_position' => 'required|integer|min:0',
            'total_duration' => 'required|integer|min:1',
            'newly_watched_segments' => 'required|array|max:' . VideoProgressService::MAX_SEGMENTS_PER_REQUEST,
            'newly_watched_segments.*' => 'integer|min:0',
        ], $metadataRules));

        // Extract metadata for the service
        $metadata = [
            'session_id' => $validated['session_id'] ?? null,
            'device' => $validated['device'] ?? null,
            'browser' => $validated['browser'] ?? null,
            'ip' => $request->ip(),
            'progress_state' => $validated['progress_state'] ?? 'playing',
        ];

        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        // Authentication alone is not an entitlement. Without this guard, an
        // authenticated user could manufacture completion data for a course
        // they cannot access and potentially satisfy downstream eligibility.
        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return $this->forbidden('Course access required');
        }

        $canonicalDuration = $this->videoProgressService->getCanonicalDuration($lecture);
        if ($canonicalDuration <= 0 || (int) $validated['total_duration'] !== $canonicalDuration) {
            return response()->json([
                'success' => false,
                'message' => 'The video duration must match the server-side lecture duration.',
            ], 422);
        }

        $progress = $this->videoProgressService->updateSegmentProgress(
            $user,
            $lecture,
            (int) $validated['current_position'],
            $canonicalDuration,
            $validated['newly_watched_segments'],
            $metadata
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

        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return $this->forbidden('Course access required');
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
     *
     * Uses CourseProgressService as the single source of truth so that
     * quizzes, assignments, and resources are included in the percentage
     * (not just video lectures).
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

        if (!$this->contentAccessService->canAccessCourse($user, $course)) {
            return $this->forbidden('Course access required');
        }

        try {
            $detailed = $this->courseProgressService->getDetailedProgress($user->id, $courseId);
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve course progress');
        }

        // Flatten per-lecture detail for backward-compat with existing consumers.
        $lessons = [];
        foreach ($detailed['chapters'] as $chapter) {
            foreach ($chapter['items'] as $item) {
                if ($item['type'] !== 'lecture') {
                    continue;
                }
                $lessons[] = [
                    'lecture_id'       => $item['item_id'],
                    'title'            => $item['title'],
                    'watch_percentage' => $item['watch_percentage'] ?? 0,
                    'is_completed'     => $item['status'] === 'completed',
                ];
            }
        }

        return $this->ok(data: [
            'course_id'          => $courseId,
            'overall_percentage' => $detailed['course']['progress_percentage'],
            'status'             => $detailed['course']['status'],
            'completed_items'    => $detailed['summary']['completed_items'],
            'total_items'        => $detailed['summary']['total_items'],
            'next_item'          => $detailed['next_item'],
            'lessons'            => $lessons,
        ]);
    }
}
