<?php

namespace App\Http\Controllers;

use App\Models\HelpdeskGroup;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class HelpdeskGroupController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect([
            'helpdesk-groups-list',
            'helpdesk-groups-create',
            'helpdesk-groups-edit',
            'helpdesk-groups-delete',
        ]);
        return view('helpdesk.groups', ['type_menu' => 'helpdesk']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-create');
        $request->validate([
            'name' => 'required|string|max:255|unique:helpdesk_groups,name',
            'slug' => 'nullable|string|max:255|unique:helpdesk_groups,slug',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
        ]);

        try {
            $data = $request->only(['name', 'slug', 'description']);

            // Handle is_private field
            $data['is_private'] = $request->has('is_private') ? 1 : 0;

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = Str::slug($request->name) . '_' . time() . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('helpdesk-groups', $imageName, 'public');
                $data['image'] = $imagePath;
            }

            HelpDeskGroup::create($data);
            return ResponseService::successResponse('Group Created Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpDeskGroupController -> store()');
            return ResponseService::errorRedirectResponse('Failed to create Group');
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $sql = HelpDeskGroup::when(!empty($showDeleted), static fn($q) => $q->onlyTrashed())->when(
            !empty($search),
            static function ($q) use ($search): void {
                $q->where('name', 'LIKE', "%$search%")->orWhere('description', 'LIKE', "%$search%");
            },
        );

        $sql->orderBy($sort, $order);

        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $rows = [];
        $no = 1;
        foreach ($result as $row) {
            $tempRow = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) ($row->slug ?? ''),
                'description' => (string) ($row->description ?? ''),
                'image' => $row->image ? Storage::url($row->image) : '',
                'is_private' => (int) $row->is_private,
                'row_order' => (int) $row->row_order,
                'is_active' => (int) $row->is_active,
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
                'no' => (int) $no++,
            ];

            if ($showDeleted) {
                $operate = '';
                if (auth()->user()->can('helpdesk-groups-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('groups.restore', $row->id));
                }
                if (auth()->user()->can('helpdesk-groups-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('groups.trash', $row->id));
                }
            } else {
                $operate = '';
                if (auth()->user()->can('helpdesk-groups-edit')) {
                    // Create edit button with data attributes
                    $editButton =
                        '<a href="'
                        . route('groups.update', $row->id)
                        . '" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-primary edit-data" title="Edit" data-target="#groupEditModal" data-toggle="modal" data-id="'
                        . $row->id
                        . '" data-name="'
                        . htmlspecialchars((string) $row->name, ENT_QUOTES, 'UTF-8')
                        . '" data-slug="'
                        . htmlspecialchars($row->slug ?? '', ENT_QUOTES, 'UTF-8')
                        . '" data-description="'
                        . htmlspecialchars($row->description ?? '', ENT_QUOTES, 'UTF-8')
                        . '" data-image="'
                        . htmlspecialchars($row->image ? Storage::url($row->image) : '', ENT_QUOTES, 'UTF-8')
                        . '" data-is-private="'
                        . $row->is_private
                        . '" data-active="'
                        . $row->is_active
                        . '"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';
                    $operate .= $editButton;
                }
                if (auth()->user()->can('helpdesk-groups-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('groups.destroy', $row->id), $row->id);
                }

                // Debug output for first row
                if ($row->id == 1) {
                    Log::info('Edit button for first group:', [
                        'id' => $row->id,
                        'name' => $row->name,
                        'description' => $row->description,
                        'is_active' => $row->is_active,
                        'button_html' => $editButton,
                    ]);
                }
            }

            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function update(Request $request, HelpDeskGroup $group)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-edit');
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:helpdesk_groups,name,' . $group->id,
            'slug' => 'nullable|string|max:255|unique:helpdesk_groups,slug,' . $group->id,
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();

            // Handle is_private field
            $data['is_private'] = $request->has('is_private') ? 1 : 0;

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($group->image && Storage::disk('public')->exists($group->image)) {
                    Storage::disk('public')->delete($group->image);
                }

                $image = $request->file('image');
                $imageName = Str::slug($request->name) . '_' . time() . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('helpdesk-groups', $imageName, 'public');
                $data['image'] = $imagePath;
            }

            $group->update($data);
            return ResponseService::successResponse('Group Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpDeskGroupController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy(HelpDeskGroup $group)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-delete');
        $group->delete();
        return ResponseService::successResponse('Group Deleted Successfully');
    }

    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-edit');
        $group = HelpDeskGroup::withTrashed()->findOrFail($id);
        $group->restore();
        return ResponseService::successResponse('Group Restored Successfully');
    }

    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-groups-delete');
        $group = HelpDeskGroup::withTrashed()->findOrFail($id);
        $group->forceDelete();
        return ResponseService::successResponse('Group Permanently Deleted');
    }

    public function updateRankOfGroups(Request $request)
    {
        //ResponseService::noAnyPermissionThenRedirect(['custom-form-fields-edit']);
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
            ]);
            if ($validator->fails()) {
                ResponseService::errorResponse($validator->errors()->first());
            }
            foreach ($request->ids as $index => $id) {
                HelpDeskGroup::where('id', $id)->update([
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
