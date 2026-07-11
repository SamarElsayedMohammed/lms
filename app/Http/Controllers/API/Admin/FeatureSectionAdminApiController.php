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

        $query = FeatureSection::with(['images', 'manualCourses'])
            ->when($withTrashed, fn ($q) => $q->withTrashed());

        $sections = $query->orderBy('row_order')->get();

        return $this->jsonSuccess(__('Feature sections retrieved'), $sections);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::with(['images', 'manualCourses'])->withTrashed()->find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        return $this->jsonSuccess(__('Feature section retrieved'), $section);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $validator = Validator::make($request->all(), $this->rules(required: true));

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $manualCourseIds = $this->manualCourseIds($request);
        $manualCourseError = $this->validateManualCourseIds($manualCourseIds);
        if ($manualCourseError) {
            return $manualCourseError;
        }

        $data = $validator->validated();
        unset($data['manual_courses']);
        $data['limit'] = $request->input('limit', 10);
        $data['is_active'] = $request->boolean('is_active', true);

        $section = FeatureSection::create($data);
        $this->syncManualCourses($section, $manualCourseIds);

        return $this->jsonSuccess(
            __('Feature section created successfully'),
            $section->fresh(['images', 'manualCourses']),
            201,
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Feature section not found'), 404);
        }

        $validator = Validator::make($request->all(), $this->rules(required: false));

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $manualCourseIds = $this->manualCourseIds($request);
        $manualCourseError = $this->validateManualCourseIds($manualCourseIds);
        if ($manualCourseError) {
            return $manualCourseError;
        }

        $data = $validator->validated();
        unset($data['manual_courses']);

        $section->update($data);
        if ($request->has('manual_courses')) {
            $this->syncManualCourses($section, $manualCourseIds);
        }

        return $this->jsonSuccess(
            __('Feature section updated successfully'),
            $section->fresh(['images', 'manualCourses']),
        );
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
        return $this->jsonSuccess(__('Feature section restored successfully'), $section->fresh(['images', 'manualCourses']));
    }

    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'type' => "{$presence}|string|max:50",
            'title' => "{$presence}|string|max:255",
            'limit' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'layout' => 'nullable|in:carousel,grid',
            'grid_columns' => 'nullable|integer|min:1|max:6',
            'background' => 'nullable|in:white,section,transparent',
            'sorting' => 'nullable|string|max:50',
            'visibility_permissions' => 'nullable|array',
            'visibility_devices' => 'nullable|array',
            'responsive_limits' => 'nullable|array',
            'manual_courses' => 'nullable|array',
        ];
    }

    private function manualCourseIds(Request $request): array
    {
        return collect($request->input('manual_courses', []))
            ->map(static fn ($item) => is_array($item) ? ($item['id'] ?? $item['course_id'] ?? null) : $item)
            ->filter(static fn ($id) => is_numeric($id))
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function validateManualCourseIds(array $courseIds): ?JsonResponse
    {
        $validator = Validator::make(['manual_courses' => $courseIds], [
            'manual_courses.*' => 'exists:courses,id',
        ]);

        return $validator->fails() ? $this->jsonError($validator->errors()->first(), 422) : null;
    }

    private function syncManualCourses(FeatureSection $section, array $courseIds): void
    {
        $sync = [];
        foreach ($courseIds as $index => $courseId) {
            $sync[$courseId] = ['sort_order' => $index + 1];
        }

        $section->manualCourses()->sync($sync);
    }
}
