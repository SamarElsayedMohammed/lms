<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Article $article */
        $article = $this->resource;
        $coverImage = $article->coverImageUrl();

        return [
            'id' => $article->id,
            'slug' => $article->slug,
            'title' => $article->title,
            'description' => $article->description ?? '',
            'content' => $article->content ?? '',
            'author' => $article->author ?: 'فريق Skillso',
            'cover_image' => $coverImage,
            'coverImage' => $coverImage,
            'tags' => array_values(array_filter(array_map('strval', $article->tags ?? []))),
            'is_published' => (bool) $article->is_published,
            'published_at' => optional($article->published_at)->toIso8601String(),
            'datePublished' => optional($article->published_at ?: $article->created_at)->toIso8601String(),
            'dateModified' => optional($article->updated_at)->toIso8601String(),
            'created_at' => optional($article->created_at)->toIso8601String(),
            'updated_at' => optional($article->updated_at)->toIso8601String(),
        ];
    }
}
