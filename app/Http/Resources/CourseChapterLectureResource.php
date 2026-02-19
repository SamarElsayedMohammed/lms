<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CourseChapterLecture $resource
 */
final class CourseChapterLectureResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lecture = $this->resource;

        return [
            'id' => $lecture->id,
            'type' => 'lecture',
            'title' => $lecture->title,
            'slug' => $lecture->slug,
            'description' => $lecture->description,
            'file_type' => $lecture->file_type,
            'file_url' => $lecture->file_url_for_api,
            'duration' => $lecture->duration,
            'hours' => $lecture->hours ?? 0,
            'minutes' => $lecture->minutes ?? 0,
            'seconds' => $lecture->seconds ?? 0,
            'duration_formatted' => $this->formatDuration($lecture->duration),
            'free_preview' => $lecture->free_preview ?? false,
            'is_active' => $lecture->is_active,
            'chapter_order' => $lecture->chapter_order,
        ];
    }

    /**
     * Format duration in seconds to human-readable format
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "$hours hour" . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = "$minutes minute" . ($minutes > 1 ? 's' : '');
        }
        if ($remainingSeconds > 0 || empty($parts)) {
            $parts[] = "$remainingSeconds second" . ($remainingSeconds > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }
}
