<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Course\CourseLanguage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodcastCourseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_podcast_filter_returns_only_explicitly_classified_courses(): void
    {
        $category = Category::create([
            'name' => 'Audio Learning',
            'slug' => 'audio-learning',
            'status' => 1,
            'is_active' => 1,
        ]);
        $language = CourseLanguage::create([
            'name' => 'English',
            'slug' => 'en',
            'is_active' => 1,
        ]);
        $author = User::factory()->create(['is_active' => 1]);

        $podcast = \Database\Factories\CourseFactory::new()->create([
            'title' => 'Leadership audio series',
            'meta_keywords' => 'leadership, podcast, audio',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $author->id,
        ]);
        $regularCourse = \Database\Factories\CourseFactory::new()->create([
            'title' => 'Leadership workshop',
            'meta_keywords' => 'leadership, workshop',
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $author->id,
        ]);

        $response = $this->getJson('/api/get-courses?is_podcast=1&per_page=20');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $podcast->id)
            ->assertJsonPath('data.0.is_podcast', true)
            ->assertJsonCount(1, 'data');

        $this->assertNotSame(
            $regularCourse->id,
            $response->json('data.0.id'),
        );
    }

    public function test_podcast_filter_rejects_invalid_boolean_values(): void
    {
        $this->getJson('/api/get-courses?is_podcast=not-a-boolean')
            ->assertStatus(422);
    }
}
