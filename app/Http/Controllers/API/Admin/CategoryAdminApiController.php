<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Services\FileService;
use App\Services\HelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CategoryAdminApiController extends AdminCrudApiController
{
    private const UPLOAD_FOLDER = 'category';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/admin/categories - List categories with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-list');

        $parentId = $request->input('parent_id');
        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = Category::withCount('subcategories')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when(!$withTrashed, fn ($q) => $q->whereNull('deleted_at'))
            ->when($parentId === null || $parentId === '0' || $parentId === '', fn ($q) => $q->whereNull('parent_category_id'))
            ->when($parentId !== null && $parentId !== '0' && $parentId !== '', fn ($q) => $q->where('parent_category_id', $parentId))
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"));

        $categories = $query->orderBy('sequence')->orderBy('id')->paginate($perPage);

        return $this->jsonSuccess(__('Categories retrieved'), $categories);
    }

    /**
     * GET /api/admin/categories/{id} - Show single category
     */
    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-list');

        $category = Category::with(['subcategories', 'parent_category'])->withCount('courses')->find($id);
        if (!$category) {
            return $this->jsonError(__('Category not found'), 404);
        }

        return $this->jsonSuccess(__('Category retrieved'), $category);
    }

    /**
     * POST /api/admin/categories - Create category
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-create');

        $imageRule = $request->parent_category_id
            ? 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168'
            : 'required|mimes:jpg,jpeg,png,webp,svg|max:7168';

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => $imageRule,
            'parent_category_id' => 'nullable|integer|exists:categories,id',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $existing = Category::whereRaw('LOWER(name) = ?', [strtolower($request->name)])->first();
        if ($existing) {
            return $this->jsonError(__('A category with this name already exists'), 422);
        }

        try {
            DB::beginTransaction();
            $data = $request->only(['name', 'slug', 'description', 'parent_category_id', 'status']);
            $data['slug'] = HelperService::generateUniqueSlug(Category::class, $request->slug ?: $request->name);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), self::UPLOAD_FOLDER);
            } elseif ($request->parent_category_id) {
                $data['image'] = null;
            }

            $category = Category::create($data);
            DB::commit();

            return $this->jsonSuccess(__('Category created successfully'), $category->fresh(), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create category') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/categories/{id} - Update category
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-edit');

        $category = Category::find($id);
        if (!$category) {
            return $this->jsonError(__('Category not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168',
            'parent_category_id' => 'nullable|integer',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $existing = Category::whereRaw('LOWER(name) = ?', [strtolower($request->name)])
            ->where('id', '!=', $id)->first();
        if ($existing) {
            return $this->jsonError(__('A category with this name already exists'), 422);
        }

        if ((int) $request->parent_category_id === $id) {
            return $this->jsonError(__('A category cannot be its own parent'), 422);
        }

        try {
            DB::beginTransaction();
            $data = $request->only(['name', 'slug', 'description', 'parent_category_id', 'status']);
            $data['slug'] = HelperService::generateUniqueSlug(Category::class, $request->slug ?: $request->name, $id);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace(
                    $request->file('image'),
                    self::UPLOAD_FOLDER,
                    $category->getRawOriginal('image'),
                );
            }

            $category->update($data);
            DB::commit();

            return $this->jsonSuccess(__('Category updated successfully'), $category->fresh());
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update category') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/categories/{id} - Soft delete category
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-delete');

        $category = Category::find($id);
        if (!$category) {
            return $this->jsonError(__('Category not found'), 404);
        }

        // Automatically find a reassignment category if not provided
        $reassignCategoryId = $request->filled('reassign_category_id') 
            ? (int) $request->reassign_category_id 
            : null;

        if (!$reassignCategoryId) {
            // Get the first active category that is not this one and not a subcategory of this one
            $fallbackCategory = Category::where('id', '!=', $id)
                ->where('parent_category_id', '!=', $id)
                ->whereNull('deleted_at')
                ->first();
            if ($fallbackCategory) {
                $reassignCategoryId = $fallbackCategory->id;
            }
        }

        // Handle subcategories
        if ($category->subcategories()->exists()) {
            foreach ($category->subcategories as $subcategory) {
                if ($subcategory->courses()->exists() && $reassignCategoryId) {
                    Course::where('category_id', $subcategory->id)
                        ->update(['category_id' => $reassignCategoryId]);
                }
                $subcategory->delete();
            }
        }

        // Handle direct courses
        if ($category->courses()->exists() && $reassignCategoryId) {
            Course::where('category_id', $id)
                ->update(['category_id' => $reassignCategoryId]);
        }

        try {
            $category->delete();
            return $this->jsonSuccess(__('Category deleted successfully'));
        } catch (\Throwable $e) {
            return $this->jsonError(__('Failed to delete category') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/categories/{id}/restore - Restore soft-deleted category
     */
    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-edit');

        $category = Category::onlyTrashed()->find($id);
        if (!$category) {
            return $this->jsonError(__('Category not found'), 404);
        }

        $category->restore();
        return $this->jsonSuccess(__('Category restored successfully'), $category->fresh());
    }

    /**
     * POST /api/admin/categories/reorder - Update category order
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('categories-edit');

        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*' => 'integer|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            foreach ($request->order as $index => $categoryId) {
                Category::where('id', $categoryId)->update(['sequence' => $index + 1]);
            }
            DB::commit();
            return $this->jsonSuccess(__('Order updated successfully'));
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update order') . ': ' . $e->getMessage(), 500);
        }
    }
}
