<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\User;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\UserCurriculumTracking;
use App\Services\CourseProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class CourseProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CourseProgressService::class);
    }

    public function test_get_total_items_for_course_ignores_inactive_items()
    {
        $course = Course::factory()->create();
        
        $activeChapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => 1
        ]);
        
        $inactiveChapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => 0
        ]);

        // Active items in active chapter
        CourseChapterLecture::factory()->count(2)->create([
            'course_chapter_id' => $activeChapter->id,
            'is_active' => 1
        ]);

        // Inactive item in active chapter
        CourseChapterLecture::factory()->create([
            'course_chapter_id' => $activeChapter->id,
            'is_active' => 0
        ]);

        // Active item in inactive chapter (should be ignored)
        CourseChapterLecture::factory()->create([
            'course_chapter_id' => $inactiveChapter->id,
            'is_active' => 1
        ]);

        Cache::flush();
        
        $totalItems = $this->service->getTotalItemsForCourse($course->id);
        
        // Should only count the 2 active lectures in the active chapter
        $this->assertEquals(2, $totalItems);
    }

    public function test_calculate_and_update_progress_syncs_with_certificate_service()
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        
        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => 1
        ]);

        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'is_active' => 1
        ]);

        // Mock CertificateService to return false (e.g. pending assignment)
        $this->mock(\App\Services\CertificateService::class, function ($mock) {
            $mock->shouldReceive('checkCourseCompletionStatus')->andReturn(false);
        });

        // Add 100% curriculum tracking
        UserCurriculumTracking::factory()->create([
            'user_id' => $user->id,
            'course_chapter_id' => $chapter->id,
            'model_type' => CourseChapterLecture::class,
            'model_id' => $lecture->id,
            'status' => 'completed'
        ]);

        Cache::flush();

        $progress = $this->service->calculateAndUpdateProgress($user->id, $course->id);

        // Even though 1/1 items are completed, CertificateService returned false, so cap at 99.99
        $this->assertEquals(99.99, $progress->progress_percentage);
        $this->assertEquals('in_progress', $progress->status);
    }
}
