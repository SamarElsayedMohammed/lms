<?php

namespace App\Traits;

use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;

trait HasChapterOrder
{
    /**
     * Get the next chapter order number across all content types in a chapter
     */
    public static function getNextChapterOrder($chapterId)
    {
        // Get max chapter_order from all chapter content models
        $lectureMaxOrder = CourseChapterLecture::where('course_chapter_id', $chapterId)->max('chapter_order') ?? 0;
        $assignmentMaxOrder =
            CourseChapterAssignment::where('course_chapter_id', $chapterId)->max('chapter_order') ?? 0;
        $quizMaxOrder = CourseChapterQuiz::where('course_chapter_id', $chapterId)->max('chapter_order') ?? 0;
        $resourceMaxOrder = CourseChapterResource::where('course_chapter_id', $chapterId)->max('chapter_order') ?? 0;

        return max($lectureMaxOrder, $assignmentMaxOrder, $quizMaxOrder, $resourceMaxOrder) + 1;
    }

    /**
     * Automatically set chapter order before creating
     */
    protected static function bootHasChapterOrder()
    {
        static::creating(static function ($model): void {
            if (empty($model->chapter_order)) {
                // Determine chapter ID based on model type
                $chapterId = null;
                if (isset($model->course_chapter_id)) {
                    $chapterId = $model->course_chapter_id;
                }

                if ($chapterId) {
                    $model->chapter_order = static::getNextChapterOrder($chapterId);
                }
            }
        });
    }
}
