<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PageController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('pages-list');
        return view('pages.index', ['type_menu' => 'pages']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('pages-create');
        $validator = Validator::make($request->all(), [
            'language_id' => 'required|exists:languages,id',
            'title' => 'required|string|max:500',
            'page_type' => 'required|string|max:50',
            'slug' => 'required|string|max:500',
            'page_content' => 'nullable|string',
            'page_icon' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'og_image' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'schema_markup' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'is_custom' => 'nullable|in:0,1',
            'is_termspolicy' => 'nullable|in:0,1',
            'is_privacypolicy' => 'nullable|in:0,1',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            // Check if page type is custom
            $isCustomPage = $data['page_type'] == 'custom' || $request->is_custom == 1;

            // For non-custom pages, check if same page type already exists for this language
            if (!$isCustomPage) {
                $existingPage = Page::where('language_id', $data['language_id'])
                    ->where('page_type', $data['page_type'])
                    ->first();

                if ($existingPage) {
                    DB::rollBack();
                    $pageTypeName = ucfirst(str_replace('-', ' ', $data['page_type']));
                    return ResponseService::validationError(
                        "A {$pageTypeName} page already exists for this language. Only one {$pageTypeName} page is allowed per language.",
                    );
                }
            }

            // Generate unique slug if not provided or if it already exists for this language
            if (
                empty($data['slug'])
                || Page::where('slug', $data['slug'])->where('language_id', $data['language_id'])->exists()
            ) {
                $baseSlug = \Illuminate\Support\Str::slug($data['title']);
                $slug = $baseSlug;
                $counter = 1;
                while (Page::where('slug', $slug)->where('language_id', $data['language_id'])->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                $data['slug'] = $slug;
            }

            $data['is_custom'] = $isCustomPage ? 1 : 0;
            $data['is_termspolicy'] = $request->is_termspolicy ?? 0;
            $data['is_privacypolicy'] = $request->is_privacypolicy ?? 0;
            $data['status'] = $request->status ?? 1;

            // Handle page_icon upload
            if ($request->hasFile('page_icon')) {
                $icon = $request->file('page_icon');
                $iconName =
                    'page_icon_' . Str::slug($data['title']) . '_' . time() . '.' . $icon->getClientOriginalExtension();
                $iconPath = $icon->storeAs('pages/icons', $iconName, 'public');
                $data['page_icon'] = $iconPath;
            }

            // Handle og_image upload
            if ($request->hasFile('og_image')) {
                $ogImage = $request->file('og_image');
                $ogImageName =
                    'og_image_'
                    . Str::slug($data['title'])
                    . '_'
                    . time()
                    . '.'
                    . $ogImage->getClientOriginalExtension();
                $ogImagePath = $ogImage->storeAs('pages/og-images', $ogImageName, 'public');
                $data['og_image'] = $ogImagePath;
            }

            // If this is a Terms & Conditions page, turn off other Terms pages for the same language
            if ($data['is_termspolicy'] == 1) {
                Page::where('language_id', $data['language_id'])
                    ->where('is_termspolicy', 1)
                    ->update(['is_termspolicy' => 0]);
            }

            Page::create($data);

            DB::commit();
            ResponseService::successResponse('Page Created Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'PageController -> Store Method');
            ResponseService::errorResponse();
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('pages-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = Page::with('language')->when(!empty($search), static function ($query) use ($search): void {
            $query->where(static function ($q) use ($search): void {
                $q
                    ->where('title', 'LIKE', "%$search%")
                    ->orWhere('page_type', 'LIKE', "%$search%")
                    ->orWhere('slug', 'LIKE', "%$search%")
                    ->orWhere('page_content', 'LIKE', "%$search%");
            });
        })->when(!empty($showDeleted), static function ($query): void {
            $query->onlyTrashed();
        });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('pages-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('pages.restore', $row->id));
                }
                // Only show trash button for custom pages
                if (
                    auth()->user()->can('pages-delete')
                    && ($row->is_custom == 1 || strtolower((string) $row->page_type) == 'custom')
                ) {
                    $operate .= BootstrapTableService::trashButton(route('pages.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('pages-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('pages.update', $row->id),
                        true,
                        '#pageEditModal',
                        $row->id,
                    );
                }

                // Add View button for non-custom pages (About, Terms, Privacy, Cookies)
                if ($row->is_custom != 1 && strtolower((string) $row->page_type) != 'custom') {
                    $operate .= BootstrapTableService::viewButton(route('pages.view', $row->slug));
                }

                // Only show delete button for custom pages
                if (
                    auth()->user()->can('pages-delete')
                    && ($row->is_custom == 1 || strtolower((string) $row->page_type) == 'custom')
                ) {
                    $operate .= BootstrapTableService::deleteButton(route('pages.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['language_name'] = $row->language->name ?? '-';
            $tempRow['status_text'] = $row->status == 1 ? 'Active' : 'Inactive';
            // Add export column for status
            $tempRow['status_export'] =
                $tempRow['status'] == 1
                || $tempRow['status'] === 1
                || $tempRow['status'] === '1'
                || $tempRow['status'] === true
                    ? 'Active'
                    : 'Deactive';
            $tempRow['operate'] = $operate;
            // Include deleted_at to check if item is trashed in the frontend
            $tempRow['deleted_at'] = $row->deleted_at;

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('pages-edit');
        $validator = Validator::make($request->all(), [
            'language_id' => 'required|exists:languages,id',
            'title' => 'required|string|max:500',
            'page_type' => 'required|string|max:50',
            'slug' => 'required|string|max:500',
            'page_content' => 'nullable|string',
            'page_icon' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'og_image' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'schema_markup' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'is_custom' => 'nullable|in:0,1',
            'is_termspolicy' => 'nullable|in:0,1',
            'is_privacypolicy' => 'nullable|in:0,1',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $page = Page::findOrFail($id);
            $data = $validator->validated();

            // Check if page type is custom
            $isCustomPage = $data['page_type'] == 'custom' || $request->is_custom == 1;

            // For non-custom pages, check if same page type already exists for this language (excluding current page)
            if (!$isCustomPage) {
                $existingPage = Page::where('language_id', $data['language_id'])
                    ->where('page_type', $data['page_type'])
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingPage) {
                    DB::rollBack();
                    $pageTypeName = ucfirst(str_replace('-', ' ', $data['page_type']));
                    return ResponseService::validationError(
                        "A {$pageTypeName} page already exists for this language. Only one {$pageTypeName} page is allowed per language.",
                    );
                }
            }

            // Check if slug needs to be unique for this language
            $existingPageBySlug = Page::where('slug', $data['slug'])
                ->where('language_id', $data['language_id'])
                ->where('id', '!=', $id)
                ->first();

            if ($existingPageBySlug) {
                $baseSlug = \Illuminate\Support\Str::slug($data['title']);
                $slug = $baseSlug;
                $counter = 1;
                while (Page::where('slug', $slug)->where('language_id', $data['language_id'])->where(
                    'id',
                    '!=',
                    $id,
                )->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                $data['slug'] = $slug;
            }

            $data['is_custom'] = $isCustomPage ? 1 : $request->is_custom ?? $page->is_custom;
            $data['is_termspolicy'] = $request->is_termspolicy ?? $page->is_termspolicy;
            $data['is_privacypolicy'] = $request->is_privacypolicy ?? $page->is_privacypolicy;
            $data['status'] = $request->status ?? $page->status;

            // Handle page_icon upload
            if ($request->hasFile('page_icon')) {
                // Delete old icon if exists
                if ($page->page_icon && Storage::disk('public')->exists($page->page_icon)) {
                    Storage::disk('public')->delete($page->page_icon);
                }

                $icon = $request->file('page_icon');
                $iconName =
                    'page_icon_' . Str::slug($data['title']) . '_' . time() . '.' . $icon->getClientOriginalExtension();
                $iconPath = $icon->storeAs('pages/icons', $iconName, 'public');
                $data['page_icon'] = $iconPath;
            } else {
                // Keep existing icon if no new file uploaded
                unset($data['page_icon']);
            }

            // Handle og_image upload
            if ($request->hasFile('og_image')) {
                // Delete old og_image if exists
                if ($page->og_image && Storage::disk('public')->exists($page->og_image)) {
                    Storage::disk('public')->delete($page->og_image);
                }

                $ogImage = $request->file('og_image');
                $ogImageName =
                    'og_image_'
                    . Str::slug($data['title'])
                    . '_'
                    . time()
                    . '.'
                    . $ogImage->getClientOriginalExtension();
                $ogImagePath = $ogImage->storeAs('pages/og-images', $ogImageName, 'public');
                $data['og_image'] = $ogImagePath;
            } else {
                // Keep existing og_image if no new file uploaded
                unset($data['og_image']);
            }

            // If this is being set as Terms & Conditions page, turn off other Terms pages for the same language
            if ($data['is_termspolicy'] == 1) {
                Page::where('language_id', $data['language_id'])
                    ->where('id', '!=', $id)
                    ->where('is_termspolicy', 1)
                    ->update(['is_termspolicy' => 0]);
            }

            $page->update($data);
            DB::commit();
            ResponseService::successResponse('Page Updated Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'PageController -> Update Method');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('pages-delete');
        try {
            DB::beginTransaction();
            $page = Page::findOrFail($id);

            // Only allow deletion of custom pages
            if ($page->is_custom != 1 && strtolower((string) $page->page_type) != 'custom') {
                DB::rollBack();
                return ResponseService::errorResponse('Only custom pages can be deleted.');
            }

            $page->delete();
            DB::commit();
            ResponseService::successResponse('Page Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'PageController -> Destroy Method');
            ResponseService::errorResponse();
        }
    }

    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('pages-delete');
        try {
            DB::beginTransaction();
            $page = Page::onlyTrashed()->findOrFail($id);
            $page->restore();
            DB::commit();
            ResponseService::successResponse('Page Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'PageController -> Restore Method');
            ResponseService::errorResponse();
        }
    }

    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('pages-delete');
        try {
            DB::beginTransaction();
            $page = Page::onlyTrashed()->findOrFail($id);

            // Only allow permanent deletion of custom pages
            if ($page->is_custom != 1 && strtolower((string) $page->page_type) != 'custom') {
                DB::rollBack();
                return ResponseService::errorResponse('Only custom pages can be permanently deleted.');
            }

            $page->forceDelete();
            DB::commit();
            ResponseService::successResponse('Page Permanently Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'PageController -> Trash Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Public page view - displays page content
     * @param string $slug
     * @return \Illuminate\Contracts\View\View
     */
    public function viewPage($slug)
    {
        try {
            // Allow authenticated users (admins) to view pages regardless of status
            // Public users can only view active pages
            $query = Page::where('slug', $slug);

            // If user is not authenticated, only show active pages
            if (!Auth::check()) {
                $query->where('status', 1);
            }

            $page = $query->firstOrFail();

            return view('pages.view', compact('page'));
        } catch (Exception) {
            abort(404);
        }
    }
}
