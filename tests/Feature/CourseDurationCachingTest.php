<?php

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CourseDurationCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecture_creation_dispatches_recalculate_job()
    {
        Queue::fake();

        $course = Course::factory()->create();
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);

        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'duration_seconds' => 3600,
        ]);

        Queue::assertPushed(\App\Jobs\RecalculateCourseDurationJob::class, function ($job) use ($course) {
            return $job->courseId === $course->id;
        });
    }

    public function test_recalculate_job_updates_durations_correctly()
    {
        $course = Course::factory()->create(['duration_seconds' => 0, 'lectures_count' => 0]);
        $chapter1 = CourseChapter::factory()->create(['course_id' => $course->id, 'duration_seconds' => 0]);
        $chapter2 = CourseChapter::factory()->create(['course_id' => $course->id, 'duration_seconds' => 0]);

        CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter1->id, 'duration_seconds' => 120]);
        CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter1->id, 'duration_seconds' => 60]);
        
        CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter2->id, 'duration_seconds' => 300]);

        // Manually run job
        $job = new \App\Jobs\RecalculateCourseDurationJob($course->id);
        $job->handle();

        $course->refresh();
        $chapter1->refresh();
        $chapter2->refresh();

        $this->assertEquals(180, $chapter1->duration_seconds);
        $this->assertEquals(300, $chapter2->duration_seconds);
        
        $this->assertEquals(480, $course->duration_seconds);
        $this->assertEquals(3, $course->lectures_count);
    }
}
