<?php

namespace App\Http\Controllers;

use App\Models\FeatureSection;
use App\Models\FeatureSectionImage;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FeatureSectionController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('feature-sections-list');
        return view('feature-sections.index', ['type_menu' => 'feature-sections']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-create');
        $limitTypes = [
            'top_rated_courses',
            'newly_added_courses',
            'most_viewed_courses',
            'free_courses',
            'wishlist',
            'searching_based',
            'recommend_for_you',
            'my_learning',
        ];

        $rules = [
            'type' => 'required|string|max:255',
            'title' => 'required|string|max:255',
        ];

        // Make limit required only for types that need it
        if (in_array($request->type, $limitTypes)) {
            $rules['limit'] = 'required|integer|min:1';
        } else {
            $rules['limit'] = 'nullable|integer|min:1';
        }

        // Make offer_image required when type is offer
        if ($request->type === 'offer') {
            $rules['offer_image'] = 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048';
        } else {
            $rules['offer_image'] = 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048';
        }

        $request->validate($rules);

        try {
            $data = $request->only(['type', 'title', 'limit']);
            $featureSection = FeatureSection::create($data);

            // Store image if type is offer
            if ($request->type === 'offer' && $request->hasFile('offer_image')) {
                $path = $request->file('offer_image')->store('feature_section', 'public');

                FeatureSectionImage::create([
                    'feature_section_id' => $featureSection->id,
                    'image' => $path,
                ]);
            }

            return ResponseService::successResponse('Feature Section Created Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            return ResponseService::errorRedirectResponse('Failed to create Feature Section');
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-list');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'row_order');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $sql = FeatureSection::with('images') // eager load images
            ->when($showDeleted == 1 || $showDeleted === '1', static function ($query): void {
                $query->onlyTrashed();
            })
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('type', 'LIKE', "%$search%")
                        ->orWhere('title', 'LIKE', "%$search%")
                        ->orWhere('limit', 'LIKE', "%$search%")
                        ->orWhere('row_order', 'LIKE', "%$search%");
                });
            });

        $sql->orderBy($sort, $order);

        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($result as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('feature-sections-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('feature-sections.restore', $row->id));
                }
                if (auth()->user()->can('feature-sections-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('feature-sections.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('feature-sections-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('feature-sections.update', $row->id),
                        true,
                        '#featureSectionEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('feature-sections-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('feature-sections.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            // Keep original type value (with underscores) for edit form
            // Don't format it here - let the formatter handle display in table
            // The type field should remain as "top_rated_courses" etc. for the edit form to work
            // Get image URLs properly
            $tempRow['images'] = $row->images->map(static function ($image) {
                return $image->image; // This will use the getImageAttribute accessor
            })->toArray();
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, FeatureSection $featureSection)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-edit');

        $limitTypes = [
            'top_rated_courses',
            'newly_added_courses',
            'most_viewed_courses',
            'free_courses',
            'wishlist',
            'searching_based',
            'recommend_for_you',
            'my_learning',
        ];

        $rules = [
            'type' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'offer_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        ];

        // Make limit required only for types that need it
        if (in_array($request->type, $limitTypes)) {
            $rules['limit'] = 'required|integer|min:1';
        } else {
            $rules['limit'] = 'nullable|integer|min:1';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();
            // Remove offer_image from update data - it's not a column in feature_sections table
            unset($data['offer_image']);

            // Remove is_active from update data - status should not be changed from edit modal

            $featureSection->update($data);

            // Handle offer image update if type is offer and image is uploaded
            if ($request->type === 'offer' && $request->hasFile('offer_image')) {
                $path = $request->file('offer_image')->store('feature_section', 'public');

                // Check if image already exists for this feature section
                $existingImage = FeatureSectionImage::where('feature_section_id', $featureSection->id)->first();

                if ($existingImage) {
                    // Delete old image file (get original value before accessor)
                    $oldImagePath = $existingImage->getOriginal('image');
                    if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                        Storage::disk('public')->delete($oldImagePath);
                    }
                    // Update existing image record
                    $existingImage->update(['image' => $path]);
                } else {
                    // Create new image record
                    FeatureSectionImage::create([
                        'feature_section_id' => $featureSection->id,
                        'image' => $path,
                    ]);
                }
            }

            return ResponseService::successResponse('Feature Section Updated Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'FeatureSectionController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy(FeatureSection $featureSection)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-delete');
        $featureSection->delete();
        return ResponseService::successResponse('Feature Section Deleted Successfully');
    }

    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-delete');
        try {
            $featureSection = FeatureSection::onlyTrashed()->findOrFail($id);
            $featureSection->restore();
            return ResponseService::successResponse('Feature Section Restored Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'FeatureSectionController -> restore()');
            return ResponseService::errorResponse('Failed to restore Feature Section');
        }
    }

    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-delete');
        try {
            $featureSection = FeatureSection::onlyTrashed()->findOrFail($id);
            $featureSection->forceDelete();
            return ResponseService::successResponse('Feature Section Permanently Deleted Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'FeatureSectionController -> trash()');
            return ResponseService::errorResponse('Failed to permanently delete Feature Section');
        }
    }

    public function updateRankOfFeatureSections(Request $request)
    {
        ResponseService::noPermissionThenSendJson('feature-sections-edit');
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
            ]);
            if ($validator->fails()) {
                ResponseService::errorResponse($validator->errors()->first());
            }
            foreach ($request->ids as $index => $id) {
                FeatureSection::where('id', $id)->update([
                    'row_order' => $index + 1,
                ]);
            }
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'FeatureSectionController -> Update row_order method');
            ResponseService::errorResponse();
        }
    }
}
