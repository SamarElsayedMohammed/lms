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

        $currentToken = $user->currentAccessToken();
        $currentTokenName = $currentToken?->name ?? '';
        // Authoritative source is the authenticated token name; fallback to request only if token has no name
        $currentDeviceId = !empty($currentTokenName)
            ? str_replace('-refresh', '', $currentTokenName)
            : ($request->header('X-Device-Id') ?: $request->input('device_id'));

        $devices = UserDevice::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        // Fetch tokens for this user to enrich device metadata (IP, user agent, last used)
        $userTokens = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->get()
            ->keyBy(function ($token) {
                return str_replace('-refresh', '', $token->name);
            });

        $formatted = $devices->map(function ($device) use ($currentDeviceId, $userTokens) {
            $matchingToken = $userTokens->get($device->device_id);
            $isCurrent = (!empty($currentDeviceId) && $device->device_id === $currentDeviceId);

            return [
                'id' => $device->id,
                'device_type' => $device->device_type,
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'ip_address' => $matchingToken?->ip_address ?? null,
                'user_agent' => $matchingToken?->user_agent ?? null,
                'last_active_at' => $matchingToken?->last_used_at ?? ($device->updated_at ? $device->updated_at->toIso8601String() : null),
                'is_current_device' => $isCurrent,
                'updated_at' => $device->updated_at ? $device->updated_at->toIso8601String() : null,
                'created_at' => $device->created_at ? $device->created_at->toIso8601String() : null,
            ];
        });

        return ApiResponseService::successResponse('Devices fetched successfully', $formatted);
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

        $device = UserDevice::where('user_id', $user->id)
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('device_id', (string) $id);
                } else {
                    $query->where('device_id', (string) $id);
                }
            })
            ->first();

        if (!$device) {
            return ApiResponseService::errorResponse('الجهاز غير موجود أو ليس لديك صلاحية لحذفه', null, 404);
        }

        try {
            DB::beginTransaction();

            $baseName = $device->device_id;

            // Revoke Sanctum tokens associated strictly with this device_id (no wildcard prefix collision)
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', get_class($user))
                ->where(function ($query) use ($baseName) {
                    $query->where('name', $baseName)
                        ->orWhere('name', $baseName . '-refresh');
                })
                ->delete();

            $device->delete();

            DB::commit();

            return ApiResponseService::successResponse('تم إزالة الجهاز وإنهاء الجلسة بنجاح');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('تعذر إزالة الجهاز، يرجى المحاولة لاحقاً', null, 500, $e);
        }
    }
}
