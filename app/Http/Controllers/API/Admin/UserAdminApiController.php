<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\User;
use App\Services\AdminStudentStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserAdminApiController extends AdminCrudApiController
{
    protected AdminStudentStatisticsService $statisticsService;

    public function __construct(AdminStudentStatisticsService $statisticsService)
    {
        $this->middleware('auth:sanctum');
        $this->statisticsService = $statisticsService;
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $search = $request->input('search');
        $subscriptionType = $request->input('subscription_type');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');
        $role = $request->input('role');

        $query = User::with(['instructor_details', 'activeSubscription.plan', 'roles'])
            ->when($role, function ($q) use ($role) {
                if ($role === 'admin') {
                    $q->role(['admin', 'super_admin']);
                } else {
                    $q->role($role);
                }
            })
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

    public function stats(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $roleFilter = $request->input('role', 'all');
        $stats = $this->statisticsService->getStatistics($roleFilter);

        return $this->jsonSuccess(__('User statistics retrieved'), $stats);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('users-list');

        $user = User::with(['roles', 'instructor_details', 'activeSubscription.plan'])->withTrashed()->find($id);
        if (!$user) {
            return $this->jsonError(__('User not found'), 404);
        }

        $user->is_instructor = !empty($user->instructor_details);
        $user->instructor_status = $user->instructor_details->status ?? null;
        $user->device_count = \App\Models\UserDevice::where('user_id', $id)->count();
        // allowed_devices_count is already on the model, but let's ensure it's exposed clearly
        // (It's in $fillable and not in $hidden, so it will be returned naturally)

        if ($user->activeSubscription) {
            $user->active_subscription_type = ucfirst($user->activeSubscription->plan->billing_cycle ?? 'unknown');
            $user->active_subscription_plan_name = $user->activeSubscription->plan->name ?? null;
            if ($user->activeSubscription->ends_at) {
                $days = \Illuminate\Support\Carbon::now()->diffInDays($user->activeSubscription->ends_at, false);
                $user->active_subscription_days_left = $days > 0 ? (int) $days : 0;
            } else {
                $user->active_subscription_days_left = 'N/A';
            }
        } else {
            $user->active_subscription_type = null;
            $user->active_subscription_plan_name = null;
            $user->active_subscription_days_left = null;
        }

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
            'allowed_devices_count' => 'nullable|integer|min:1',
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
