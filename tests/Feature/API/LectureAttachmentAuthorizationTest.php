<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class LectureAttachmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_unenrolled_user_cannot_list_a_paid_lectures_attachments(): void
    {
        FeatureFlag::create([
            'key' => 'lecture_attachments',
            'name' => 'Lecture attachments',
            'is_enabled' => true,
        ]);
        Cache::forget('feature_flag:lecture_attachments');

        $user = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create(['course_type' => 'paid', 'price' => 100, 'is_active' => true]);
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id, 'is_active' => true]);
        $lecture = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapter->id,
            'is_active' => true,
            'is_free' => false,
            'free_preview' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/lecture/{$lecture->id}/attachments")
            ->assertForbidden();
    }
}
