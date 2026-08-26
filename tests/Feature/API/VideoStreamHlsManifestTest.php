<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Services\ContentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VideoStreamHlsManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentAccessService::flushRequestCache();
    }

    public function test_hls_stream_returns_a_master_playlist_url_players_can_detect(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create([
            'is_free' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);
        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        $lecture = CourseChapterLecture::factory()->withHls()->create([
            'course_chapter_id' => $chapter->id,
            'type' => 'file',
            'file_extension' => 'mp4',
            'is_active' => true,
            'is_free' => true,
            'duration_seconds' => 600,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/video/{$lecture->id}/stream");

        $response->assertOk();
        $manifestUrl = (string) $response->json('data.manifest_url');
        $this->assertStringContainsString('/api/hls/', $manifestUrl);
        $this->assertStringContainsString('master.m3u8', $manifestUrl);
        $this->assertSame('hls', $response->json('data.type'));
        $this->assertTrue((bool) $response->json('data.has_hls'));
        $this->assertSame(600, (int) $response->json('data.duration'));
    }
}
