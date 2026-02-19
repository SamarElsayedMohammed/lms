<?php

namespace App\Http\Controllers;

use App\Models\CustomFormField;
use App\Models\CustomFormFieldOption;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CustomFormFieldController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect([
            'custom-form-fields-list',
            'custom-form-fields-create',
            'custom-form-fields-edit',
            'custom-form-fields-delete',
        ]);
        $formFields = CustomFormField::with('options')->orderBy('sort_order')->get();
        return view('custom-form-fields.index', ['type_menu' => 'custom-form-fields', 'formFields' => $formFields]);
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-create']);
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:custom_form_fields,name',
            'type' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Get the maximum sort_order to set the next one
            $maxSortOrder = CustomFormField::max('sort_order') ?? 0;

            // Prepare data for creation
            $data = [
                'name' => $request->name,
                'type' => $request->type,
                'is_required' => $request->has('required') && $request->required == '1' ? 1 : 0,
                'sort_order' => $maxSortOrder + 1,
            ];

            // Create the custom form field
            $customFormField = CustomFormField::create($data);

            // Handle options for dropdown, radio, checkbox
            if (in_array($request->type, ['dropdown', 'radio', 'checkbox']) && $request->has('default_data')) {
                // Validate minimum 2 options for radio and dropdown types
                $optionsCount = count(array_filter(
                    $request->default_data,
                    static fn($option) => !empty($option['option']),
                ));

                if (in_array($request->type, ['dropdown', 'radio']) && $optionsCount < 2) {
                    // Rollback the created field if validation fails
                    $customFormField->delete();
                    return ResponseService::validationError('At least 2 options are required for '
                    . ucfirst($request->type)
                    . ' type.');
                }

                $createdOptions = [];
                foreach ($request->default_data as $optionData) {
                    if (empty($optionData['option'])) {
                        continue;
                    }

                    $createdOptions[] = CustomFormFieldOption::create([
                        'custom_form_field_id' => $customFormField->id,
                        'option' => $optionData['option'],
                    ]);
                }

                // Validate final count after processing
                $finalCount = count($createdOptions);
                if (in_array($request->type, ['dropdown', 'radio']) && $finalCount < 2) {
                    // Rollback the created field if validation fails
                    $customFormField->delete();
                    return ResponseService::validationError('At least 2 options are required for '
                    . ucfirst($request->type)
                    . ' type.');
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Store method');
            ResponseService::errorResponse('An error occurred while storing Tfhe data.');
        }
    }

    /**
     * Display the specified resource for Bootstrap Table.
     */
    public function show(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-list']);
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'sort_order');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $query = CustomFormField::with('options')
            ->where(static function ($query) use ($search): void {
                if ($search) {
                    $query->where('name', 'LIKE', "%$search%");
                }
            })
            ->when($showDeleted, static function ($query): void {
                $query->onlyTrashed();
            });

        $total = $query->count();

        $query->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $query->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';

            $canDelete = auth()->user()->can('custom-form-fields-delete');
            if ($showDeleted) {
                if ($canDelete) {
                    $operate .= BootstrapTableService::restoreButton(route('custom-form-fields.restore', $row->id));
                    $operate .= BootstrapTableService::trashButton(route('custom-form-fields.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('custom-form-fields-edit')) {
                    $operate .= BootstrapTableService::editButton(route('custom-form-fields.edit', $row->id));
                }
                if ($canDelete) {
                    $operate .= BootstrapTableService::deleteButton(route('custom-form-fields.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            // Format type value: convert to title case
            // e.g., "dropdown" -> "Dropdown", "radio" -> "Radio"
            if (!empty($tempRow['type'])) {
                $tempRow['type'] = ucfirst((string) $tempRow['type']);
            }
            // Add export column for is_required
            $tempRow['is_required_export'] =
                $tempRow['is_required'] == 1
                || $tempRow['is_required'] === 1
                || $tempRow['is_required'] === '1'
                || $tempRow['is_required'] === true
                    ? 'Yes'
                    : 'No';
            $tempRow['default_values'] = $row->options->pluck('option')->toArray();
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function edit($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-edit']);
        $customField = CustomFormField::with('options')->findOrFail($id);

        $defaultValues = $customField
            ->options
            ->map(static fn($option) => [
                'id' => $option->id, // <-- add this line
                'option' => $option->option,
            ])
            ->toArray();

        return view('custom-form-fields.edit', compact('customField', 'defaultValues'), [
            'type_menu' => 'custom-form-fields',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CustomFormField $customFormField)
    {
        ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-edit']);
        $request->validate([
            'name' => 'required|unique:custom_form_fields,name,' . $customFormField->id,
            'type' => 'required',
        ]);

        try {
            $data = [
                'name' => $request->name,
                'type' => $request->type,
                'is_required' => $request->has('edit-required') ? 1 : 0,
            ];

            $customFormField->update($data);

            // Handle options for dropdown, radio, checkbox
            if (in_array($request->type, ['dropdown', 'radio', 'checkbox']) && $request->has('edit_default_data')) {
                // Validate minimum 2 options for radio and dropdown types
                $optionsCount = count(array_filter(
                    $request->edit_default_data,
                    static fn($option) => !empty($option['option']),
                ));

                if (in_array($request->type, ['dropdown', 'radio']) && $optionsCount < 2) {
                    return ResponseService::validationError('At least 2 options are required for '
                    . ucfirst($request->type)
                    . ' type.');
                }

                $submittedOptionIds = [];

                foreach ($request->edit_default_data as $optionData) {
                    if (empty($optionData['option'])) {
                        continue; // Skip empty options
                    }

                    if (!empty($optionData['default_value_id'])) {
                        // Update existing option
                        $option = CustomFormFieldOption::find($optionData['default_value_id']);
                        if ($option) {
                            $option->option = $optionData['option'];
                            $option->save();
                            $submittedOptionIds[] = $option->id;
                        }
                    } else {
                        // Create new option
                        $newOption = CustomFormFieldOption::create([
                            'custom_form_field_id' => $customFormField->id,
                            'option' => $optionData['option'],
                        ]);
                        $submittedOptionIds[] = $newOption->id;
                    }
                }

                // Validate final count after processing
                $finalCount = count($submittedOptionIds);
                if (in_array($request->type, ['dropdown', 'radio']) && $finalCount < 2) {
                    return ResponseService::validationError('At least 2 options are required for '
                    . ucfirst($request->type)
                    . ' type.');
                }

                // Soft delete options that were removed (not in submitted IDs)
                $customFormField->options()->whereNotIn('id', $submittedOptionIds)->delete();
            } elseif (!in_array($request->type, ['dropdown', 'radio', 'checkbox'])) {
                // If type changed to non-options type, soft delete all existing options
                $customFormField->options()->delete();
            }

            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Update method');
            ResponseService::errorResponse('An error occurred while updating the data.');
        }
    }

    /**
     * Remove the specified resource from storage (soft delete).
     */
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('custom-form-fields-delete');
        try {
            $customFormField = CustomFormField::findOrFail($id);
            $customFormField->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Destroy method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Update the sort_order of form fields.
     */
    public function updateRankOfFields(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-edit']);
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
            ]);
            if ($validator->fails()) {
                ResponseService::errorResponse($validator->errors()->first());
            }
            foreach ($request->ids as $index => $id) {
                CustomFormField::where('id', $id)->update([
                    'sort_order' => $index + 1,
                ]);
            }
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Update sort_order method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Restore a soft-deleted form field.
     */
    public function restore(int $id)
    {
        ResponseService::noPermissionThenSendJson('custom-form-fields-edit');
        try {
            $formField = CustomFormField::onlyTrashed()->findOrFail($id);
            $formField->restore();
            ResponseService::successResponse('Data Restored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Restore method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Permanently delete a soft-deleted form field.
     */
    public function trash(int $id)
    {
        ResponseService::noPermissionThenSendJson('custom-form-fields-delete');
        try {
            $formField = CustomFormField::onlyTrashed()->findOrFail($id);
            $formField->forceDelete();
            ResponseService::successResponse('Data Deleted Permanently');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> Trash method');
            ResponseService::errorResponse();
        }
    }

    public function deleteOption($id)
    {
        ResponseService::noPermissionThenSendJson('custom-form-fields-delete');
        try {
            $option = CustomFormFieldOption::findOrFail($id);
            $option->delete(); // soft delete
            ResponseService::successResponse('Option Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'CustomFormFieldController -> deleteOption');
            ResponseService::errorResponse();
        }
    }
}
