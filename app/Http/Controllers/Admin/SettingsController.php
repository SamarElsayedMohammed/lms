<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Display the system settings form.
     *
     * @return \Illuminate\View\View
     */
    public function system()
    {
        $settings = CachingService::getSystemSettings(['app_name', 'favicon', 'vertical_logo', 'horizontal_logo']);
        return view('pages.system-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the system settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'app_name' => 'required|string|max:255',
            'favicon' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'vertical_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'horizontal_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->except(['_token']);

            foreach ($data as $name => $value) {
                // Handle file uploads
                if ($request->hasFile($name)) {
                    $file = $request->file($name);
                    $path = FileService::compressAndUpload($file, 'settings');
                    $value = $path;
                }

                // Determine the type based on the input
                $type = 'text';
                if ($request->hasFile($name)) {
                    $type = 'file';
                } elseif (is_bool($value)) {
                    $type = 'boolean';
                } elseif (is_numeric($value)) {
                    $type = 'number';
                }
                $data = ['name' => $name, 'value' => $value, 'type' => $type];
            }
            Setting::upsert($data, ['name']);

            $envUpdate = [
                'APP_NAME' => $request->app_name,
            ];
            // Update Vales in ENV
            if ($envUpdate) {
                HelperService::changeEnv($envUpdate);
            }

            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            ResponseService::successResponse('Settings updated successfully');
        } catch (Exception) {
            ResponseService::errorResponse('Something went wrong');
        }
    }

    /**
     * Display the firebase settings form.
     *
     * @return \Illuminate\View\View
     */
    public function firebase()
    {
        $settings = CachingService::getSystemSettings([
            'firebase_api_key',
            'firebase_auth_domain',
            'firebase_project_id',
            'firebase_storage_bucket',
            'firebase_messaging_sender_id',
            'firebase_app_id',
            'firebase_measurement_id',
        ]);

        // Check if Firebase service file exists using the Storage facade
        $firebaseServiceFileExists = Storage::disk('firebase')->exists('firebase/firebase_credentials.json');

        return view('pages.firebase-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
            'firebaseServiceFileExists' => $firebaseServiceFileExists,
        ]);
    }

    /**
     * Update the firebase settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateFirebase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_api_key' => 'required|string|max:255',
            'firebase_auth_domain' => 'required|string|max:255',
            'firebase_project_id' => 'required|string|max:255',
            'firebase_storage_bucket' => 'required|string|max:255',
            'firebase_messaging_sender_id' => 'required|string|max:255',
            'firebase_app_id' => 'required|string|max:255',
            'firebase_measurement_id' => 'required|string|max:255',
            'firebase_service_file' => 'nullable|file|mimes:json|max:2048',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->except(['_token', 'firebase_service_file']);

            // Handle Firebase service account file
            if ($request->hasFile('firebase_service_file')) {
                $file = $request->file('firebase_service_file');

                try {
                    // Use Laravel's Storage API with our new Firebase disk
                    $path = Storage::disk('firebase')->putFileAs('firebase', $file, 'firebase_credentials.json');

                    // Validate that the file was stored successfully
                    if (!Storage::disk('firebase')->exists($path)) {
                        throw new Exception('File was not stored successfully');
                    }

                    // Get the full path to the stored file
                    $filePath = Storage::disk('firebase')->path($path);

                    // Update the FIREBASE_CREDENTIALS env variable
                    HelperService::changeEnv([
                        'FIREBASE_CREDENTIALS' => 'storage/app/' . $path,
                    ]);

                    // Add service file message to be combined with final success message
                    $fileUploadSuccess = true;
                } catch (Exception $e) {
                    throw new Exception('Failed to store Firebase credentials file: ' . $e->getMessage());
                }
            }

            $settingArray = [];
            foreach ($data as $name => $value) {
                $settingArray[] = ['name' => $name, 'value' => $value];
            }
            Setting::upsert($settingArray, ['name']);

            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            $successMessage = 'Firebase settings updated successfully';
            if (isset($fileUploadSuccess) && $fileUploadSuccess) {
                $successMessage .= ' and service account file uploaded successfully';
            }

            ResponseService::successResponse($successMessage);
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating Firebase settings');
            ResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Display the refund settings form.
     *
     * @return \Illuminate\View\View
     */
    public function refund()
    {
        $settings = CachingService::getSystemSettings(['refund_policy', 'refund_period_days', 'refund_enabled']);

        return view('pages.refund-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the refund settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRefund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refund_policy' => 'required|string',
            'refund_period_days' => 'required|integer|min:0',
            'refund_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->except(['_token']);

            $settingArray = [];
            foreach ($data as $name => $value) {
                if ($name == 'refund_period_days') {
                    $type = 'number';
                } else if ($name == 'refund_enabled') {
                    $type = 'boolean';
                } else {
                    $type = 'text';
                }
                $settingArray[] = ['name' => $name, 'value' => $value, 'type' => $type];
            }
            Setting::upsert($settingArray, ['name']);

            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            ResponseService::successResponse('Refund settings updated successfully');
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating refund settings');
            ResponseService::errorResponse('Error updating refund settings');
        }

        return redirect()->back();
    }
}
