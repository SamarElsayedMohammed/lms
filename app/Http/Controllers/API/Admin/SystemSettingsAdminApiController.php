<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Http\Request;

class SystemSettingsAdminApiController extends AdminCrudApiController
{
    private $logoFolder = 'logos/';
    private $faviconFolder = 'favicons/';

    /**
     * Get App Settings
     */
    public function getAppSettings(Request $request)
    {
        $this->checkPermission('settings-app-list');
        $settings = HelperService::systemSettings([
            'playstore_url',
            'appstore_url',
            'android_version',
            'ios_version',
            'app_version',
            'force_update',
        ]);
        return $this->jsonSuccess('App Settings retrieved successfully', $settings);
    }

    /**
     * Update App Settings
     */
    public function updateAppSettings(Request $request)
    {
        $this->checkPermission('settings-app-edit');
        
        $fields = [
            'playstore_url' => 'text',
            'appstore_url' => 'text',
            'android_version' => 'text',
            'ios_version' => 'text',
            'app_version' => 'text',
            'force_update' => 'boolean',
            'app_name' => 'text',
            'announcement_text' => 'text',
            'maintenance_mode' => 'boolean',
            'timezone' => 'text',
            'max_video_size' => 'text',
            'instructor_mode' => 'text',
            'solo_commission_fee' => 'number',
            'team_commission_fee' => 'number',
            'refund_enabled' => 'boolean',
            'refund_days' => 'number',
            'default_language_code' => 'text',
        ];

        $settingsData = [];
        foreach ($fields as $name => $type) {
            if ($request->has($name)) {
                $value = $request->input($name);
                if ($type === 'boolean') {
                    $value = in_array($value, [1, '1', true, 'true'], true) ? 1 : 0;
                }
                
                // Map frontend keys to backend database keys if needed
                $dbName = $name;
                if ($name === 'maintenance_mode') $dbName = 'maintaince_mode';
                if ($name === 'refund_days') $dbName = 'refund_period_days';
                if ($name === 'app_name') $dbName = 'app_name'; // We want both app_name and site_name to stay consistent
                
                $settingsData[] = [
                    'name' => $dbName,
                    'value' => $value,
                    'type' => $type,
                ];
                
                if ($name === 'app_name') {
                    $settingsData[] = [
                        'name' => 'site_name',
                        'value' => $value,
                        'type' => $type,
                    ];
                }
            }
        }

        if (!empty($settingsData)) {
            Setting::upsert($settingsData, ['name']);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
            if ($request->has('app_name')) {
                HelperService::changeEnv(['APP_NAME' => $request->input('app_name')]);
            }

            if ($request->has('timezone') && !empty($request->input('timezone'))) {
                try {
                    date_default_timezone_set($request->input('timezone'));
                    config(['app.timezone' => $request->input('timezone')]);
                    HelperService::changeEnv(['APP_TIMEZONE' => $request->input('timezone')]);
                } catch (\Exception $e) {
                    // Ignore timezone errors
                }
            }
        }

        return $this->jsonSuccess('App Settings updated successfully');
    }

    /**
     * Get Web Settings
     */
    public function getWebSettings(Request $request)
    {
        $this->checkPermission('settings-system-list');
        $settings = HelperService::systemSettings([
            'site_name',
            'app_name',
            'site_url',
            'website_url',
            'announcement_bar',
            'announcement_text',
            'contact_address',
            'contact_email',
            'contact_phone',
            'currency_name',
            'currency_symbol',
            'brand_color',
            'brand_light_color',
            'scroll_color',
            'footer_description',
            'copyright_text',
            'favicon',
            'horizontal_logo',
            'vertical_logo',
            'default_image',
            'auth_banner',
            'maintaince_mode',
            'timezone',
            'instructor_mode',
            'refund_enabled',
            'refund_period_days',
            'individual_instructor_terms',
            'team_instructor_terms',
            'default_language_code'
        ]);
        
        // Convert file paths to URLs
        $fileFields = ['favicon', 'horizontal_logo', 'vertical_logo', 'default_image', 'auth_banner'];
        foreach ($fileFields as $field) {
            if (!empty($settings[$field]) && !filter_var($settings[$field], FILTER_VALIDATE_URL)) {
                $settings[$field . '_url'] = FileService::getFileUrl($settings[$field]);
            }
        }

        return $this->jsonSuccess('Web Settings retrieved successfully', $settings);
    }

    /**
     * Update Web Settings
     */
    public function updateWebSettings(Request $request)
    {
        $this->checkPermission('settings-system-edit');
        
        $settingsData = [];
        $allInputs = $request->except(['_token', '_method']);

        // Key mapping from frontend to backend
        $keyMap = [
            'favicon_url' => 'favicon',
            'logo_vertical_url' => 'vertical_logo',
            'logo_horizontal_url' => 'horizontal_logo',
            'placeholder_image_url' => 'default_image',
            'login_banner_url' => 'auth_banner',
        ];

        foreach ($allInputs as $inputName => $value) {
            $name = $keyMap[$inputName] ?? $inputName;
            $type = 'text';

            if ($request->hasFile($inputName)) {
                $type = 'file';
                $folder = in_array($name, ['favicon']) ? $this->faviconFolder : $this->logoFolder;
                $existingSetting = Setting::where('name', $name)->first();
                $existingPath = $existingSetting ? $existingSetting->value : null;
                $value = FileService::compressAndReplace($request->file($inputName), $folder, $existingPath);
            } elseif (in_array($name, ['maintaince_mode', 'refund_enabled', 'force_update'])) {
                $type = 'boolean';
                $value = in_array($value, [1, '1', true, 'true'], true) ? 1 : 0;
            } elseif (in_array($name, ['refund_period_days', 'solo_commission_fee', 'team_commission_fee'])) {
                $type = 'number';
            } elseif ($name === 'timezone') {
                $type = 'string';
            }

            $settingsData[] = [
                'name' => $name,
                'value' => $value,
                'type' => $type,
            ];
        }

        if (!empty($settingsData)) {
            Setting::upsert($settingsData, ['name']);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
        }

        if ($request->has('app_name') || $request->has('site_name')) {
            $appName = $request->input('app_name') ?? $request->input('site_name');
            HelperService::changeEnv(['APP_NAME' => $appName]);
        }

        if ($request->has('timezone') && !empty($request->input('timezone'))) {
            try {
                date_default_timezone_set($request->input('timezone'));
                config(['app.timezone' => $request->input('timezone')]);
                HelperService::changeEnv(['APP_TIMEZONE' => $request->input('timezone')]);
            } catch (\Exception $e) {
                // Ignore timezone errors
            }
        }

        return $this->jsonSuccess('Web Settings updated successfully');
    }

    /**
     * Get SEO Settings
     */
    public function getSeoSettings(Request $request)
    {
        $this->checkPermission('settings-system-list');
        $settings = HelperService::systemSettings([
            'schema_name',
            'meta_title',
            'meta_description',
            'meta_keywords',
        ]);
        return $this->jsonSuccess('SEO Settings retrieved successfully', $settings);
    }

    /**
     * Update SEO Settings
     */
    public function updateSeoSettings(Request $request)
    {
        $this->checkPermission('settings-system-edit');
        
        $fields = [
            'schema_name' => 'text',
            'meta_title' => 'text',
            'meta_description' => 'text',
            'meta_keywords' => 'text',
        ];

        $settingsData = [];
        foreach ($fields as $name => $type) {
            if ($request->has($name)) {
                $settingsData[] = [
                    'name' => $name,
                    'value' => $request->input($name),
                    'type' => $type,
                ];
            }
        }

        if (!empty($settingsData)) {
            Setting::upsert($settingsData, ['name']);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
        }

        return $this->jsonSuccess('SEO Settings updated successfully');
    }
}
