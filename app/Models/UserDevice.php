<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_type',
        'device_id',
        'device_name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Register (or verify) a device for the user.
     *
     * @param  int         $userId
     * @param  string      $deviceType   web | android | ios | desktop
     * @param  string      $deviceId     unique hardware/browser fingerprint
     * @param  string|null $deviceName   e.g. "Samsung Galaxy S24"
     * @param  int         $maxDevices   maximum simultaneous active devices allowed (default 3)
     */
    public static function verifyDevice(
        int    $userId,
        string $deviceType,
        string $deviceId,
        ?string $deviceName = null,
        int    $maxDevices = 3,
    ): array {
        if (empty($deviceName)) {
            $deviceName = ucfirst($deviceType) . ' Device';
        }

        // Check if this exact device is already registered for the user
        $existing = self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        if ($existing) {
            // Known device — update name/type and touch last seen
            $existing->update([
                'device_name' => $deviceName,
                'device_type' => $deviceType,
            ]);
            return ['allowed' => true];
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // Check if user already has a device registered for this device_type
            $existingType = self::where('user_id', $userId)->where('device_type', $deviceType)->first();
            if ($existingType) {
                $isWeb = ($deviceType === 'web');
                $nameMatches = (!empty($deviceName) && !empty($existingType->device_name) && strtolower($existingType->device_name) === strtolower($deviceName));
                $currentCount = self::where('user_id', $userId)->count();

                // Web browsers easily reset storage/fingerprints; overwrite old slot so user is never locked out on web.
                // For other types, allow overwrite if device_name matches or if total count is within limits.
                if ($isWeb || $nameMatches || $currentCount <= $maxDevices) {
                    // Revoke old tokens associated with the old device_id
                    \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                        ->where('tokenable_id', $userId)
                        ->where('tokenable_type', \App\Models\User::class)
                        ->where('name', 'like', $existingType->device_id . '%')
                        ->delete();

                    $existingType->update([
                        'device_id'   => $deviceId,
                        'device_name' => $deviceName ?: $existingType->device_name ?: (ucfirst($deviceType) . ' Device'),
                    ]);

                    \Illuminate\Support\Facades\DB::commit();
                    return ['allowed' => true];
                }

                \Illuminate\Support\Facades\DB::rollBack();
                return [
                    'allowed' => false,
                    'code' => 'DEVICE_LIMIT_EXCEEDED',
                    'message' => 'لقد وصلت إلى الحد الأقصى للأجهزة المسموح بها من هذا النوع. يرجى تسجيل الخروج من الجهاز الآخر أو إدارة أجهزتك من لوحة التحكم.'
                ];
            }

            // Explicit Block: Max devices overall limit
            $deviceCount = self::where('user_id', $userId)->count();
            if ($deviceCount >= $maxDevices) {
                \Illuminate\Support\Facades\DB::rollBack();
                return [
                    'allowed' => false,
                    'code' => 'DEVICE_LIMIT_EXCEEDED',
                    'message' => 'لقد وصلت إلى الحد الأقصى الإجمالي للأجهزة المسموح بها. يرجى إدارة أجهزتك من لوحة التحكم.'
                ];
            }

            // Register the new device
            self::create([
                'user_id'     => $userId,
                'device_type' => $deviceType,
                'device_id'   => $deviceId,
                'device_name' => $deviceName,
            ]);

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return [
                'allowed' => false,
                'code' => 'DEVICE_ERROR',
                'message' => 'حدث خطأ أثناء إدارة الأجهزة. يرجى المحاولة مرة أخرى.',
            ];
        }

        return ['allowed' => true];
    }
}
