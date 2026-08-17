<?php

namespace Tests\Unit\Services;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use PHPUnit\Framework\TestCase;

class VideoProgressSecurityUnitTest extends TestCase
{
    private VideoProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VideoProgressService(new FeatureFlagService());
    }

    public function test_get_canonical_duration_prefers_duration_seconds()
    {
        $lecture = new CourseChapterLecture([
            'duration_seconds' => 3600,
            'total_duration' => '60:00',
            'duration' => 1800,
        ]);

        $duration = $this->service->getCanonicalDuration($lecture);
        $this->assertEquals(3600, $duration);
    }

    public function test_get_canonical_duration_returns_zero_when_no_duration_set()
    {
        $lecture = new CourseChapterLecture([
            'duration_seconds' => null,
            'total_duration' => null,
            'duration' => null,
        ]);

        $duration = $this->service->getCanonicalDuration($lecture);
        $this->assertEquals(0, $duration);
    }

    public function test_update_progress_with_segments_rejects_missing_canonical_duration()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lecture duration is not yet set by the server. Progress tracking is temporarily unavailable.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture(['id' => 888, 'duration_seconds' => 0]);

        $this->service->updateSegmentProgress(
            $user,
            $lecture,
            10, // Spoofed duration
            10,
            [0],
            []
        );
    }

    public function test_update_progress_with_segments_rejects_duration_shrink_spoofing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The reported video duration cannot shrink canonical lecture duration.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture(['id' => 888, 'duration_seconds' => 3600]);

        $this->service->updateSegmentProgress(
            $user,
            $lecture,
            10, // Attempting to shrink from 3600s to 10s
            10,
            [0],
            []
        );
    }
}
