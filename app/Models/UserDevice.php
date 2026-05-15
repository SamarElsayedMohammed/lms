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
     * Returns true if the device is allowed, false if blocked.
     */
    public static function verifyDevice(int $userId, string $deviceType, string $deviceId, ?string $deviceName = null): array
    {
        $existing = self::where('user_id', $userId)
            ->where('device_type', $deviceType)
            ->first();

        if ($existing) {
            // A device of this type is already registered
            if ($existing->device_id === $deviceId) {
                // Same device — allowed
                return ['allowed' => true];
            }
            // Different device of same type — blocked
            return [
                'allowed' => false,
                'message' => "لقد تم تسجيل جهاز {$deviceType} آخر بالفعل. يمكنك تسجيل الدخول من جهاز واحد فقط لكل نوع. يرجى التواصل مع الدعم الفني لتغيير الجهاز.",
            ];
        }

        // No device of this type registered yet — register it
        self::create([
            'user_id'     => $userId,
            'device_type' => $deviceType,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
        ]);

        return ['allowed' => true];
    }
}
