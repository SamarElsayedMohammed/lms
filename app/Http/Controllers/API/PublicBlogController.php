<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class PublicBlogController extends Controller
{
    public function index(Request $request): void
    {
        $search = trim((string) $request->input('search', $request->input('q', '')));
        $perPage = min((int) $request->input('per_page', $request->input('limit', 50)), 100);

        $query = Article::query()
            ->published()
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('author', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $articles = $query->paginate($perPage);

        ApiResponseService::successResponse(
            'Articles retrieved successfully',
            ArticleResource::collection($articles)
        );
    }

    public function show(string $slug): void
    {
        $article = Article::query()->published()->where('slug', $slug)->first();
        if (!$article) {
            ApiResponseService::errorResponse('Article not found', [], 404);
        }

        ApiResponseService::successResponse(
            'Article retrieved successfully',
            (new ArticleResource($article))->resolve()
        );
    }
}
