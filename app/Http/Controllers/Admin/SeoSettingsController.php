<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\SeoSetting;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\ResponseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SeoSettingsController extends Controller
{
    private readonly string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'seo-settings';
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list']);
        return view('settings.seo-settings.index', [
            'type_menu' => 'settings',
            'pageTypes' => SeoSetting::getPageTypes(),
        ]);
    }

    /**
     * Show data for Bootstrap Table (server-side pagination)
     */
    public function show(Request $request, $id = 0)
    {
        ResponseService::noPermissionThenSendJson('settings-system-list');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = SeoSetting::with('language')->when(!empty($showDeleted), static function ($query): void {
            $query->onlyTrashed();
        })->when(!empty($search), static function ($query) use ($search): void {
            $query->where(static function ($q) use ($search): void {
                $q
                    ->where('meta_title', 'LIKE', "%{$search}%")
                    ->orWhere('meta_description', 'LIKE', "%{$search}%")
                    ->orWhere('meta_keywords', 'LIKE', "%{$search}%")
                    ->orWhere('page_type', 'LIKE', "%{$search}%")
                    ->orWhereHas('language', static function ($langQuery) use ($search): void {
                        $langQuery->where('name', 'LIKE', "%{$search}%");
                    });
            });
        });

        $sql->orderBy($sort, $order);

        $result = $sql->get()->slice($offset, $limit);
        $total = $sql->count();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        $pageTypes = SeoSetting::getPageTypes();

        foreach ($result as $row) {
            if ($showDeleted) {
                $operate = BootstrapTableService::restoreButton(route('admin.seo-settings.restore', $row->id));
                $operate .= BootstrapTableService::trashButton(route('admin.seo-settings.trash', $row->id));
            } else {
                $operate = BootstrapTableService::editButton(route('admin.seo-settings.edit', $row->id));
                $operate .= BootstrapTableService::deleteButton(route('admin.seo-settings.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['language'] = $row->language->name ?? '-';
            $tempRow['page_type_display'] = $pageTypes[$row->page_type] ?? $row->page_type;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        ResponseService::noPermissionThenRedirect('settings-system-list');
        $languages = Language::where('status', 1)->orderBy('name')->get();
        $pageTypes = SeoSetting::getPageTypes();

        return view('settings.seo-settings.create', [
            'type_menu' => 'settings',
            'languages' => $languages,
            'pageTypes' => $pageTypes,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-system-list');

        $request->validate([
            'language_id' => 'required|exists:languages,id',
            'page_type' => 'required|in:home,courses,instructor,help_and_support,all_categories,search_page,contact_us',
            'meta_title' => 'required|string|max:255',
            'meta_description' => 'required|string|max:500',
            'meta_keywords' => 'required|string',
            'schema_markup' => 'required|string',
            'og_image' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        ], [
            'language_id.required' => 'Language is required',
            'language_id.exists' => 'Selected language does not exist',
            'page_type.required' => 'Page type is required',
            'page_type.in' => 'Invalid page type selected',
            'meta_title.required' => 'Meta title is required',
            'meta_description.required' => 'Meta description is required',
            'meta_keywords.required' => 'Meta keywords is required',
            'schema_markup.required' => 'Schema markup is required',
            'og_image.required' => 'OG image is required',
            'og_image.image' => 'OG image must be an image file',
            'og_image.mimes' => 'OG image must be jpeg, png, jpg, gif, webp, or svg',
            'og_image.max' => 'OG image must not exceed 2MB',
        ]);

        try {
            // Check for duplicate (same language + same page type)
            $existing = SeoSetting::where('language_id', $request->language_id)
                ->where('page_type', $request->page_type)
                ->first();

            if ($existing) {
                ResponseService::validationError(
                    'SEO settings for this language and page type already exist. Please edit the existing entry.',
                );
            }

            DB::beginTransaction();

            // Handle OG image upload
            $ogImagePath = null;
            if ($request->hasFile('og_image')) {
                $ogImagePath = FileService::compressAndUpload($request->file('og_image'), $this->uploadFolder);
            }

            $seoSetting = SeoSetting::create([
                'language_id' => $request->language_id,
                'page_type' => $request->page_type,
                'meta_title' => $request->meta_title,
                'meta_description' => $request->meta_description,
                'meta_keywords' => $request->meta_keywords,
                'schema_markup' => $request->schema_markup,
                'og_image' => $ogImagePath,
            ]);

            DB::commit();

            ResponseService::successResponse('SEO settings created successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'SeoSettingsController -> store');
            ResponseService::errorResponse('Failed to create SEO settings: ' . $th->getMessage());
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('settings-system-list');
        $seoSetting = SeoSetting::findOrFail($id);
        $languages = Language::where('status', 1)->orderBy('name')->get();
        $pageTypes = SeoSetting::getPageTypes();

        return view('settings.seo-settings.edit', [
            'type_menu' => 'settings',
            'seoSetting' => $seoSetting,
            'languages' => $languages,
            'pageTypes' => $pageTypes,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            ResponseService::noPermissionThenSendJson('settings-system-list');
            $seoSetting = SeoSetting::findOrFail($id);

            $request->validate([
                'language_id' => 'required|exists:languages,id',
                'page_type' => 'required|in:home,courses,instructor,help_and_support,all_categories,search_page,contact_us',
                'meta_title' => 'required|string|max:255',
                'meta_description' => 'required|string|max:500',
                'meta_keywords' => 'required|string',
                'schema_markup' => 'required|string',
                'og_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            ], [
                'language_id.required' => 'Language is required',
                'language_id.exists' => 'Selected language does not exist',
                'page_type.required' => 'Page type is required',
                'page_type.in' => 'Invalid page type selected',
                'meta_title.required' => 'Meta title is required',
                'meta_description.required' => 'Meta description is required',
                'meta_keywords.required' => 'Meta keywords is required',
                'schema_markup.required' => 'Schema markup is required',
                'og_image.image' => 'OG image must be an image file',
                'og_image.mimes' => 'OG image must be jpeg, png, jpg, gif, webp, or svg',
                'og_image.max' => 'OG image must not exceed 2MB',
            ]);

            // Only check for duplicate if language_id or page_type is changing
            if ($seoSetting->language_id != $request->language_id || $seoSetting->page_type != $request->page_type) {
                // Check for duplicate (same language + same page type) excluding current record
                $existing = SeoSetting::where('language_id', $request->language_id)
                    ->where('page_type', $request->page_type)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existing) {
                    ResponseService::validationError(
                        'SEO settings for this language and page type already exist. Please edit the existing entry.',
                    );
                }
            }

            DB::beginTransaction();

            // Handle OG image upload
            if ($request->hasFile('og_image')) {
                $ogImagePath = FileService::compressAndReplace(
                    $request->file('og_image'),
                    $this->uploadFolder,
                    $seoSetting->getRawOriginal('og_image'),
                );
            } else {
                $ogImagePath = $seoSetting->og_image;
            }

            $seoSetting->update([
                'language_id' => $request->language_id,
                'page_type' => $request->page_type,
                'meta_title' => $request->meta_title,
                'meta_description' => $request->meta_description,
                'meta_keywords' => $request->meta_keywords,
                'schema_markup' => $request->schema_markup,
                'og_image' => $ogImagePath,
            ]);

            DB::commit();

            ResponseService::successResponse('SEO settings updated successfully', null, ['redirect_url' => route(
                'admin.seo-settings.index',
            )]);
        } catch (QueryException $e) {
            DB::rollBack();
            // Check if it's a duplicate entry error (MySQL error code 1062 or SQLSTATE 23000)
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            if (
                $errorCode == 23000
                || $errorCode == 1062
                || str_contains($errorMessage, 'Duplicate entry')
                || str_contains($errorMessage, 'seo_settings_language_page_unique')
            ) {
                ResponseService::validationError(
                    'SEO settings for this language and page type already exist. Please edit the existing entry.',
                );
            } else {
                ResponseService::logErrorRedirect($e, 'SeoSettingsController -> update');
                ResponseService::errorResponse('Failed to update SEO settings: ' . $e->getMessage());
            }
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'SeoSettingsController -> update');
            ResponseService::errorRedirectResponse('Failed to update SEO settings');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('settings-system-list');
        try {
            DB::beginTransaction();
            $seoSetting = SeoSetting::findOrFail($id);

            // Delete OG image
            if ($seoSetting->og_image) {
                FileService::delete($seoSetting->og_image);
            }

            $seoSetting->delete();
            DB::commit();
            ResponseService::successResponse('SEO settings deleted successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'SeoSettingsController -> destroy');
            ResponseService::errorResponse('Failed to delete SEO settings: ' . $th->getMessage());
        }
    }

    /**
     * Restore a soft-deleted SEO setting
     */
    public function restore($id)
    {
        try {
            ResponseService::noPermissionThenSendJson('settings-system-list');
            DB::beginTransaction();
            $seoSetting = SeoSetting::onlyTrashed()->findOrFail($id);
            $seoSetting->restore();
            DB::commit();
            ResponseService::successResponse('SEO settings restored successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'SeoSettingsController -> restore');
            ResponseService::errorResponse('Failed to restore SEO settings: ' . $th->getMessage());
        }
    }

    /**
     * Permanently delete a soft-deleted SEO setting
     */
    public function trash($id)
    {
        try {
            ResponseService::noPermissionThenRedirect('settings-system-list');
            DB::beginTransaction();
            $seoSetting = SeoSetting::onlyTrashed()->findOrFail($id);

            // Delete OG image
            if ($seoSetting->og_image) {
                FileService::delete($seoSetting->og_image);
            }

            $seoSetting->forceDelete();
            DB::commit();
            ResponseService::successResponse('SEO settings permanently deleted successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'SeoSettingsController -> trash');
            ResponseService::errorRedirectResponse('Failed to permanently delete SEO settings');
        }
    }
}
