<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\FeatureSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeatureSectionAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $withTrashed = $request->boolean('with_trashed');

        $query = FeatureSection::with('images')
            ->when($withTrashed, fn ($q) => $q->withTrashed());

        $sections = $query->orderBy('row_order')->get();

        return $this->jsonSuccess(__('Feature sections retrieved'), $sections);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::with('images')->withTrashed()->find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        return $this->jsonSuccess(__('Feature section retrieved'), $section);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $section = FeatureSection::create([
            'type' => $request->type,
            'title' => $request->title,
            'limit' => $request->input('limit', 10),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->jsonSuccess(__('Feature section created successfully'), $section, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'limit' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $section->update($validator->validated());
        return $this->jsonSuccess(__('Feature section updated successfully'), $section->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        $section->delete();
        return $this->jsonSuccess(__('Feature section deleted successfully'));
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::onlyTrashed()->find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        $section->restore();
        return $this->jsonSuccess(__('Feature section restored successfully'), $section->fresh());
    }
}
