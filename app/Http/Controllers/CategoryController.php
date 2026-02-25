<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CategoryController extends Controller
{
    private readonly string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'category';
    }

    /**
     * Get the depth of a category in the hierarchy
     * Returns -1 if circular reference is detected
     */
    private function getCategoryDepth($category)
    {
        if (!$category) {
            return 0;
        }

        $depth = 1;
        $visitedIds = []; // Track visited category IDs to prevent infinite loops
        $maxDepth = 10; // Maximum depth limit for safety

        $currentCategory = $category;

        while ($currentCategory && $currentCategory->parent_category_id && $depth < $maxDepth) {
            // Check for circular reference
            if (in_array($currentCategory->id, $visitedIds)) {
                // Return a flag to indicate circular reference instead of throwing exception
                return -1; // -1 indicates circular reference
            }

            $visitedIds[] = $currentCategory->id;
            $currentCategory = Category::find($currentCategory->parent_category_id);

            if (!$currentCategory) {
                break; // Parent not found, exit loop
            }

            $depth++;
        }

        return $depth;
    }

    /**
     * Get all descendant category IDs (children, grandchildren, etc.)
     * Used to prevent setting a category as parent of its own descendant
     */
    private function getAllDescendantIds($categoryId, &$descendantIds = [])
    {
        $children = Category::where('parent_category_id', $categoryId)->get();

        foreach ($children as $child) {
            $descendantIds[] = $child->id;
            $this->getAllDescendantIds($child->id, $descendantIds);
        }

        return $descendantIds;
    }

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect([
            'categories-list',
            'categories-create',
            'categories-edit',
            'categories-delete',
        ]);
        return view('categories.index', ['type_menu' => 'categories']);
    }

    public function create(Request $request)
    {
        ResponseService::noPermissionThenRedirect('categories-create');
        $categories = Category::with('subcategories')->get();
        return view('categories.create', compact('categories'), ['type_menu' => 'categories']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('categories-create');

        // Image is required only if parent_category_id is not set (main category)
        $imageRule = $request->parent_category_id
            ? 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168'
            : 'required|mimes:jpg,jpeg,png,webp,svg|max:7168';

        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255|unique:categories,name',
                'image' => $imageRule,
                'parent_category_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string',
                'slug' => 'required|string|max:255',
                'status' => 'nullable|boolean',
            ],
            [
                'image.max' => 'Please upload an image file that is 7MB or less.',
                'image.mimes' => 'Please upload a valid image file (JPG, PNG, SVG, or WebP).',
                'image.required' => 'Please select a category image.',
            ],
        );

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Check for duplicate category name (case-insensitive)
            $existingCategory = Category::whereRaw('LOWER(name) = ?', [strtolower($request->name)])->first();
            if ($existingCategory) {
                ResponseService::validationError(
                    'A category with this name already exists. Please use a different name.',
                );
            }

            $parentId = $request->parent_category_id;
            $level = 0;

            if ($parentId) {
                $parent = Category::find($parentId);

                if (!$parent) {
                    ResponseService::validationError('Selected parent category does not exist');
                }

                $level = $this->getCategoryDepth($parent);

                // Check for circular reference (indicated by -1)
                if ($level == -1) {
                    ResponseService::validationError(
                        'Circular reference detected in category hierarchy. The selected parent category has a circular reference in its chain. Please select a different parent category or fix the existing category hierarchy.',
                    );
                }

                // Maximum 3 levels allowed: Level 1 (root), Level 2 (child), Level 3 (grandchild)
                // If parent is at Level 3 (depth = 3), new child would be Level 4 - NOT ALLOWED
                if ($level >= 3) {
                    ResponseService::validationError(
                        'You can create subcategories up to only 3 levels. The selected parent category is already at the maximum level (Level 3).',
                    );
                }
            }
            $data = $request->only(['name', 'slug', 'description', 'parent_category_id', 'status']);
            $data['slug'] = HelperService::generateUniqueSlug(Category::class, $request->slug);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), $this->uploadFolder);
            } else {
                // For subcategories, image can be null; for main categories, validation ensures image is provided
                if ($request->parent_category_id) {
                    $data['image'] = null;
                }

                // For main categories without image, validation will fail before reaching here
            }

            $category = Category::create($data);

            ResponseService::successResponse('Category Created Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            // طلب AJAX يجب أن يرجع JSON حتى تظهر رسالة الخطأ في الصفحة
            if ($request->wantsJson() || $request->ajax()) {
                ResponseService::errorResponse(__('Failed to create category') . ': ' . $th->getMessage());
            }
            ResponseService::errorRedirectResponse('Failed to create category: ' . $th->getMessage());
        }
    }

    public function show(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('categories-list');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = Category::withCount('subcategories')
            ->select('categories.*')
            ->when(!empty($showDeleted), static function ($query): void {
                $query->onlyTrashed();
            })
            ->when(empty($showDeleted), static function ($query) use ($id): void {
                // Only apply parent-child logic if NOT viewing trashed
                if ($id == '0') {
                    $query->whereNull('parent_category_id');
                } else {
                    $query->where('parent_category_id', $id);
                }
            })
            ->when(!empty($showDeleted) && $id !== '0', static function ($query) use ($id): void {
                // When showing trashed subcategories under a parent
                $query->where('parent_category_id', $id);
            })
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q->where('name', 'LIKE', "%$search%");
                });
            });

        $sql->orderBy($sort, $order);

        $result = $sql->get()->slice($offset, $limit);
        $total = $sql->count();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($result as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('categories-restore')) {
                    $operate .= BootstrapTableService::restoreButton(route('categories.restore', $row->id));
                }
                if (auth()->user()->can('categories-trash')) {
                    $operate .= BootstrapTableService::trashButton(route('categories.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('categories-edit')) {
                    $operate .= BootstrapTableService::editButton(route('categories.edit', $row->id));
                }
                if (auth()->user()->can('categories-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('categories.destroy', $row->id));
                }
                if ($row->subcategories_count > 0 && auth()->user()->can('categories-reorder')) {
                    $operate .= BootstrapTableService::button(
                        'fa fa-list-ol',
                        route('sub.category.order.change', $row->id),
                        ['btn-secondary'],
                        ['title' => 'Reorder Subcategories'],
                    );
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;

            // Ensure subcategories_count is always present
            if (!isset($tempRow['subcategories_count']) || $tempRow['subcategories_count'] === null) {
                $tempRow['subcategories_count'] = $row->subcategories_count ?? 0;
            }
            // Ensure it's an integer
            $tempRow['subcategories_count'] = (int) ($tempRow['subcategories_count'] ?? 0);

            // CRITICAL: Ensure status field is ALWAYS present and properly formatted as integer
            // Get status directly from model attribute (not from toArray which might miss it)
            $statusValue = $row->getAttribute('status');

            // Handle all possible status values
            if ($statusValue === null || $statusValue === false || $statusValue === 0 || $statusValue === '0') {
                $tempRow['status'] = 0;
            } elseif ($statusValue === true || $statusValue === 1 || $statusValue === '1') {
                $tempRow['status'] = 1;
            } else {
                // Convert to integer (handles strings, floats, etc.)
                $tempRow['status'] = (int) $statusValue;
            }

            // Double-check: if status is still not set, default to 0
            if (!isset($tempRow['status'])) {
                $tempRow['status'] = 0;
            }

            // Add export-ready status field (for CSV export) - ensure it's clean text only
            $tempRow['status_export'] =
                $tempRow['status'] == 1
                || $tempRow['status'] === 1
                || $tempRow['status'] === '1'
                || $tempRow['status'] === true
                    ? 'Active'
                    : 'Deactive';

            // Add subcategories_count as plain number for export (without formatter)
            $tempRow['subcategories_count_export'] = (int) ($tempRow['subcategories_count'] ?? 0);

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('categories-edit');
        $category_data = Category::findOrFail($id);
        $parent_category_data = Category::find($category_data->parent_category_id);
        $parent_category = $parent_category_data->name ?? '';
        $category_data->image_url = $category_data->image ?: asset('assets/img_placeholder.jpeg');

        // Get all descendant IDs to exclude (to prevent circular references)
        $descendantIds = $this->getAllDescendantIds($id);
        $excludeIds = array_merge([$id], $descendantIds);

        // Exclude current category and its descendants from parent category list
        $categories = Category::with('subcategories')->whereNotIn('id', $excludeIds)->get();

        return view(
            'categories.edit',
            compact('category_data', 'categories', 'parent_category_data', 'parent_category'),
            ['type_menu' => 'categories'],
        );
    }

    public function update(Request $request, $id)
    {
        try {
            ResponseService::noPermissionThenSendJson('categories-edit');
            $category = Category::findOrFail($id);
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255|unique:categories,name,' . $id,
                    'image' => 'nullable|mimes:jpg,jpeg,png,webp,svg|max:7168',
                    'parent_category_id' => 'nullable|integer',
                    'description' => 'nullable|string',
                    'slug' => 'required|string|max:255',
                ],
                [
                    'image.max' => 'Please upload an image file that is 7MB or less.',
                    'image.mimes' => 'Please upload a valid image file (JPG, PNG, SVG, or WebP).',
                ],
            );

            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput();
            }

            // Check for duplicate category name (case-insensitive, excluding current category)
            $existingCategory = Category::whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                ->where('id', '!=', $id)
                ->first();
            if ($existingCategory) {
                return back()->withErrors([
                    'name' => 'A category with this name already exists. Please use a different name.',
                ]);
            }

            // Prevent setting category as its own parent
            if ($request->parent_category_id == $category->id) {
                return back()->withErrors(['parent_category' => 'A category cannot be set as its own parent.']);
            }

            // Check if trying to set a descendant as parent (which would create a circular reference)
            if ($request->parent_category_id) {
                $parentId = $request->parent_category_id;
                $descendantIds = $this->getAllDescendantIds($category->id);

                if (in_array($parentId, $descendantIds)) {
                    return back()->withErrors([
                        'parent_category' => 'A category cannot be set as a parent of its own descendant. This would create a circular reference.',
                    ]);
                }

                // Check if the selected parent has a circular reference
                $parent = Category::find($parentId);
                if ($parent) {
                    $level = $this->getCategoryDepth($parent);
                    if ($level == -1) {
                        return back()->withErrors([
                            'parent_category' => 'The selected parent category has a circular reference in its hierarchy. Please select a different parent category or fix the existing category hierarchy.',
                        ]);
                    }

                    // Maximum 3 levels allowed: Level 1 (root), Level 2 (child), Level 3 (grandchild)
                    // If parent is at Level 3 (depth = 3), new child would be Level 4 - NOT ALLOWED
                    if ($level >= 3) {
                        return back()->withErrors([
                            'parent_category' => 'You can create subcategories up to only 3 levels. The selected parent category is already at the maximum level (Level 3).',
                        ]);
                    }
                }
            }

            $data = $request->only(['name', 'slug', 'description', 'parent_category_id']);
            $data['slug'] = HelperService::generateUniqueSlug(Category::class, $request->slug, $category->id);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace(
                    $request->file('image'),
                    $this->uploadFolder,
                    $category->getRawOriginal('image'),
                );
            } else {
                $data['image'] = $category->image;
            }

            $category->update($data);

            ResponseService::successRedirectResponse('Category Updated Successfully', route('categories.index'));
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse('Failed to update category');
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('categories-delete');
        try {
            DB::beginTransaction();
            $category = Category::findOrFail($id);

            // Check if category has subcategories
            if ($category->subcategories()->exists()) {
                DB::rollBack();
                ResponseService::errorResponse('Please Delete Subcategories Before Deleting This Category');
            }

            // Check if category has linked courses
            if ($category->courses()->exists()) {
                DB::rollBack();
                $courseCount = $category->courses()->count();
                ResponseService::errorResponse(
                    "Cannot delete category. This category is linked to {$courseCount} course(s). Please remove or reassign courses before deleting this category.",
                );
            }

            $category->delete();
            DB::commit();
            ResponseService::successResponse('Category deleted successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'CategoryController -> destroy');
            ResponseService::errorResponse('Failed to delete category: ' . $th->getMessage());
        }
    }

    public function restore($id)
    {
        try {
            ResponseService::noPermissionThenSendJson('categories-edit');
            DB::beginTransaction();
            $category = Category::onlyTrashed()->findOrFail($id);
            $category->restore();
            DB::commit();
            ResponseService::successResponse('Category Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Category Controller -> Restore Method');
            ResponseService::errorRedirectResponse('Failed to restore category');
        }
    }

    public function trash($id)
    {
        try {
            ResponseService::noPermissionThenRedirect('categories-delete');
            DB::beginTransaction();
            $category = Category::onlyTrashed()->findOrFail($id);
            if ($category->image) {
                FileService::delete($category->image);
            }
            $category->forceDelete();
            DB::commit();
            ResponseService::successResponse('Category Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Catgeory Controller -> Trash mehtos');
            ResponseService::errorRedirectResponse('Failed to delete category');
        }
    }

    public function getSubCategories($id)
    {
        ResponseService::noPermissionThenRedirect('categories-list');
        $subcategories = Category::where('parent_category_id', $id)
            ->with('subcategories')
            ->withCount('subcategories')
            ->orderBy('sequence')
            ->get()
            ->map(static function ($subcategory) {
                $operate = '';
                $operate .= BootstrapTableService::editButton(route('categories.edit', $subcategory->id));
                $operate .= BootstrapTableService::deleteButton(route('categories.destroy', $subcategory->id));
                if ($subcategory->subcategories_count > 0) {
                    $operate .= BootstrapTableService::button(
                        'fa fa-list-ol',
                        route('sub.category.order.change', $subcategory->id),
                        ['btn-secondary'],
                        ['title' => 'Reorder Subcategories'],
                    );
                }
                $subcategory->operate = $operate;

                // Get raw image path (before accessor conversion) to avoid double URL encoding
                $imagePath = $subcategory->getRawOriginal('image');
                $imageUrl = '';
                if ($imagePath) {
                    // Use FileService to get proper URL, or Storage::url if FileService not available
                    if (class_exists(\App\Services\FileService::class)) {
                        $imageUrl = \App\Services\FileService::getFileUrl($imagePath);
                    } else {
                        $imageUrl = Storage::url($imagePath);
                    }
                }

                return [
                    'id' => $subcategory->id,
                    'name' => $subcategory->name,
                    'image' => $imageUrl,
                    'subcategories_count' => $subcategory->subcategories_count,
                    'status' => $subcategory->status,
                    'operate' => $operate,
                ];
            });
        return response()->json($subcategories);
    }

    public function categoriesReOrder(Request $request)
    {
        ResponseService::noPermissionThenRedirect('categories-list');
        $categories = Category::whereNull('parent_category_id')->orderBy('sequence')->get();
        return view('categories.category-order', compact('categories'), ['type_menu' => 'categories']);
    }

    public function subcategoriesOrder(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('categories-list');
        $categories = Category::where('parent_category_id', $id)->orderBy('sequence')->get();
        return view('categories.sub-category-order', compact('categories'), ['type_menu' => 'categories']);
    }

    public function customFields($id)
    {
        // ResponseService::noPermissionThenSendJson('custom-field-list');
        $category = Category::find($id);
        $p_id = $category->parent_category_id;
        $cat_id = $category->id;
        $category_name = $category->name;
        return view('categories.custom-fields', compact('cat_id', 'category_name', 'p_id'), [
            'type_menu' => 'custom-fields',
        ]);
    }

    public function updateOrder(Request $request)
    {
        ResponseService::noPermissionThenSendJson('categories-edit');

        $request->validate([
            'order' => 'required|json',
        ]);

        try {
            $orderJson = $request->input('order');
            Log::info('Category Order Update Request:', ['order_json' => $orderJson]);

            $order = json_decode((string) $orderJson, true);

            // Validate that order is an array
            if (!is_array($order) || empty($order)) {
                Log::error('Invalid order data received', ['order' => $order]);
                return ResponseService::errorResponse('Invalid order data. Order must be a non-empty array.');
            }

            Log::info('Processing order update', [
                'order_count' => count($order),
                'order' => $order,
                'order_with_sequences' => array_map(
                    static fn($id, $index) => ['category_id' => $id, 'sequence' => $index + 1],
                    $order,
                    array_keys($order),
                ),
            ]);

            // Use transaction to ensure all updates succeed or fail together
            DB::beginTransaction();

            try {
                $updatedCount = 0;
                $failedCount = 0;

                // Update sequence for each category using DB::table for direct update
                foreach ($order as $index => $id) {
                    // Convert ID to integer if it's a string
                    $categoryId = is_numeric($id) ? (int) $id : $id;
                    $sequence = $index + 1; // Sequence = position in array (1-based)

                    Log::debug('Updating category sequence', [
                        'category_id' => $categoryId,
                        'array_index' => $index,
                        'calculated_sequence' => $sequence,
                    ]);

                    // First, check if category exists and get current sequence
                    $category = DB::table('categories')
                        ->where('id', $categoryId)
                        ->whereNull('deleted_at')
                        ->first(['id', 'sequence']);

                    if (!$category) {
                        $failedCount++;
                        Log::warning('Category not found or deleted', [
                            'category_id' => $categoryId,
                            'sequence' => $sequence,
                        ]);
                        continue;
                    }

                    // Always update sequence to ensure correct order
                    // Use DB::table with updateOrInsert pattern to ensure it works
                    try {
                        // Direct update using DB facade
                        $updated = DB::table('categories')
                            ->where('id', $categoryId)
                            ->whereNull('deleted_at')
                            ->update(['sequence' => $sequence]);

                        // If update returned 0, check if it's because value is same or category doesn't exist
                        if ($updated == 0) {
                            // Verify the category exists and check current sequence
                            $currentCategory = DB::table('categories')
                                ->where('id', $categoryId)
                                ->whereNull('deleted_at')
                                ->first(['sequence']);

                            if ($currentCategory) {
                                // Category exists, check if sequence is already correct
                                if ($currentCategory->sequence == $sequence) {
                                    // Already correct, count as success
                                    $updatedCount++;
                                    Log::info('Category sequence already correct', [
                                        'category_id' => $categoryId,
                                        'sequence' => $sequence,
                                    ]);
                                } else {
                                    // Sequence is different but update returned 0 - try raw SQL
                                    $updated = DB::update('UPDATE categories SET sequence = ? WHERE id = ? AND deleted_at IS NULL', [
                                        $sequence,
                                        $categoryId,
                                    ]);

                                    if ($updated > 0) {
                                        $updatedCount++;
                                        Log::info('Category sequence updated via raw SQL', [
                                            'category_id' => $categoryId,
                                            'old_sequence' => $currentCategory->sequence,
                                            'new_sequence' => $sequence,
                                        ]);
                                    } else {
                                        $failedCount++;
                                        Log::warning('Category sequence update failed', [
                                            'category_id' => $categoryId,
                                            'sequence' => $sequence,
                                            'current_sequence' => $currentCategory->sequence,
                                            'reason' => 'Update returned 0 rows - possible database issue',
                                        ]);
                                    }
                                }
                            } else {
                                $failedCount++;
                                Log::warning('Category not found or deleted', [
                                    'category_id' => $categoryId,
                                    'sequence' => $sequence,
                                ]);
                            }
                        } else {
                            // Update successful
                            $updatedCount++;
                            Log::info('Category sequence updated', [
                                'category_id' => $categoryId,
                                'old_sequence' => $category->sequence ?? 'NULL',
                                'new_sequence' => $sequence,
                                'rows_affected' => $updated,
                            ]);
                        }
                    } catch (\Exception $e) {
                        $failedCount++;
                        Log::error('Exception updating category sequence', [
                            'category_id' => $categoryId,
                            'sequence' => $sequence,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                DB::commit();
                Log::info('Category order update transaction committed successfully', [
                    'total_categories' => count($order),
                    'updated_count' => $updatedCount,
                    'failed_count' => $failedCount,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Category order update transaction failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            Log::info('Category order update completed successfully');
            ResponseService::successResponse('Order Updated Successfully');
        } catch (Throwable $th) {
            Log::error('Category order update failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            ResponseService::logErrorRedirect($th, 'CategoryController -> updateOrder');
            ResponseService::errorResponse('Failed to update order: ' . $th->getMessage());
        }
    }
}
