<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NotificationSettingsAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->ensureAdmin();
            return $next($request);
        });
    }
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
            
            $channels = $this->notificationChannels($className);
            $mailEnabled = $channels['mail'] && (isset($config[$className]['mail']) ? (bool) $config[$className]['mail'] : true);
            $pushEnabled = $channels['database'] && (isset($config[$className]['database']) ? (bool) $config[$className]['database'] : true);

            $notifications[] = [
                'class_name' => $className,
                'name' => $this->notificationDisplayName($className),
                'available_channels' => [
                    'mail' => $channels['mail'],
                    'database' => $channels['database'],
                ],
                'mail_enabled' => $mailEnabled,
                'email_enabled' => $mailEnabled,
                'push_enabled' => $pushEnabled,
                'notification_enabled' => $pushEnabled,
                'database_enabled' => $pushEnabled,
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
            'notifications.*.mail_enabled' => 'nullable|boolean',
            'notifications.*.email_enabled' => 'nullable|boolean',
            'notifications.*.push_enabled' => 'nullable|boolean',
            'notifications.*.notification_enabled' => 'nullable|boolean',
            'notifications.*.database_enabled' => 'nullable|boolean',
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
                    'mail' => (bool) ($notificationData['mail_enabled'] ?? $notificationData['email_enabled'] ?? $config[$className]['mail'] ?? true),
                    'database' => (bool) ($notificationData['push_enabled'] ?? $notificationData['notification_enabled'] ?? $notificationData['database_enabled'] ?? $config[$className]['database'] ?? true),
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

    /**
     * Preview the general email notification template.
     */
    public function previewEmail(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'message' => 'required|string',
            'image' => 'nullable|string' // usually a URL for preview purposes
        ]);

        $html = view('emails.general-notification', [
            'notificationTitle' => $request->title,
            'notificationContent' => $request->message,
            'imageUrl' => $request->image,
            'greeting' => 'مرحباً،' // Default greeting for preview
        ])->render();

        return ApiResponseService::successResponse('Preview generated successfully', [
            'html' => $html
        ]);
    }
    private function notificationDisplayName(string $className): string
    {
        return trim(Str::headline(str_replace('Notification', '', $className)));
    }

    private function notificationChannels(string $className): array
    {
        $fqcn = "App\\Notifications\\{$className}";

        // Use reflection to check if the class actually implements toMail with a real body
        // (not just inherited from the base Notification class)
        $mailSupported = false;
        if (class_exists($fqcn)) {
            try {
                $reflection = new \ReflectionMethod($fqcn, 'toMail');
                // Only count it as mail-supported if it's declared directly on this class
                $mailSupported = $reflection->getDeclaringClass()->getName() === $fqcn;
            } catch (\ReflectionException) {
                $mailSupported = false;
            }
        }

        $databaseSupported = class_exists($fqcn) && (
            method_exists($fqcn, 'toDatabase') || method_exists($fqcn, 'toArray')
        );

        return [
            'mail'     => $mailSupported,
            'database' => $databaseSupported,
        ];
    }
}
