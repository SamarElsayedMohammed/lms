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
        // Check if this exact device is already registered for the user
        $existing = self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        if ($existing) {
            // Known device — always allowed (refresh device_name if changed)
            if ($deviceName && $existing->device_name !== $deviceName) {
                $existing->update(['device_name' => $deviceName, 'device_type' => $deviceType]);
            } else {
                // Touch updated_at so it appears as "last seen"
                $existing->touch();
            }
            return ['allowed' => true];
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // Explicit Block: 1 device per type limit
            $existingType = self::where('user_id', $userId)->where('device_type', $deviceType)->first();
            if ($existingType) {
                // B2 Grace: If the device_id changed but device_name and device_type match exactly,
                // we assume it's the same physical device getting a new ID. Overwrite the old one.
                if ($existingType->device_name === $deviceName) {
                    // Revoke old tokens associated with the old device_id
                    \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                        ->where('tokenable_id', $userId)
                        ->where('tokenable_type', \App\Models\User::class)
                        ->where('name', 'like', $existingType->device_id . '%')
                        ->delete();

                    $existingType->update(['device_id' => $deviceId]);
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
