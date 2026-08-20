<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BlogAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = trim((string) $request->input('search', $request->input('q', '')));
        $perPage = min((int) $request->input('per_page', $request->input('limit', 100)), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = Article::query()
            ->when($withTrashed, fn ($builder) => $builder->withTrashed())
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('author', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $articles = $query->paginate($perPage);
        $payload = $articles->toArray();
        $payload['data'] = ArticleResource::collection($articles->getCollection())->resolve();

        return $this->jsonSuccess(__('Articles retrieved'), $payload);
    }

    public function show(string $slug): JsonResponse
    {
        $this->ensureAdmin();

        $article = $this->findArticle($slug);
        if (!$article) {
            return $this->jsonError(__('Article not found'), 404);
        }

        return $this->jsonSuccess(__('Article retrieved'), (new ArticleResource($article))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($this->normalizePayload($request), $this->rules());
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $this->validatedArticleData($request, $validator->validated());
        $article = Article::create($data);

        return $this->jsonSuccess(__('Article created successfully'), (new ArticleResource($article->fresh()))->resolve(), 201);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $this->ensureAdmin();

        $article = $this->findArticle($slug);
        if (!$article) {
            return $this->jsonError(__('Article not found'), 404);
        }

        $validator = Validator::make($this->normalizePayload($request), $this->rules());
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $this->validatedArticleData($request, $validator->validated(), $article);
        $article->update($data);

        return $this->jsonSuccess(__('Article updated successfully'), (new ArticleResource($article->fresh()))->resolve());
    }

    public function destroy(string $slug): JsonResponse
    {
        $this->ensureAdmin();

        $article = $this->findArticle($slug);
        if (!$article) {
            return $this->jsonError(__('Article not found'), 404);
        }

        $this->deleteStoredCover($article->cover_image);
        $article->forceDelete();

        return $this->jsonSuccess(__('Article deleted successfully'));
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $file = $request->file('image') ?: $request->file('file') ?: $request->file('cover_image');
        if (!$file) {
            return $this->jsonError(__('Image file is required'), 422);
        }

        $validator = Validator::make(
            ['image' => $file],
            ['image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:4096']
        );
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $path = $file->store('articles/covers', 'public');

        return $this->jsonSuccess(__('Image uploaded successfully'), [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => 'required|string|min:2|max:255',
            'slug' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'cover_image' => 'nullable|string|max:2048',
            'tags' => 'nullable',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(Request $request): array
    {
        $payload = $request->all();
        if (isset($payload['coverImage']) && !isset($payload['cover_image'])) {
            $payload['cover_image'] = $payload['coverImage'];
        }
        if (isset($payload['datePublished']) && !isset($payload['published_at'])) {
            $payload['published_at'] = $payload['datePublished'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function validatedArticleData(Request $request, array $validated, ?Article $existing = null): array
    {
        $title = trim((string) ($validated['title'] ?? ''));
        $slug = Article::makeUniqueSlug(
            $title,
            isset($validated['slug']) ? (string) $validated['slug'] : null,
            $existing?->id
        );

        $coverImage = trim((string) ($validated['cover_image'] ?? ''));
        if (str_starts_with($coverImage, 'blob:')) {
            $coverImage = $existing?->cover_image ?? '';
        }

        $publishedAt = $validated['published_at'] ?? $existing?->published_at ?? now();

        return [
            'slug' => $slug,
            'title' => $title,
            'description' => trim((string) ($validated['description'] ?? '')),
            'content' => (string) ($validated['content'] ?? ''),
            'author' => trim((string) ($validated['author'] ?? '')) ?: 'فريق Skillso',
            'cover_image' => $coverImage !== '' ? $coverImage : null,
            'tags' => $this->normalizeTags($validated['tags'] ?? $request->input('tags')),
            'is_published' => $request->has('is_published')
                ? $request->boolean('is_published')
                : ($existing?->is_published ?? true),
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param mixed $tags
     * @return array<int, string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (!is_array($tags)) {
            return ['Skillso'];
        }

        $normalized = array_values(array_filter(array_map(static function ($tag) {
            return trim((string) $tag);
        }, $tags)));

        return $normalized !== [] ? $normalized : ['Skillso'];
    }

    private function findArticle(string $slug): ?Article
    {
        return Article::query()->where('slug', $slug)->first();
    }

    private function deleteStoredCover(?string $path): void
    {
        $value = trim((string) $path);
        if ($value === '' || str_contains($value, '://') || str_starts_with($value, '/')) {
            return;
        }

        if (Storage::disk('public')->exists($value)) {
            Storage::disk('public')->delete($value);
        }
    }
}
