<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LectureProgressSegmentsApiTest extends TestCase
{
    use RefreshDatabase;

    private function createAccessibleLecture(int $duration = 60): CourseChapterLecture
    {
        $course = \App\Models\Course\Course::factory()->create([
            'is_free' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        return CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'duration_seconds' => $duration,
            'hours' => (int) floor($duration / 3600),
            'minutes' => (int) floor(($duration % 3600) / 60),
            'seconds' => (int) ($duration % 60),
            'type' => 'file',
            'file_extension' => 'mp4',
            'is_active' => true,
            'is_free' => true,
            'free_preview' => true,
        ]);
    }

    public function test_update_progress_with_segments(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'error',
                'message',
                'data' => [
                    'watch_percentage',
                    'is_completed',
                    'completed_segments',
                    'total_segments',
                    'last_position',
                    'can_seek_to',
                ],
            ]);

        $this->assertEquals(16.67, $response->json('data.watch_percentage'));
        $this->assertEquals(1, $response->json('data.completed_segments'));
    }

    public function test_update_progress_rejects_too_many_segments(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 50,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2, 3, 4, 5], // More than MAX_SEGMENTS_PER_REQUEST (3)
        ]);

        $response->assertStatus(422);
    }

    public function test_update_progress_rejects_a_forged_duration(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 10,
            'newly_watched_segments' => [0],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_progress_rejects_skipping_unwatched_segments(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 60,
            'total_duration' => 60,
            'newly_watched_segments' => [5],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.completed_segments', 0)
            ->assertJsonPath('data.is_completed', false);
    }

    public function test_get_progress_returns_segment_info(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        // First create some progress
        $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        // Then fetch it
        $response = $this->actingAs($user)->getJson("/api/lecture/{$lecture->id}/progress");

        $response->assertOk()
            ->assertJsonStructure([
                'error',
                'message',
                'data' => [
                    'watched_seconds',
                    'total_seconds',
                    'watch_percentage',
                    'last_position',
                    'is_completed',
                    'watched_segments',
                    'total_segments',
                    'completed_segments',
                    'can_seek_to',
                    'resume_from',
                ],
            ]);

        $this->assertIsArray($response->json('data.watched_segments'));
    }

    public function test_standard_watch_time_tracking_is_supported(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'watched_seconds' => 30,
            'last_position' => 30,
            'total_seconds' => 60,
        ]);

        $response->assertOk();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(401);
    }

    public function test_nonexistent_lecture_returns_404(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson("/api/lecture/99999/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(404);
    }

    public function test_progress_accumulates_over_multiple_requests(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        // First request - watch segments 0
        $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        // Second request - watch the next contiguous segment.
        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 20,
            'total_duration' => 60,
            'newly_watched_segments' => [1],
        ]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.completed_segments'));
    }

    public function test_empty_progress_returns_defaults(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(60);

        $response = $this->actingAs($user)->getJson("/api/lecture/{$lecture->id}/progress");

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.watch_percentage'));
        $this->assertFalse($response->json('data.is_completed'));
        $this->assertEquals([], $response->json('data.watched_segments'));
    }

    public function test_update_progress_self_heals_unconfigured_lecture_duration(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $lecture = $this->createAccessibleLecture(0); // Lecture created with 0 duration

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 120,
            'newly_watched_segments' => [0],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.total_seconds', 120)
            ->assertJsonPath('data.completed_segments', 1);

        $lecture->refresh();
        $this->assertEquals(120, $lecture->duration_seconds);
    }
}
