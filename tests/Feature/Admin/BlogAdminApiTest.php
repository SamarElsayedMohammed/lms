<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'blog-admin@skillso.test']);
        $this->admin->assignRole($role);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/blog')->assertStatus(401);
    }

    public function test_admin_can_list_create_update_and_delete_articles(): void
    {
        $create = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/blog', [
            'title' => 'مقال تجريبي عن التعلم',
            'description' => 'وصف قصير',
            'content' => '# محتوى الماركداون',
            'author' => 'فريق Skillso',
            'tags' => ['تعليم', 'Skillso'],
            'coverImage' => 'https://example.com/cover.jpg',
            'datePublished' => '2026-08-20T12:00:00Z',
        ]);

        $create->assertStatus(201);
        $create->assertJsonPath('success', true);
        $slug = $create->json('data.slug');
        $this->assertNotEmpty($slug);
        $this->assertSame('مقال تجريبي عن التعلم', $create->json('data.title'));
        $this->assertSame('https://example.com/cover.jpg', $create->json('data.cover_image'));

        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/blog');
        $list->assertStatus(200);
        $this->assertNotEmpty($list->json('data.data') ?? $list->json('data'));

        $update = $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/blog/' . $slug, [
            'title' => 'مقال محدّث',
            'description' => 'وصف محدّث',
            'content' => 'محتوى جديد',
            'author' => 'محرر Skillso',
            'tags' => ['تحديث'],
            'slug' => $slug,
        ]);
        $update->assertStatus(200);
        $update->assertJsonPath('data.title', 'مقال محدّث');

        $delete = $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/admin/blog/' . $slug);
        $delete->assertStatus(200);
        $this->assertDatabaseMissing('articles', ['slug' => $slug]);
    }

    public function test_upload_image_stores_public_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('cover.jpg', 800, 400);
        $response = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/admin/blog/upload-image', ['image' => $file], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.url'));
        $path = $response->json('data.path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_blob_cover_urls_are_not_persisted(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/blog', [
            'title' => 'مقال بدون صورة مؤقتة',
            'content' => 'نص',
            'coverImage' => 'blob:http://localhost/abc',
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.cover_image'));
        $this->assertNull(Article::query()->first()?->cover_image);
    }
}
