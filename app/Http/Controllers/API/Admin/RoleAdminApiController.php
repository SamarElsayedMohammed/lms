<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class RoleAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of the roles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-list');

        $search = $request->input('search');
        $perPage = (int) $request->input('per_page', 15);

        $query = Role::query()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });

        $roles = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Roles retrieved successfully'), $roles);
    }

    /**
     * Display the specified role with its permissions.
     */
    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-list');

        $role = Role::with('permissions')->find($id);

        if (!$role) {
            return $this->jsonError(__('Role not found'), 404);
        }

        return $this->jsonSuccess(__('Role retrieved successfully'), $role);
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-create');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles,name',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'web',
                'custom_role' => true
            ]);
            $role->syncPermissions($request->permissions);
            DB::commit();

            return $this->jsonSuccess(__('Role created successfully'), $role->load('permissions'), 201);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create role: ') . $e->getMessage());
        }
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-edit');

        $role = Role::find($id);

        if (!$role) {
            return $this->jsonError(__('Role not found'), 404);
        }

        if (!$role->custom_role && $role->name !== $request->name) {
             return $this->jsonError(__('System roles names cannot be changed'), 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles,name,' . $id,
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $role->update(['name' => $request->name]);
            $role->syncPermissions($request->permissions);
            DB::commit();

            return $this->jsonSuccess(__('Role updated successfully'), $role->load('permissions'));
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update role: ') . $e->getMessage());
        }
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-delete');

        $role = Role::withCount('users')->find($id);

        if (!$role) {
            return $this->jsonError(__('Role not found'), 404);
        }

        if (!$role->custom_role) {
            return $this->jsonError(__('System roles cannot be deleted'), 422);
        }

        if ($role->users_count > 0) {
            return $this->jsonError(__('Cannot delete role that is assigned to users'), 422);
        }

        $role->delete();

        return $this->jsonSuccess(__('Role deleted successfully'));
    }

    /**
     * Get all available permissions grouped by module.
     */
    public function permissions(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('roles-list');

        $allPermissions = Permission::all();
        $groupedPermissions = [];

        foreach ($allPermissions as $permission) {
            $parts = explode('-', (string) $permission->name);
            $module = $parts[0];
            $groupedPermissions[$module][] = $permission;
        }

        return $this->jsonSuccess(__('Permissions retrieved successfully'), $groupedPermissions);
    }
}
