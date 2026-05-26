<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $search = $request->input('search');
        $subscriptionType = $request->input('subscription_type');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = User::with(['instructor_details', 'activeSubscription.plan', 'roles'])
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            }))
            ->when($subscriptionType, fn ($q) => $q->whereHas('subscriptions', fn ($sq) => $sq->where('status', 'active')->whereHas('plan', fn ($pq) => $pq->where('billing_cycle', $subscriptionType))));

        $users = $query->orderBy('id', 'desc')->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            $user->is_instructor = !empty($user->instructor_details);
            $user->instructor_status = $user->instructor_details->status ?? null;
            $user->active_subscription_type = $user->activeSubscription
                ? ucfirst($user->activeSubscription->plan->billing_cycle ?? 'unknown')
                : null;
            return $user;
        });

        return $this->jsonSuccess(__('Users retrieved'), $users);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $user = User::with(['roles', 'instructor_details', 'activeSubscription'])->withTrashed()->find($id);
        if (!$user) {
            return $this->jsonError(__('User not found'), 404);
        }

        $user->is_instructor = !empty($user->instructor_details);
        $user->instructor_status = $user->instructor_details->status ?? null;

        return $this->jsonSuccess(__('User retrieved'), $user);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $user = User::find($id);
        if (!$user) {
            return $this->jsonError(__('User not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'mobile' => 'nullable|string|max:20',
            'country_calling_code' => 'nullable|string|max:10',
            'country_code' => 'nullable|string|max:5',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $user->update($validator->validated());
        return $this->jsonSuccess(__('User updated successfully'), $user->fresh());
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $user = User::find($id);
        if (!$user) {
            return $this->jsonError(__('User not found'), 404);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return $this->jsonSuccess(__('User status updated'), ['is_active' => (bool) $user->is_active]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-edit');

        $user = User::find($id);
        if (!$user) {
            return $this->jsonError(__('User not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'role_name' => 'required|string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $user->syncRoles([$request->role_name]);

        return $this->jsonSuccess(__('User role updated successfully'), [
            'roles' => $user->getRoleNames()
        ]);
    }
}
