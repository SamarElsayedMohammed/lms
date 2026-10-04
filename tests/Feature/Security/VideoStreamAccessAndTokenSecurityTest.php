<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Services\ContentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VideoStreamAccessAndTokenSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentAccessService::flushRequestCache();
    }

    public function test_fake_or_expired_hls_token_returns_403(): void
    {
        $fakeUuid = (string) Str::uuid();

        $response = $this->get("/api/hls/{$fakeUuid}/master.m3u8");

        $response->assertStatus(403);
    }

    public function test_fake_or_expired_direct_video_token_returns_403(): void
    {
        $fakeUuid = (string) Str::uuid();

        $response = $this->get("/api/video-direct/{$fakeUuid}");

        $response->assertStatus(403);
    }

    public function test_unenrolled_student_cannot_request_stream_for_paid_lecture(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create([
            'course_type' => 'paid',
            'price' => 100,
            'is_active' => true,
        ]);
        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'is_active' => true,
            'is_free' => false,
            'free_preview' => false,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/video/{$lecture->id}/stream");

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Subscription required');
    }

    public function test_revoked_student_access_blocks_hls_stream_serving(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create([
            'course_type' => 'paid',
            'price' => 100,
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
            'is_free' => false,
            'free_preview' => false,
        ]);

        // Place a token in cache claiming user has token, but student is NOT enrolled
        $uuid = (string) Str::uuid();
        Cache::put("hls_token:{$uuid}", json_encode([
            'lecture_id' => $lecture->id,
            'user_id' => $student->id,
            'is_free_preview' => false,
            'created_at' => now()->timestamp,
        ]), 1800);

        $response = $this->get("/api/hls/{$uuid}/master.m3u8");

        $response->assertStatus(403);
    }
}
