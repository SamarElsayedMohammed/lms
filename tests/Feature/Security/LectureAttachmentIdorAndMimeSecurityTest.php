<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Instructor;
use App\Models\LectureAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class LectureAttachmentIdorAndMimeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructorA;
    private User $instructorB;
    private User $admin;
    private CourseChapterLecture $lectureA;
    private LectureAttachment $attachmentA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'Instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->instructorA = User::factory()->create(['is_active' => true]);
        $this->instructorA->assignRole('Admin');

        $this->instructorB = User::factory()->create(['is_active' => true]);
        $this->instructorB->assignRole('Admin');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');

        $courseA = Course::factory()->create([
            'user_id' => $this->instructorA->id,
            'course_type' => 'paid',
            'price' => 50,
            'is_active' => true,
        ]);

        $chapterA = CourseChapter::factory()->create([
            'course_id' => $courseA->id,
            'is_active' => true,
        ]);

        $this->lectureA = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $chapterA->id,
            'is_active' => true,
            'is_free' => false,
            'free_preview' => false,
        ]);

        $this->attachmentA = LectureAttachment::create([
            'lecture_id' => $this->lectureA->id,
            'file_name' => 'notes.pdf',
            'file_path' => 'courses/attachments/notes.pdf',
            'file_size' => 1024,
            'file_type' => 'application/pdf',
            'sort_order' => 1,
        ]);
    }

    public function test_instructor_b_cannot_view_instructor_a_lecture_attachments(): void
    {
        $response = $this->actingAs($this->instructorB, 'sanctum')
            ->getJson("/api/admin/lecture/{$this->lectureA->id}/attachments");

        $response->assertStatus(403);
    }

    public function test_instructor_b_cannot_upload_attachment_to_instructor_a_lecture(): void
    {
        $file = UploadedFile::fake()->create('hacked.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->instructorB, 'sanctum')
            ->postJson("/api/admin/lecture/{$this->lectureA->id}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_instructor_b_cannot_update_attachment_of_instructor_a_lecture(): void
    {
        $file = UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->instructorB, 'sanctum')
            ->putJson("/api/admin/lecture/{$this->lectureA->id}/attachments/{$this->attachmentA->id}", [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_instructor_b_cannot_delete_attachment_of_instructor_a_lecture(): void
    {
        $response = $this->actingAs($this->instructorB, 'sanctum')
            ->deleteJson("/api/admin/lecture/{$this->lectureA->id}/attachments/{$this->attachmentA->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('lecture_attachments', ['id' => $this->attachmentA->id]);
    }

    public function test_disallowed_mime_type_on_update_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('exploit.php', 10, 'application/x-php');

        $response = $this->actingAs($this->instructorA, 'sanctum')
            ->putJson("/api/admin/lecture/{$this->lectureA->id}/attachments/{$this->attachmentA->id}", [
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_course_owner_can_manage_their_lecture_attachments(): void
    {
        $response = $this->actingAs($this->instructorA, 'sanctum')
            ->getJson("/api/admin/lecture/{$this->lectureA->id}/attachments");

        $response->assertStatus(200);
        $response->assertJsonPath('error', false);
        $this->assertCount(1, $response->json('data.attachments'));
    }

    public function test_super_admin_can_manage_any_lecture_attachments(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/lecture/{$this->lectureA->id}/attachments");

        $response->assertStatus(200);
        $response->assertJsonPath('error', false);
    }
}
