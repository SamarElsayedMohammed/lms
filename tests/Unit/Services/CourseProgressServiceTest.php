<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\User;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\UserCurriculumTracking;
use App\Models\UserCourseProgress;
use App\Models\VideoProgress;
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

    public function test_calculate_and_update_progress_marks_a_completed_curriculum_as_completed()
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

        // Certificate issuance is a side-effect of an authoritative 100% result.
        $this->mock(\App\Services\CertificateService::class, function ($mock) {
            $mock->shouldReceive('autoGenerateCertificate')->once()->andReturn(null);
        });

        // Add 100% curriculum tracking and video progress
        UserCurriculumTracking::create([
            'user_id' => $user->id,
            'course_chapter_id' => $chapter->id,
            'model_type' => CourseChapterLecture::class,
            'model_id' => $lecture->id,
            'status' => 'completed'
        ]);

        VideoProgress::create([
            'user_id' => $user->id,
            'lecture_id' => $lecture->id,
            'watched_seconds' => 100,
            'total_seconds' => 100,
            'watch_percentage' => 100,
            'is_completed' => true,
        ]);

        Cache::flush();

        $progress = $this->service->calculateAndUpdateProgress($user->id, $course->id);

        $this->assertEquals(100.0, $progress->progress_percentage);
        $this->assertEquals('completed', $progress->status);
    }

    public function test_cached_dashboard_progress_reconciles_stale_aggregate_from_video_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id, 'is_active' => true]);
        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'is_active' => true,
            'type' => 'file',
            'file_extension' => 'mp4',
        ]);

        $actualAccessAt = now()->subDay()->startOfMinute();
        UserCourseProgress::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'total_items' => 1,
            'completed_items' => 0,
            'progress_percentage' => 0,
            'status' => 'not_started',
            'last_accessed_at' => $actualAccessAt,
        ]);
        VideoProgress::create([
            'user_id' => $user->id,
            'lecture_id' => $lecture->id,
            'watched_seconds' => 25,
            'total_seconds' => 100,
            'watch_percentage' => 25,
            'is_completed' => false,
        ]);

        Cache::flush();
        $progress = $this->service->getProgressWithCache($user->id, $course->id);

        $this->assertEquals(25.0, $progress->progress_percentage);
        $this->assertEquals('in_progress', $progress->status);
        $this->assertEquals(1, $progress->total_items);
        $this->assertEquals(0, $progress->completed_items);
        $this->assertTrue($progress->last_accessed_at->equalTo($actualAccessAt));
    }

    public function test_progress_normalizes_corrupt_video_percentage_to_100(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'is_active' => true,
            'type' => 'file',
            'file_extension' => 'mp4',
        ]);

        VideoProgress::create([
            'user_id' => $user->id,
            'lecture_id' => $lecture->id,
            'watched_seconds' => 150,
            'total_seconds' => 100,
            'watch_percentage' => 150,
            'is_completed' => true,
        ]);

        $this->mock(\App\Services\CertificateService::class, function ($mock) {
            $mock->shouldReceive('autoGenerateCertificate')->once()->andReturn(null);
        });

        Cache::flush();
        $progress = $this->service->getProgressWithCache($user->id, $course->id);

        $this->assertSame(100.0, (float) $progress->progress_percentage);
    }
}
