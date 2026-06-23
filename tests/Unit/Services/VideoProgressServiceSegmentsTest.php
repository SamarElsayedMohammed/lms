<?php

namespace Tests\Unit\Services;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VideoProgressServiceSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private VideoProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $featureFlagService = Mockery::mock(FeatureFlagService::class);
        $featureFlagService->shouldReceive('isEnabled')->andReturn(true);
        
        $this->service = new VideoProgressService($featureFlagService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_initialize_segments_creates_correct_array(): void
    {
        // 30 second video with 5-second segments = 6 segments
        $segments = VideoProgress::initializeSegments(30, 5);
        
        $this->assertCount(6, $segments);
        $this->assertEquals([0, 0, 0, 0, 0, 0], $segments);
    }

    public function test_initialize_segments_rounds_up_partial_segments(): void
    {
        // 33 second video with 5-second segments = 7 segments (ceil)
        $segments = VideoProgress::initializeSegments(33, 5);
        
        $this->assertCount(7, $segments);
    }

    public function test_get_max_seekable_position_with_empty_progress(): void
    {
        $progress = new VideoProgress([
            'is_completed' => false,
            'watched_segments' => [],
            'total_seconds' => 60,
            'segment_size' => 5,
        ]);
        
        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(0, $maxSeek);
    }

    public function test_get_max_seekable_position_with_continuous_segments(): void
    {
        $progress = new VideoProgress([
            'is_completed' => false,
            'watched_segments' => [1, 1, 1, 0, 0, 0],
            'total_seconds' => 30,
            'segment_size' => 5,
        ]);
        
        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(15, $maxSeek); // 3 segments * 5 seconds
    }

    public function test_get_max_seekable_position_with_gap(): void
    {
        $progress = new VideoProgress([
            'is_completed' => false,
            'watched_segments' => [1, 1, 0, 1, 1, 1], // Gap at index 2
            'total_seconds' => 30,
            'segment_size' => 5,
        ]);
        
        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(10, $maxSeek); // Only 2 continuous segments from start
    }

    public function test_completed_video_allows_full_seek(): void
    {
        $progress = new VideoProgress([
            'is_completed' => true,
            'watched_segments' => [1, 1, 1, 1, 1, 1],
            'total_seconds' => 30,
            'segment_size' => 5,
        ]);
        
        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(30, $maxSeek); // Full duration
    }

    public function test_constants_are_defined(): void
    {
        $this->assertEquals(100.0, VideoProgressService::COMPLETION_THRESHOLD);
        $this->assertEquals(5, VideoProgressService::DEFAULT_SEGMENT_SIZE);
        $this->assertEquals(3, VideoProgressService::MAX_SEGMENTS_PER_REQUEST);
    }
}
