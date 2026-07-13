<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

final class AuthDeviceService
{
    /**
     * Verify device limits for a user during login or registration.
     * Throws an HttpResponseException if the limit is exceeded.
     */
    public static function verifyDeviceLimits(User $user, Request $request): ?array
    {
        $deviceType = $request->input('device_type');
        $deviceId   = $request->input('device_id');

        // If client doesn't send device info, skip verification (backward compatibility for some clients/flows)
        if (empty($deviceType) || empty($deviceId)) {
            return null;
        }

        // Check if user requested to clear devices (B1)
        if ($request->input('clear_devices') == true || $request->input('clear_devices') === 'true' || $request->input('clear_devices') === '1') {
            UserDevice::where('user_id', $user->id)->delete();
            \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', get_class($user))
                ->delete();
        }

        // Determine max devices for this user
        $maxDevices = $user->allowed_devices_count ?? (int) HelperService::systemSettings('default_device_limit', 3);
        if ($maxDevices <= 0) {
            $maxDevices = 3;
        }

        $result = UserDevice::verifyDevice(
            $user->id,
            $deviceType,
            $deviceId,
            $request->input('device_name'),
            $maxDevices
        );

        if (!$result['allowed']) {
            return $result;
        }
        
        return null;
    }
}
