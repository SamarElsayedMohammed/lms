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

    public function test_update_progress_with_segments(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
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
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 50,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2, 3, 4, 5], // More than MAX_SEGMENTS_PER_REQUEST (3)
        ]);

        $response->assertStatus(422);
    }

    public function test_update_progress_rejects_a_forged_duration(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 10,
            'newly_watched_segments' => [0],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_progress_rejects_skipping_unwatched_segments(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

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
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

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
                'success',
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

    public function test_legacy_format_is_rejected_to_prevent_forged_progress(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'watched_seconds' => 30,
            'last_position' => 30,
            'total_seconds' => 60,
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(401);
    }

    public function test_nonexistent_lecture_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/lecture/99999/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(404);
    }

    public function test_progress_accumulates_over_multiple_requests(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        // First request - watch segments 0, 1, 2
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
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create(['duration_seconds' => 60]);

        $response = $this->actingAs($user)->getJson("/api/lecture/{$lecture->id}/progress");

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.watch_percentage'));
        $this->assertFalse($response->json('data.is_completed'));
        $this->assertEquals([], $response->json('data.watched_segments'));
    }
}
