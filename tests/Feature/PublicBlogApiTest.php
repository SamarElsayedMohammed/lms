<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBlogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_returns_only_published_articles(): void
    {
        Article::factory()->create([
            'slug' => 'published-article',
            'title' => 'مقال منشور',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
        Article::factory()->draft()->create([
            'slug' => 'draft-article',
            'title' => 'مقال مسودة',
        ]);

        $response = $this->getJson('/api/blog');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('مقال منشور', $titles);
        $this->assertNotContains('مقال مسودة', $titles);
    }

    public function test_public_show_returns_article_by_slug_and_alias(): void
    {
        Article::factory()->create([
            'slug' => 'nextjs-15',
            'title' => 'Next.js 15',
            'content' => 'تفاصيل المقال',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/blog/nextjs-15')
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'nextjs-15')
            ->assertJsonPath('data.content', 'تفاصيل المقال');

        $this->getJson('/api/article/nextjs-15')
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'nextjs-15');
    }

    public function test_draft_article_is_hidden_from_public_show(): void
    {
        Article::factory()->draft()->create([
            'slug' => 'hidden-draft',
            'title' => 'مخفي',
        ]);

        $this->getJson('/api/blog/hidden-draft')->assertStatus(404);
    }
}
