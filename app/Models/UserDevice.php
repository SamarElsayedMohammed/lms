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

        // New device — check if the user has reached the limit
        $deviceCount = self::where('user_id', $userId)->count();

        if ($deviceCount >= $maxDevices) {
            return [
                'allowed' => false,
                'message' => "لقد وصلت إلى الحد الأقصى المسموح به من الأجهزة ({$maxDevices} أجهزة). يرجى التواصل مع الدعم الفني لإدارة أجهزتك.",
            ];
        }

        // Register the new device
        self::create([
            'user_id'     => $userId,
            'device_type' => $deviceType,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
        ]);

        return ['allowed' => true];
    }
}
