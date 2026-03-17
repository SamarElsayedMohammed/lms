<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TagAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = Tag::withCount('courses')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where('tag', 'like', "%{$search}%"));

        $tags = $query->orderBy('tag')->paginate($perPage);

        return $this->jsonSuccess(__('Tags retrieved'), $tags);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $tag = Tag::with('courses')->withTrashed()->find($id);
        if (!$tag) {
            return $this->jsonError(__('Tag not found'), 404);
        }

        return $this->jsonSuccess(__('Tag retrieved'), $tag);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $tag = Tag::create([
            'tag' => $request->tag,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->jsonSuccess(__('Tag created successfully'), $tag, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $tag = Tag::find($id);
        if (!$tag) {
            return $this->jsonError(__('Tag not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $tag->update($validator->validated());
        return $this->jsonSuccess(__('Tag updated successfully'), $tag->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $tag = Tag::find($id);
        if (!$tag) {
            return $this->jsonError(__('Tag not found'), 404);
        }

        $tag->delete();
        return $this->jsonSuccess(__('Tag deleted successfully'));
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('course-tags-list');

        $tag = Tag::onlyTrashed()->find($id);
        if (!$tag) {
            return $this->jsonError(__('Tag not found'), 404);
        }

        $tag->restore();
        return $this->jsonSuccess(__('Tag restored successfully'), $tag->fresh());
    }
}
