<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserDeviceApiController extends Controller
{
    /**
     * Get a list of the user's active devices.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::unauthorizedResponse();
        }

        $devices = UserDevice::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'device_type', 'device_id', 'device_name', 'updated_at']);

        ApiResponseService::successResponse('Devices fetched successfully', $devices);
    }

    /**
     * Remove a registered device and revoke its associated access tokens.
     */
    public function destroy($id, Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::unauthorizedResponse();
        }

        $device = UserDevice::where('user_id', $user->id)->where('id', $id)->first();

        if (!$device) {
            return ApiResponseService::errorResponse('Device not found or unauthorized', null, 404);
        }

        try {
            DB::beginTransaction();

            // Revoke Sanctum tokens associated with this device_id
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', get_class($user))
                ->where('name', 'like', $device->device_id . '%')
                ->delete();

            $device->delete();

            DB::commit();

            ApiResponseService::successResponse('Device removed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            ApiResponseService::errorResponse('Failed to remove device', null, 500, $e);
        }
    }
}
