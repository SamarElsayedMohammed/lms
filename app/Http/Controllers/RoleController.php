<?php

namespace App\Http\Controllers;

use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['roles-list', 'roles-create', 'roles-edit', 'roles-delete']);
        $roles = Role::orderBy('id', 'DESC')->get();
        return view('Roles.index', compact('roles'), ['type_menu' => 'roles']);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        ResponseService::noPermissionThenRedirect('roles-create');
        $permission = Permission::get();
        $groupedPermissions = [];

        foreach ($permission as $val) {
            $lastDashPos = strrpos((string) $val->name, '-');
            if ($lastDashPos !== false) {
                $subArr = substr((string) $val->name, 0, $lastDashPos);
                $shortName = str_replace($subArr . '-', '', $val->name);
            } else {
                // If permission name doesn't contain "-", use the full name as group
                $subArr = 'other';
                $shortName = $val->name;
            }

            // Ensure all values are properly cast to strings/numbers
            $permissionArray = $val->toArray();
            $permissionArray['short_name'] = $shortName;

            // Ensure id and name are scalars
            if (isset($permissionArray['id']) && !is_scalar($permissionArray['id'])) {
                $permissionArray['id'] = (string) $permissionArray['id'];
            }
            if (isset($permissionArray['name']) && !is_scalar($permissionArray['name'])) {
                $permissionArray['name'] = is_array($permissionArray['name'])
                    ? json_encode($permissionArray['name'])
                    : (string) $permissionArray['name'];
            }

            $groupedPermissions[$subArr][] = (object) $permissionArray;
        }

        // Keep as array for easier iteration in Blade
        return view('Roles.create', compact('groupedPermissions'), ['type_menu' => 'roles']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['roles-create']);
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:roles,name',
            'permission' => 'required|array',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $role = Role::create(['name' => $request->input('name'), 'custom_role' => 1, 'guard_name' => 'web']);
            $role->syncPermissions($request->input('permission'));
            DB::commit();
            ResponseService::successResponse(trans('Role Created Successfully'));
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorRedirect($e, 'Role Controller -> store');
            ResponseService::errorResponse();
        }
    }

    public function list(Request $request)
    {
        ResponseService::noPermissionThenRedirect(['roles-list']);
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');

        $sql = Role::where('custom_role', 1);

        if (!empty($request->search)) {
            $search = $request->search;
            $sql->where(static function ($query) use ($search): void {
                $query->where('id', 'LIKE', "%$search%")->orwhere('name', 'LIKE', "%$search%");
            });
        }

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;
        foreach ($res as $row) {
            $operate = '';
            if (auth()->user()->can('roles-list')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-eye',
                    route('roles.show', $row->id),
                    ['btn-info'],
                    ['title' => 'View'],
                );
            }
            if (auth()->user()->can('roles-edit')) {
                $operate .= BootstrapTableService::editButton(route('roles.edit', $row->id), false);
            }
            if (auth()->user()->can('roles-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('roles.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        ResponseService::noPermissionThenRedirect('roles-list');
        $role = Role::findOrFail($id);
        $rolePermissions = Permission::join(
            'role_has_permissions',
            'role_has_permissions.permission_id',
            '=',
            'permissions.id',
        )->where('role_has_permissions.role_id', $id)->get();
        return view('Roles.show', compact('role', 'rolePermissions'), ['type_menu' => 'roles']);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        ResponseService::noPermissionThenRedirect('roles-edit');
        $role = Role::findOrFail($id);
        $permission = Permission::get();
        $rolePermissions = DB::table('role_has_permissions')
            ->where('role_has_permissions.role_id', $id)
            ->pluck('role_has_permissions.permission_id', 'role_has_permissions.permission_id')
            ->all();
        $groupedPermissions = [];
        foreach ($permission as $val) {
            $subArr = substr((string) $val->name, 0, strrpos((string) $val->name, '-'));
            $groupedPermissions[$subArr][] = (object) [
                ...$val->toArray(),
                'short_name' => str_replace($subArr . '-', '', $val->name),
            ];
        }

        $groupedPermissions = (object) $groupedPermissions;
        return view('Roles.edit', compact('role', 'groupedPermissions', 'rolePermissions'), ['type_menu' => 'roles']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        ResponseService::noPermissionThenRedirect('roles-edit');
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:roles,name,' . $id,
            'permission' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $role = Role::findOrFail($id);
            $role->name = $request->input('name');
            $role->save();
            $role->syncPermissions($request->input('permission'));
            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'RoleController -> update');
            ResponseService::errorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            ResponseService::noPermissionThenSendJson('roles-delete');
            $role = Role::withCount('users')->findOrFail($id);
            if ($role->users_count) {
                ResponseService::errorResponse('Cannot delete because data is associated with other data');
            } else {
                Role::findOrFail($id)->delete();
                ResponseService::successResponse('Data Deleted Successfully');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorRedirect($e);
            ResponseService::errorResponse();
        }
    }
}
