<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;

class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/articles.json');
        if (!is_file($path)) {
            return;
        }

        $raw = file_get_contents($path);
        $articles = json_decode((string) $raw, true);
        if (!is_array($articles)) {
            return;
        }

        foreach ($articles as $article) {
            if (!is_array($article) || empty($article['slug']) || empty($article['title'])) {
                continue;
            }

            Article::query()->updateOrCreate(
                ['slug' => (string) $article['slug']],
                [
                    'title' => (string) $article['title'],
                    'description' => (string) ($article['description'] ?? ''),
                    'content' => (string) ($article['content'] ?? ''),
                    'author' => (string) ($article['author'] ?? 'فريق Skillso'),
                    'cover_image' => $article['coverImage'] ?? $article['cover_image'] ?? null,
                    'tags' => $article['tags'] ?? ['Skillso'],
                    'is_published' => true,
                    'published_at' => $article['datePublished'] ?? $article['published_at'] ?? now(),
                ]
            );
        }
    }
}
