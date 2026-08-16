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

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use (
                $userId,
                $deviceType,
                $deviceId,
                $deviceName,
                $maxDevices
            ) {
                // 1. Check if this exact device is already registered for the user (with lock to prevent race)
                $existing = self::where('user_id', $userId)
                    ->where('device_id', $deviceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // Known device re-authenticating — update metadata & touch updated_at without consuming new slot
                    $existing->update([
                        'device_name' => $deviceName,
                        'device_type' => $deviceType,
                    ]);
                    return ['allowed' => true];
                }

                // 2. Count current active registered devices for this user
                $currentCount = self::where('user_id', $userId)
                    ->lockForUpdate()
                    ->count();

                if ($currentCount >= $maxDevices) {
                    return [
                        'allowed' => false,
                        'code' => 'DEVICE_LIMIT_EXCEEDED',
                        'message' => 'لقد وصلت إلى الحد الأقصى للأجهزة المسموح بها. يمكنك تسجيل الخروج من الأجهزة الأخرى للمتابعة.'
                    ];
                }

                // 3. Register the new device (handling potential legacy schema gracefully)
                try {
                    self::create([
                        'user_id'     => $userId,
                        'device_type' => $deviceType,
                        'device_id'   => $deviceId,
                        'device_name' => $deviceName,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // If legacy unique(user_id, device_type) constraint exists, update slot safely
                    $existingType = self::where('user_id', $userId)
                        ->where('device_type', $deviceType)
                        ->first();

                    if ($existingType) {
                        // Revoke tokens for the old device_id
                        $oldId = $existingType->device_id;
                        \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                            ->where('tokenable_id', $userId)
                            ->where('tokenable_type', \App\Models\User::class)
                            ->where(function ($q) use ($oldId) {
                                $q->where('name', $oldId)
                                  ->orWhere('name', $oldId . '-refresh');
                            })
                            ->delete();

                        $existingType->update([
                            'device_id'   => $deviceId,
                            'device_name' => $deviceName,
                        ]);
                    } else {
                        throw $qe;
                    }
                }

                return ['allowed' => true];
            });
        } catch (\Exception $e) {
            return [
                'allowed' => false,
                'code' => 'DEVICE_ERROR',
                'message' => 'حدث خطأ أثناء إدارة الأجهزة. يرجى المحاولة مرة أخرى.',
            ];
        }
    }
}
