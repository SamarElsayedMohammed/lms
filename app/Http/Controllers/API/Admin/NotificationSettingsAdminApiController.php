<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationSettingsAdminApiController extends Controller
{
    /**
     * Get global notification settings
     */
    public function getSettings()
    {
        // Fetch current configs
        $configSetting = Setting::where('name', 'notification_channels_config')->first();
        $config = $configSetting ? json_decode((string) $configSetting->value, true) : [];

        $expirySetting = Setting::where('name', 'subscription_expiry_days')->first();
        $expiryDays = $expirySetting ? $expirySetting->value : '15,7,3';

        // Scan available notifications
        $notificationFiles = glob(app_path('Notifications/*.php'));
        $notifications = [];

        foreach ($notificationFiles as $file) {
            $className = basename($file, '.php');
            
            // Exclude some manually dispatched notifications that might not be configurable globally
            // or we can just list all of them
            $mailEnabled = isset($config[$className]['mail']) ? (bool) $config[$className]['mail'] : true;
            $pushEnabled = isset($config[$className]['database']) ? (bool) $config[$className]['database'] : true;

            $notifications[] = [
                'class_name' => $className,
                'name' => preg_replace('/(?<!^)[A-Z]/', ' $0', $className), // Make it human readable
                'mail_enabled' => $mailEnabled,
                'push_enabled' => $pushEnabled,
            ];
        }

        return ApiResponseService::successResponse('Notification settings retrieved successfully', [
            'subscription_expiry_days' => $expiryDays,
            'notifications' => $notifications
        ]);
    }

    /**
     * Update global notification settings
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'subscription_expiry_days' => 'nullable|string',
            'notifications' => 'nullable|array',
            'notifications.*.class_name' => 'required|string',
            'notifications.*.mail_enabled' => 'required|boolean',
            'notifications.*.push_enabled' => 'required|boolean',
        ]);

        if ($request->has('subscription_expiry_days')) {
            Setting::updateOrCreate(
                ['name' => 'subscription_expiry_days'],
                ['value' => $request->subscription_expiry_days, 'type' => 'text']
            );
        }

        if ($request->has('notifications')) {
            $configSetting = Setting::where('name', 'notification_channels_config')->first();
            $config = $configSetting ? json_decode((string) $configSetting->value, true) : [];

            foreach ($request->notifications as $notificationData) {
                $className = $notificationData['class_name'];
                $config[$className] = [
                    'mail' => $notificationData['mail_enabled'],
                    'database' => $notificationData['push_enabled']
                ];
            }

            Setting::updateOrCreate(
                ['name' => 'notification_channels_config'],
                ['value' => json_encode($config), 'type' => 'text']
            );
        }

        // Clear settings cache
        Cache::forget(config('constants.CACHE.SETTINGS', 'system_settings'));

        return ApiResponseService::successResponse('Notification settings updated successfully');
    }
}
