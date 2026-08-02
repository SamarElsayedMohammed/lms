<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin — User Device Management
 *
 * Allows admins to view and remove registered devices for any user.
 * Useful when a user is blocked from logging in because they've
 * reached their device limit and can't free a slot themselves.
 */
class UserDeviceAdminApiController extends Controller
{
    /**
     * GET /api/admin/users/{userId}/devices
     *
     * List all registered devices for a specific user.
     */
    public function index($userId)
    {
        $this->requireAdminRole();

        $user = User::findOrFail($userId);

        $devices = UserDevice::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($d) => [
                'id'          => $d->id,
                'device_type' => $d->device_type ?? 'web',
                'device_id'   => $d->device_id ?? '',
                'device_name' => $d->device_name ?: (ucfirst($d->device_type ?? 'web') . ' Device'),
                'last_seen'   => $d->updated_at?->diffForHumans() ?? $d->created_at?->diffForHumans() ?? 'Recently',
                'registered'  => $d->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
                'created_at'  => $d->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
            ]);

        ApiResponseService::successResponse('Devices retrieved successfully', [
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'device_count' => $devices->count(),
            'devices'      => $devices,
        ]);
    }

    /**
     * DELETE /api/admin/users/{userId}/devices/{deviceId}
     *
     * Remove a specific device for a user.
     * After removal, the user can log in from a new device.
     */
    public function destroy($userId, $deviceId)
    {
        $this->requireAdminRole();

        $device = UserDevice::where('user_id', $userId)
            ->where('id', $deviceId)
            ->firstOrFail();

        $device->delete();

        ApiResponseService::successResponse('Device removed successfully. The user can now login from a new device.');
    }

    /**
     * DELETE /api/admin/users/{userId}/devices
     *
     * Remove ALL devices for a user (full reset).
     * The user will need to re-authenticate on all their devices.
     */
    public function destroyAll($userId)
    {
        $this->requireAdminRole();

        User::findOrFail($userId); // ensure user exists

        $count = UserDevice::where('user_id', $userId)->count();
        UserDevice::where('user_id', $userId)->delete();

        ApiResponseService::successResponse(
            "All {$count} device(s) removed. The user can now login from any device.",
            ['removed_count' => $count]
        );
    }

    // -------------------------------------------------------------------------

    private function requireAdminRole(): void
    {
        $user = Auth::user();
        $adminRoles = [
            'Super Admin',
            'Admin',
            config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
            config('constants.SYSTEM_ROLES.STAFF'),
            config('constants.SYSTEM_ROLES.SUPERVISOR'),
        ];

        if (!$user || !$user->hasAnyRole($adminRoles, 'web')) {
            ApiResponseService::errorResponse('Unauthorized', null, 403);
        }
    }
}
