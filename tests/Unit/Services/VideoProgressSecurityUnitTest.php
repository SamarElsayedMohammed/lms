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

    public function test_get_canonical_duration_prefers_duration_seconds(): void
    {
        $lecture = new CourseChapterLecture([
            'duration_seconds' => 3600,
            'hours' => 2,
            'minutes' => 0,
            'seconds' => 0,
            'total_duration' => '60:00',
            'duration' => 1800,
        ]);

        $duration = $this->service->getCanonicalDuration($lecture);
        $this->assertEquals(3600, $duration);
    }

    public function test_get_canonical_duration_calculates_from_hms_fields(): void
    {
        $lecture = new CourseChapterLecture([
            'duration_seconds' => null,
            'hours' => 1,
            'minutes' => 30,
            'seconds' => 15,
            'total_duration' => null,
            'duration' => null,
        ]);

        // (1 * 3600) + (30 * 60) + 15 = 3600 + 1800 + 15 = 5415
        $duration = $this->service->getCanonicalDuration($lecture);
        $this->assertEquals(5415, $duration);
    }

    public function test_get_canonical_duration_falls_back_to_total_duration_and_duration(): void
    {
        $lectureWithTotal = new CourseChapterLecture([
            'duration_seconds' => null,
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
            'total_duration' => '120',
            'duration' => null,
        ]);
        $this->assertEquals(120, $this->service->getCanonicalDuration($lectureWithTotal));

        $lectureWithDuration = new CourseChapterLecture([
            'duration_seconds' => null,
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
            'total_duration' => null,
            'duration' => 450,
        ]);
        $this->assertEquals(450, $this->service->getCanonicalDuration($lectureWithDuration));
    }

    public function test_get_canonical_duration_returns_zero_when_no_duration_set(): void
    {
        $lecture = new CourseChapterLecture([
            'duration_seconds' => null,
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
            'total_duration' => null,
            'duration' => null,
        ]);

        $duration = $this->service->getCanonicalDuration($lecture);
        $this->assertEquals(0, $duration);
    }

    public function test_update_progress_with_segments_rejects_missing_canonical_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lecture duration is not yet set by the server. Progress tracking is temporarily unavailable.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture(['id' => 888, 'duration_seconds' => 0]);

        $this->service->updateSegmentProgress(
            $user,
            $lecture,
            10,
            10,
            [0],
            []
        );
    }

    public function test_update_progress_with_segments_rejects_duration_shrink_spoofing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The reported video duration cannot shrink canonical lecture duration.');

        $user = new \App\Models\User(['id' => 999]);
        $lecture = new CourseChapterLecture(['id' => 888, 'duration_seconds' => 3600]);

        $this->service->updateProgressWithSegments(
            $user,
            $lecture,
            10,
            10, // Attempting to shrink from 3600s to 10s
            [0],
            []
        );
    }
}
