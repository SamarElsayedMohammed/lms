<?php

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderTrackingService
{
    /**
     * Create curriculum tracking entries in user_curriculum_trackings table
     * This creates entry for the first curriculum item of the first chapter of each course
     */
    public static function createCurriculumTrackingEntries(Order $order, $user)
    {
        try {
            $orderCourses = OrderCourse::where('order_id', $order->id)->get();
            $trackingEntries = [];

            foreach ($orderCourses as $orderCourse) {
                $course = Course::with(['chapters' => static function ($query): void {
                    $query
                        ->where('is_active', true)
                        ->orderBy('chapter_order', 'asc')
                        ->orderBy('id', 'asc')
                        ->with(['lectures' => static function ($q): void {
                            $q->where('is_active', true)->orderBy('chapter_order', 'asc')->orderBy('id', 'asc');
                        }])
                        ->with(['quizzes' => static function ($q): void {
                            $q->where('is_active', true)->orderBy('chapter_order', 'asc')->orderBy('id', 'asc');
                        }])
                        ->with(['assignments' => static function ($q): void {
                            $q->where('is_active', true)->orderBy('chapter_order', 'asc')->orderBy('id', 'asc');
                        }])
                        ->with(['resources' => static function ($q): void {
                            $q->where('is_active', true)->orderBy('chapter_order', 'asc')->orderBy('id', 'asc');
                        }]);
                }])->find($orderCourse->course_id);

                if (!$course || $course->chapters->isEmpty()) {
                    continue;
                }

                // Get the first chapter
                $firstChapter = $course->chapters->first();

                // Collect all curriculum items from first chapter
                $allItems = collect();

                // Add lectures
                foreach ($firstChapter->lectures as $lecture) {
                    $allItems->push([
                        'id' => $lecture->id,
                        'type' => \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class,
                        'chapter_order' => $lecture->chapter_order ?? 0,
                    ]);
                }

                // Add quizzes
                foreach ($firstChapter->quizzes as $quiz) {
                    $allItems->push([
                        'id' => $quiz->id,
                        'type' => \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class,
                        'chapter_order' => $quiz->chapter_order ?? 0,
                    ]);
                }

                // Add assignments
                foreach ($firstChapter->assignments as $assignment) {
                    $allItems->push([
                        'id' => $assignment->id,
                        'type' => \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class,
                        'chapter_order' => $assignment->chapter_order ?? 0,
                    ]);
                }

                // Add resources
                foreach ($firstChapter->resources as $resource) {
                    $allItems->push([
                        'id' => $resource->id,
                        'type' => \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class,
                        'chapter_order' => $resource->chapter_order ?? 0,
                    ]);
                }

                // Get the first curriculum item (sorted by chapter_order)
                $firstItem = $allItems->sortBy('chapter_order')->first();

                if ($firstItem) {
                    $trackingEntries[] = [
                        'user_id' => $user->id,
                        'course_chapter_id' => $firstChapter->id,
                        'model_id' => $firstItem['id'],
                        'model_type' => $firstItem['type'],
                        'status' => 'in_progress',
                        'started_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Insert all tracking entries in batches to avoid duplicates
            if (!empty($trackingEntries)) {
                // Use insertOrIgnore to avoid duplicate key errors
                foreach (array_chunk($trackingEntries, 500) as $chunk) {
                    DB::table('user_curriculum_trackings')->insertOrIgnore($chunk);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to create curriculum tracking entries: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'user_id' => $user->id,
            ]);

            // Don't throw exception, just log the error
        }
    }
}
