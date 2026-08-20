<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(6)),
            'title' => $title,
            'description' => fake()->paragraph(),
            'content' => fake()->paragraphs(3, true),
            'author' => fake()->name(),
            'cover_image' => null,
            'tags' => ['Skillso', 'مدونة'],
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
