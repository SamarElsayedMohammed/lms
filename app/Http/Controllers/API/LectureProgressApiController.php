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
     * Update video watch progress.
     */
    public function updateProgress(Request $request, int $lectureId): JsonResponse
    {
        $validated = $request->validate([
            'watched_seconds' => 'required|integer|min:0',
            'last_position' => 'required|integer|min:0',
            'total_seconds' => 'required|integer|min:1',
        ]);

        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

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
     * Get video progress for a lecture.
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

        $progress = $this->videoProgressService->getProgress($user, $lecture);

        if ($progress === null) {
            return $this->ok(data: [
                'watched_seconds' => 0,
                'total_seconds' => 0,
                'watch_percentage' => 0.0,
                'last_position' => 0,
                'is_completed' => false,
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
