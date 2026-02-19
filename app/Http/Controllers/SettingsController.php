<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SocialMedia;
use App\Models\User;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Intl\Currencies;
use Throwable;

class SettingsController extends Controller
{
    private $uploadFolder = 'settings';

    private $socialMediaFolder = 'social-media';

    /**
     * Display the system settings form.
     *
     * @return \Illuminate\View\View
     */
    public function system()
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list', 'manage_settings']);
        // Ensure system_version exists in database
        $systemVersion = Setting::firstOrCreate(['name' => 'system_version'], ['value' => '1.0.0', 'type' => 'string']);

        $settings = HelperService::systemSettings([
            'app_name',
            'website_url',
            'announcement_bar',
            'favicon',
            'vertical_logo',
            'horizontal_logo',
            'placeholder_image',
            'login_banner_image',
            'contact_address',
            'contact_email',
            'contact_phone',
            'system_color',
            'system_light_color',
            'hover_color',
            'footer_description',
            'website_copyright',
            'schema',
            'currency_code',
            'currency_symbol',
            'instructor_mode',
            'individual_admin_commission',
            'team_admin_commission',
            'max_video_upload_size',
            'maintaince_mode',
            'system_version',
            'refund_enabled',
            'refund_period_days',
            'timezone',
        ]);
        $socialMedias = SocialMedia::all();
        $listOfCurrencies = HelperService::currencyCode();

        return view('settings.system-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
            'socialMedias' => $socialMedias,
            'listOfCurrencies' => $listOfCurrencies,
        ]);
    }

    /**
     * Update the system settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-system-edit');
        $validator = Validator::make($request->all(), [
            'app_name' => 'required|string|max:255',
            'website_url' => 'nullable|url|max:255',
            'announcement_bar' => 'nullable|string|max:500',
            'favicon' => 'nullable|mimetypes:image/x-icon,image/vnd.microsoft.icon,image/png,image/jpeg|max:2048',
            'vertical_logo' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml|max:2048',
            'horizontal_logo' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml|max:2048',
            'placeholder_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/webp|max:2048',
            'login_banner_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/webp|max:2048',
            'contact_address' => 'nullable|string|max:500',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'system_color' => 'nullable|string',
            'system_light_color' => 'nullable|string',
            'hover_color' => 'nullable|string',
            'footer_description' => 'nullable|string|max:1000',
            'website_copyright' => 'nullable|string|max:1000',
            'schema' => 'nullable|string|regex:/^[a-z]*$/|max:255',
            'social_media_data' => 'nullable|array',
            'social_media_data.*.id' => 'nullable|integer|exists:social_medias,id',
            'social_media_data.*.name' => 'required|string',
            'social_media_data.*.icon' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml|max:2048',
            'social_media_data.*.url' => 'required|url|max:500',
            'currency_code' => 'required|string',
            'currency_symbol' => 'required|string',
            'instructor_mode' => 'required|in:single,multi',
            'individual_admin_commission' => 'required|numeric|min:0|max:100',
            'team_admin_commission' => 'required|numeric|min:0|max:100',
            'max_video_upload_size' => 'nullable|numeric|min:1',
            'maintaince_mode' => 'nullable|boolean',
            'refund_enabled' => 'nullable|boolean',
            'refund_period_days' => 'nullable|integer|min:0',
            'timezone' => 'nullable|string|timezone',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->except(['_token', 'social_media_data', 'system_version']);
            $settings = HelperService::systemSettings([
                'favicon',
                'vertical_logo',
                'horizontal_logo',
                'placeholder_image',
                'login_banner_image',
                'system_color',
                'rgb_color',
            ]);

            $settingsData = [];
            foreach ($data as $name => $value) {
                // Handle file uploads
                if ($request->hasFile($name)) {
                    $file = $request->file($name);
                    $path = FileService::compressAndReplace($file, $this->uploadFolder, $settings[$name]);
                    $value = $path;
                }

                // Convert schema to lowercase and remove non-alphabetic characters
                if ($name === 'schema' && !empty($value)) {
                    $value = strtolower(preg_replace('/[^a-z]/', '', (string) $value));
                }

                // Handle maintaince_mode - ensure boolean value is properly saved
                if ($name === 'maintaince_mode') {
                    // Convert to boolean: '0', 0, false, null, '' -> 0, everything else -> 1
                    $value = $value == '1' || $value === 1 || $value === true || $value === 'true' ? 1 : 0;
                    $type = 'boolean';
                } elseif ($name === 'refund_enabled') {
                    // Handle refund_enabled - ensure boolean value is properly saved
                    $value = $value == '1' || $value === 1 || $value === true || $value === 'true' ? 1 : 0;
                    $type = 'boolean';
                } elseif ($name === 'refund_period_days') {
                    // Handle refund_period_days - ensure it's a number
                    $type = 'number';
                } elseif ($name === 'timezone') {
                    // Handle timezone - ensure it's a string
                    $type = 'string';
                } else {
                    // Determine the type based on the input
                    $type = 'text';
                    if ($request->hasFile($name)) {
                        $type = 'file';
                    } elseif (is_bool($value)) {
                        $type = 'boolean';
                    } elseif (is_numeric($value)) {
                        $type = 'number';
                    }
                }
                $settingsData[] = ['name' => $name, 'value' => $value, 'type' => $type];
            }

            if (!empty($settingsData)) {
                Setting::upsert($settingsData, ['name']);
            }

            $envUpdate = [
                'APP_NAME' => $request->app_name,
            ];
            // Update Vales in ENV
            if ($envUpdate) {
                HelperService::changeEnv($envUpdate);
            }

            if (!empty($request->social_media_data)) {
                foreach ($request->social_media_data as $socialMedia) {
                    $iconExists = null;
                    $iconPath = null;

                    // Get existing icon if updating
                    if (!empty($socialMedia['id'])) {
                        $existingSocialMedia = SocialMedia::find($socialMedia['id']);
                        if ($existingSocialMedia) {
                            $iconExists = $existingSocialMedia->getRawOriginal('icon');
                            $iconPath = $iconExists; // Preserve existing icon by default
                        }
                    }

                    // Handle new icon upload
                    if (isset($socialMedia['icon']) && is_file($socialMedia['icon'])) {
                        $iconPath = FileService::compressAndReplace(
                            $socialMedia['icon'],
                            $this->socialMediaFolder,
                            $iconExists,
                        );
                    }

                    // Prepare data array with consistent structure
                    $data = [
                        'name' => $socialMedia['name'],
                        'url' => $socialMedia['url'] ?? null,
                        'icon' => $iconPath,
                    ];

                    // Update or create
                    if (!empty($socialMedia['id'])) {
                        // Update existing
                        SocialMedia::where('id', $socialMedia['id'])->update($data);
                    } else {
                        // Create new
                        SocialMedia::create($data);
                    }
                }
            }

            // Handle instructor mode changes
            if ($request->has('instructor_mode') && $request->instructor_mode === 'single') {
                $this->ensureAdminHasInstructorPermissions();
            }

            // Remove Cache - Clear settings cache to ensure currency symbol updates immediately
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            // Force cache refresh by getting fresh settings
            HelperService::systemSettings(['currency_symbol', 'currency_code']);

            // Update application timezone if timezone setting was updated
            if ($request->has('timezone') && !empty($request->timezone)) {
                try {
                    date_default_timezone_set($request->timezone);
                    config(['app.timezone' => $request->timezone]);
                } catch (\Exception $e) {
                    // Log error but don't fail the update
                    \Log::warning('Failed to set timezone: ' . $e->getMessage());
                }
            }

            ResponseService::successResponse('Settings updated successfully');
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating system settings');
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
        ResponseService::noPermissionThenRedirect('settings-firebase-list');
        $settings = HelperService::systemSettings([
            'firebase_api_key',
            'firebase_auth_domain',
            'firebase_project_id',
            'firebase_storage_bucket',
            'firebase_messaging_sender_id',
            'firebase_app_id',
            'firebase_measurement_id',
            'firebase_service_file',
        ]);
        if (!empty($settings['firebase_service_file'])) {
            $firebaseServiceFileExists = FileService::checkFileExists($settings['firebase_service_file']);
            if ($firebaseServiceFileExists) {
                $settings['firebase_service_file'] = FileService::getFilePath($settings['firebase_service_file']);
            }
        } else {
            $firebaseServiceFileExists = false;
        }

        return view('settings.firebase-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
            'firebaseServiceFileExists' => $firebaseServiceFileExists,
        ]);
    }

    /**
     * Update the firebase settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateFirebase(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-firebase-edit');
        $validator = Validator::make($request->all(), [
            'firebase_service_file' => 'nullable|file|mimes:json|max:2048',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->except(['_token', 'firebase_service_file']);
            $firebaseFilePath = HelperService::systemSettings('firebase_service_file');

            // Get Settings Data
            $settingArray = [];
            foreach ($data as $name => $value) {
                $settingArray[] = ['name' => $name, 'value' => $value, 'type' => 'text'];
            }
            // Upload Firebase Service File
            if ($request->hasFile('firebase_service_file')) {
                $file = $request->file('firebase_service_file');
                $path = FileService::replace($file, 'firebase', $firebaseFilePath);
                $settingArray[] = ['name' => 'firebase_service_file', 'value' => $path, 'type' => 'file'];
            }
            // Update Settings Data
            Setting::upsert($settingArray, ['name']);
            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            $firebaseFilePath = HelperService::systemSettings('firebase_service_file');

            // Update Vales in ENV
            $envUpdate = [
                'FIREBASE_CREDENTIALS' => $firebaseFilePath,
            ];
            if ($envUpdate) {
                HelperService::changeEnv($envUpdate);
            }

            ResponseService::successResponse('Firebase settings updated successfully');
        } catch (Exception) {
            ResponseService::errorResponse('Something went wrong');
        }
    }

    /**
     * Display the refund settings form.
     *
     * @return \Illuminate\View\View
     */
    public function refund()
    {
        ResponseService::noPermissionThenRedirect('settings-refund-list');
        $settings = HelperService::systemSettings(['refund_policy', 'refund_period_days', 'refund_enabled']);

        return view('settings.refund-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the refund settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRefund(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-refund-edit');
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
                } elseif ($name == 'refund_enabled') {
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

    /**
     * Destroy the social media.
     */
    public function destroySocialMedia($id)
    {
        $socialMedia = SocialMedia::find($id);
        if (!empty($socialMedia)) {
            $socialMedia->forceDelete();
        }
        ResponseService::successResponse('Social media deleted successfully');
    }

    /**
     * Display the instructor terms settings form.
     *
     * @return \Illuminate\View\View
     */
    public function instructorTerms()
    {
        ResponseService::noPermissionThenRedirect('settings-instructor-terms-list');
        $settings = HelperService::systemSettings(['individual_instructor_terms', 'team_instructor_terms']);

        return view('settings.instructor-terms-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the instructor terms settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateInstructorTerms(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-instructor-terms-edit');
        $validator = Validator::make($request->all(), [
            'individual_instructor_terms' => 'required|string',
            'team_instructor_terms' => 'required|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $settingsData = [
                [
                    'name' => 'individual_instructor_terms',
                    'value' => $request->individual_instructor_terms,
                    'type' => 'text',
                ],
                [
                    'name' => 'team_instructor_terms',
                    'value' => $request->team_instructor_terms,
                    'type' => 'text',
                ],
            ];

            Setting::upsert($settingsData, ['name']);

            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            ResponseService::successResponse('Instructor terms and conditions updated successfully');
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating instructor terms settings');
            ResponseService::errorResponse('Something went wrong');
        }
    }

    /**
     * Display the app settings form.
     *
     * @return \Illuminate\View\View
     */
    public function appSettings()
    {
        ResponseService::noPermissionThenRedirect('settings-app-list');
        $settings = HelperService::systemSettings([
            'playstore_url',
            'appstore_url',
            'android_version',
            'ios_version',
            'app_version',
            'force_update',
        ]);

        return view('settings.app-settings', [
            'type_menu' => 'app-settings',
            'settings' => $settings,
        ]);
    }

    public function updateAppSettings(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-app-edit');
        $validator = Validator::make($request->all(), [
            'playstore_url' => 'nullable|url',
            'appstore_url' => 'nullable|url',
            'android_version' => 'nullable|string',
            'ios_version' => 'nullable|string',
            'app_version' => 'nullable|string',
            'force_update' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $settingsData = [
                [
                    'name' => 'playstore_url',
                    'value' => $request->playstore_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'appstore_url',
                    'value' => $request->appstore_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'android_version',
                    'value' => $request->android_version,
                    'type' => 'text',
                ],
                [
                    'name' => 'ios_version',
                    'value' => $request->ios_version,
                    'type' => 'text',
                ],
                [
                    'name' => 'app_version',
                    'value' => $request->app_version,
                    'type' => 'text',
                ],
                [
                    'name' => 'force_update',
                    'value' => $request->force_update,
                    'type' => 'boolean',
                ],
            ];
            Setting::upsert($settingsData, ['name']);

            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            ResponseService::successResponse('App settings updated successfully');
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating app settings');
            ResponseService::errorResponse('Something went wrong');
        }
    }

    public function getCurrencySymbol(Request $request)
    {
        try {
            $countryCode = $request->country_code;
            $symbol = Currencies::getSymbol($countryCode);
            ResponseService::successResponse('', $symbol);
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error getting currency symbol');
            ResponseService::errorResponse('Something went wrong');
        }
    }

    /**
     * Ensure admin users have instructor permissions when in single instructor mode
     */
    private function ensureAdminHasInstructorPermissions()
    {
        try {
            // Get all admin users
            $adminUsers = User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();

            foreach ($adminUsers as $admin) {
                // Give admin users instructor role as well (for single instructor mode)
                if ($admin->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                    continue;
                }

                $admin->assignRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            }
        } catch (Exception $e) {
            // Log error but don't fail the main operation
            ResponseService::logError($e, 'Error assigning instructor permissions to admin');
        }
    }

    public function paymentGateway()
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-payment-gateway-list', 'manage_settings']);
        $settings = HelperService::systemSettings(['payment_gateway']);
        $razorpayPaymentGateway = HelperService::systemSettings([
            'razorpay_status',
            'razorpay_api_key',
            'razorpay_secret_key',
            'razorpay_webhook_url',
            'razorpay_webhook_secret_key',
        ]);
        $stripePaymentGateway = HelperService::systemSettings([
            'stripe_status',
            'stripe_publishable_key',
            'stripe_secret_key',
            'stripe_webhook_url',
            'stripe_webhook_secret',
            'stripe_success_url',
            'stripe_cancel_url',
            'stripe_currency',
        ]);
        $flutterwavePaymentGateway = HelperService::systemSettings([
            'flutterwave_status',
            'flutterwave_public_key',
            'flutterwave_secret_key',
            'flutterwave_webhook_url',
            'flutterwave_webhook_secret',
            'flutterwave_encryption_key',
            'flutterwave_currency',
        ]);
        $kashierPaymentGateway = HelperService::systemSettings([
            'kashier_merchant_id',
            'kashier_api_key',
            'kashier_webhook_secret',
            'kashier_mode',
        ]);

        return view('settings.payment-gateway-settings', [
            'type_menu' => 'settings',
            'settings' => $settings,
            'razorpayPaymentGateway' => $razorpayPaymentGateway,
            'stripePaymentGateway' => $stripePaymentGateway,
            'flutterwavePaymentGateway' => $flutterwavePaymentGateway,
            'kashierPaymentGateway' => $kashierPaymentGateway,
        ]);
    }

    public function updatePaymentGateway(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-payment-gateway-edit');
        try {
            $validator = Validator::make($request->all(), [
                'razorpay_status' => 'nullable|boolean',
                'razorpay_api_key' => 'required_if:razorpay_status,1|nullable|string',
                'razorpay_secret_key' => 'required_if:razorpay_status,1|nullable|string',
                'razorpay_webhook_url' => 'required_if:razorpay_status,1|nullable|string',
                'razorpay_webhook_secret_key' => 'required_if:razorpay_status,1|nullable|string',
                'stripe_status' => 'nullable|boolean',
                'stripe_publishable_key' => 'required_if:stripe_status,1|nullable|string',
                'stripe_secret_key' => 'required_if:stripe_status,1|nullable|string',
                'stripe_webhook_url' => 'nullable|string',
                'stripe_webhook_secret' => 'nullable|string',
                'stripe_success_url' => 'nullable|string',
                'stripe_cancel_url' => 'nullable|string',
                'stripe_currency' => 'required_if:stripe_status,1|nullable|string',
                'flutterwave_status' => 'nullable|boolean',
                'flutterwave_public_key' => 'required_if:flutterwave_status,1|nullable|string',
                'flutterwave_secret_key' => 'required_if:flutterwave_status,1|nullable|string',
                'flutterwave_webhook_url' => 'nullable|string',
                'flutterwave_webhook_secret' => 'nullable|string',
                'flutterwave_encryption_key' => 'required_if:flutterwave_status,1|nullable|string',
                'flutterwave_currency' => 'required_if:flutterwave_status,1|nullable|string',
                'kashier_merchant_id' => 'nullable|string',
                'kashier_api_key' => 'nullable|string',
                'kashier_webhook_secret' => 'nullable|string',
                'kashier_mode' => 'nullable|in:test,live',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            // Validate that at least one payment gateway is enabled
            $razorpayEnabled =
                $request->has('razorpay_status')
                && ($request->razorpay_status == 1 || $request->razorpay_status == '1');
            $stripeEnabled =
                $request->has('stripe_status') && ($request->stripe_status == 1 || $request->stripe_status == '1');
            $flutterwaveEnabled =
                $request->has('flutterwave_status')
                && ($request->flutterwave_status == 1 || $request->flutterwave_status == '1');

            if (!$razorpayEnabled && !$stripeEnabled && !$flutterwaveEnabled) {
                return ResponseService::validationError('At least one payment gateway must be enabled.');
            }

            $settingsData = [
                // Razorpay Settings
                [
                    'name' => 'razorpay_status',
                    'value' => $request->has('razorpay_status') ? 1 : 0,
                    'type' => 'boolean',
                ],
                [
                    'name' => 'razorpay_api_key',
                    'value' => $request->razorpay_api_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'razorpay_secret_key',
                    'value' => $request->razorpay_secret_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'razorpay_webhook_url',
                    'value' => $request->razorpay_webhook_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'razorpay_webhook_secret_key',
                    'value' => $request->razorpay_webhook_secret_key,
                    'type' => 'text',
                ],
                // Stripe Settings
                [
                    'name' => 'stripe_status',
                    'value' => $request->has('stripe_status') ? 1 : 0,
                    'type' => 'boolean',
                ],
                [
                    'name' => 'stripe_publishable_key',
                    'value' => $request->stripe_publishable_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_secret_key',
                    'value' => $request->stripe_secret_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_webhook_url',
                    'value' => $request->stripe_webhook_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_webhook_secret',
                    'value' => $request->stripe_webhook_secret,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_success_url',
                    'value' => $request->stripe_success_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_cancel_url',
                    'value' => $request->stripe_cancel_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'stripe_currency',
                    'value' => $request->stripe_currency,
                    'type' => 'text',
                ],
                // Flutterwave Settings
                [
                    'name' => 'flutterwave_status',
                    'value' => $request->has('flutterwave_status') ? 1 : 0,
                    'type' => 'boolean',
                ],
                [
                    'name' => 'flutterwave_public_key',
                    'value' => $request->flutterwave_public_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'flutterwave_secret_key',
                    'value' => $request->flutterwave_secret_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'flutterwave_webhook_url',
                    'value' => $request->flutterwave_webhook_url,
                    'type' => 'text',
                ],
                [
                    'name' => 'flutterwave_webhook_secret',
                    'value' => $request->flutterwave_webhook_secret,
                    'type' => 'text',
                ],
                [
                    'name' => 'flutterwave_encryption_key',
                    'value' => $request->flutterwave_encryption_key,
                    'type' => 'text',
                ],
                [
                    'name' => 'flutterwave_currency',
                    'value' => $request->flutterwave_currency,
                    'type' => 'text',
                ],
                // Kashier Settings (subscription payments)
                [
                    'name' => 'kashier_merchant_id',
                    'value' => $request->kashier_merchant_id ?? '',
                    'type' => 'text',
                ],
                [
                    'name' => 'kashier_api_key',
                    'value' => $request->kashier_api_key ?? '',
                    'type' => 'text',
                ],
                [
                    'name' => 'kashier_webhook_secret',
                    'value' => $request->kashier_webhook_secret ?? '',
                    'type' => 'text',
                ],
                [
                    'name' => 'kashier_mode',
                    'value' => $request->kashier_mode ?? 'test',
                    'type' => 'text',
                ],
            ];

            Setting::upsert($settingsData, ['name']);
            // Remove Cache
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
            ResponseService::successResponse('Payment gateway settings updated successfully');
        } catch (Exception $e) {
            ResponseService::logError($e, 'Error updating payment gateway');
            ResponseService::errorResponse('Something went wrong');
        }
    }

    public function page()
    {
        // ResponseService::noPermissionThenSendJson('settings-update');
        $type_menu = last(request()->segments());
        $settings = CachingService::getSystemSettings()->toArray();
        if (!empty($settings['place_api_key']) && config('app.demo_mode')) {
            $settings['place_api_key'] = '**************************';
        }

        $stripe_currencies = [
            'USD',
            'AED',
            'AFN',
            'ALL',
            'AMD',
            'ANG',
            'AOA',
            'ARS',
            'AUD',
            'AWG',
            'AZN',
            'BAM',
            'BBD',
            'BDT',
            'BGN',
            'BIF',
            'BMD',
            'BND',
            'BOB',
            'BRL',
            'BSD',
            'BWP',
            'BYN',
            'BZD',
            'CAD',
            'CDF',
            'CHF',
            'CLP',
            'CNY',
            'COP',
            'CRC',
            'CVE',
            'CZK',
            'DJF',
            'DKK',
            'DOP',
            'DZD',
            'EGP',
            'ETB',
            'EUR',
            'FJD',
            'FKP',
            'GBP',
            'GEL',
            'GIP',
            'GMD',
            'GNF',
            'GTQ',
            'GYD',
            'HKD',
            'HNL',
            'HTG',
            'HUF',
            'IDR',
            'ILS',
            'INR',
            'ISK',
            'JMD',
            'JPY',
            'KES',
            'KGS',
            'KHR',
            'KMF',
            'KRW',
            'KYD',
            'KZT',
            'LAK',
            'LBP',
            'LKR',
            'LRD',
            'LSL',
            'MAD',
            'MDL',
            'MGA',
            'MKD',
            'MMK',
            'MNT',
            'MOP',
            'MRO',
            'MUR',
            'MVR',
            'MWK',
            'MXN',
            'MYR',
            'MZN',
            'NAD',
            'NGN',
            'NIO',
            'NOK',
            'NPR',
            'NZD',
            'PAB',
            'PEN',
            'PGK',
            'PHP',
            'PKR',
            'PLN',
            'PYG',
            'QAR',
            'RON',
            'RSD',
            'RUB',
            'RWF',
            'SAR',
            'SBD',
            'SCR',
            'SEK',
            'SGD',
            'SHP',
            'SLE',
            'SOS',
            'SRD',
            'STD',
            'SZL',
            'THB',
            'TJS',
            'TOP',
            'TTD',
            'TWD',
            'TZS',
            'UAH',
            'UGX',
            'UYU',
            'UZS',
            'VND',
            'VUV',
            'WST',
            'XAF',
            'XCD',
            'XOF',
            'XPF',
            'YER',
            'ZAR',
            'ZMW',
        ];
        $languages = CachingService::getLanguages();
        $translations = $this->getSettingTranslations();
        $languages_translate = CachingService::getLanguages()->where('code', '!=', 'en')->values();

        return view('settings.' . $type_menu, compact(
            'settings',
            'type_menu',
            'languages',
            'stripe_currencies',
            'languages_translate',
            'translations',
        ));
    }

    private function getSettingTranslations()
    {
        $settings = Setting::get();

        $translations = [];

        return $translations;
    }

    /**
     * Display the Why Choose Us settings form.
     *
     * @return \Illuminate\View\View
     */
    public function whyChooseUs()
    {
        $settings = HelperService::systemSettings([
            'why_choose_us_title',
            'why_choose_us_description',
            'why_choose_us_point_1',
            'why_choose_us_point_2',
            'why_choose_us_point_3',
            'why_choose_us_point_4',
            'why_choose_us_point_5',
            'why_choose_us_image',
            'why_choose_us_button_text',
            'why_choose_us_button_link',
        ]);

        return view('settings.why-choose-us', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the Why Choose Us settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateWhyChooseUs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'why_choose_us_title' => 'required|string|max:255',
            'why_choose_us_description' => 'nullable|string',
            'why_choose_us_point_1' => 'nullable|string|max:255',
            'why_choose_us_point_2' => 'nullable|string|max:255',
            'why_choose_us_point_3' => 'nullable|string|max:255',
            'why_choose_us_point_4' => 'nullable|string|max:255',
            'why_choose_us_point_5' => 'nullable|string|max:255',
            'why_choose_us_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml,image/webp|max:2048',
            'why_choose_us_button_text' => 'nullable|string|max:100',
            'why_choose_us_button_link' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Get existing image setting to preserve it if not updated
            $settings = HelperService::systemSettings(['why_choose_us_image']);
            $settingsData = [];

            // Define all fields that should be updated
            $fieldsToUpdate = [
                'why_choose_us_title',
                'why_choose_us_description',
                'why_choose_us_point_1',
                'why_choose_us_point_2',
                'why_choose_us_point_3',
                'why_choose_us_point_4',
                'why_choose_us_point_5',
                'why_choose_us_image',
                'why_choose_us_button_text',
                'why_choose_us_button_link',
            ];

            foreach ($fieldsToUpdate as $name) {
                // Handle file upload for image
                if ($request->hasFile($name) && $name === 'why_choose_us_image') {
                    $file = $request->file($name);
                    $path = FileService::compressAndReplace(
                        $file,
                        'why-choose-us',
                        $settings['why_choose_us_image'] ?? null,
                    );
                    $value = $path;
                    $type = 'file';
                } else {
                    // Skip if it's image field but no file uploaded (preserve existing)
                    if ($name === 'why_choose_us_image' && !$request->hasFile($name)) {
                        continue;
                    }

                    // Get value from request, default to empty string if not present
                    $value = $request->input($name, '');
                    $type = 'text';
                    if ($name === 'why_choose_us_description') {
                        $type = 'textarea';
                    }
                }

                $settingsData[] = [
                    'name' => $name,
                    'value' => $value,
                    'type' => $type,
                ];
            }

            if (!empty($settingsData)) {
                Setting::upsert($settingsData, ['name']);
            }

            // Clear cache - use the same cache key as HelperService
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            return ResponseService::successResponse('Why Choose Us settings updated successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Display the Become Instructor settings form.
     *
     * @return \Illuminate\View\View
     */
    public function becomeInstructor()
    {
        // In single instructor mode, redirect to dashboard
        if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
            return redirect()
                ->route('settings.system')
                ->with('info', 'Become Instructor settings are disabled in Single Instructor mode.');
        }

        $settings = HelperService::systemSettings([
            'become_instructor_title',
            'become_instructor_description',
            'become_instructor_button_text',
            'become_instructor_button_link',
            'become_instructor_step_1_title',
            'become_instructor_step_1_description',
            'become_instructor_step_1_image',
            'become_instructor_step_2_title',
            'become_instructor_step_2_description',
            'become_instructor_step_2_image',
            'become_instructor_step_3_title',
            'become_instructor_step_3_description',
            'become_instructor_step_3_image',
            'become_instructor_step_4_title',
            'become_instructor_step_4_description',
            'become_instructor_step_4_image',
        ]);

        return view('settings.become-instructor', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the Become Instructor settings.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateBecomeInstructor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'become_instructor_title' => 'required|string|max:255',
            'become_instructor_description' => 'nullable|string',
            'become_instructor_button_text' => 'nullable|string|max:100',
            'become_instructor_button_link' => 'nullable|string|max:255',
            'become_instructor_step_1_title' => 'nullable|string|max:255',
            'become_instructor_step_1_description' => 'nullable|string',
            'become_instructor_step_1_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml,image/webp|max:2048',
            'become_instructor_step_2_title' => 'nullable|string|max:255',
            'become_instructor_step_2_description' => 'nullable|string',
            'become_instructor_step_2_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml,image/webp|max:2048',
            'become_instructor_step_3_title' => 'nullable|string|max:255',
            'become_instructor_step_3_description' => 'nullable|string',
            'become_instructor_step_3_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml,image/webp|max:2048',
            'become_instructor_step_4_title' => 'nullable|string|max:255',
            'become_instructor_step_4_description' => 'nullable|string',
            'become_instructor_step_4_image' => 'nullable|mimetypes:image/jpeg,image/png,image/jpg,image/svg+xml,image/webp|max:2048',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Get existing image settings to preserve them if not updated
            $settings = HelperService::systemSettings([
                'become_instructor_step_1_image',
                'become_instructor_step_2_image',
                'become_instructor_step_3_image',
                'become_instructor_step_4_image',
            ]);

            $settingsData = [];

            // Define all fields that should be updated
            $fieldsToUpdate = [
                'become_instructor_title',
                'become_instructor_description',
                'become_instructor_button_text',
                'become_instructor_button_link',
                'become_instructor_step_1_title',
                'become_instructor_step_1_description',
                'become_instructor_step_1_image',
                'become_instructor_step_2_title',
                'become_instructor_step_2_description',
                'become_instructor_step_2_image',
                'become_instructor_step_3_title',
                'become_instructor_step_3_description',
                'become_instructor_step_3_image',
                'become_instructor_step_4_title',
                'become_instructor_step_4_description',
                'become_instructor_step_4_image',
            ];

            foreach ($fieldsToUpdate as $name) {
                // Handle file uploads for step images
                if ($request->hasFile($name) && str_contains($name, '_image')) {
                    $file = $request->file($name);
                    $path = FileService::compressAndReplace($file, 'become-instructor', $settings[$name] ?? null);
                    $value = $path;
                    $type = 'file';
                } else {
                    // Skip if it's image field but no file uploaded (preserve existing)
                    if (str_contains($name, '_image') && !$request->hasFile($name)) {
                        continue;
                    }

                    // Get value from request, default to empty string if not present
                    $value = $request->input($name, '');
                    $type = 'text';
                    if ($name === 'become_instructor_description' || str_contains($name, '_description')) {
                        $type = 'textarea';
                    }
                }

                $settingsData[] = [
                    'name' => $name,
                    'value' => $value,
                    'type' => $type,
                ];
            }

            if (!empty($settingsData)) {
                Setting::upsert($settingsData, ['name']);
            }

            // Clear cache - use the same cache key as HelperService
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            return ResponseService::successResponse('Become Instructor settings updated successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Display the system update form.
     *
     * @return \Illuminate\View\View
     */
    public function systemUpdate()
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list']);
        $settings = HelperService::systemSettings(['system_version']);

        return view('settings.system-update', [
            'type_menu' => 'settings',
            'settings' => $settings,
        ]);
    }

    /**
     * Update the system.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateSystem(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-system-list');

        $validator = Validator::make($request->all(), [
            'purchase_code' => 'required|string|max:255',
            'update_file' => 'required|file|mimes:zip',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            // Handle update file upload
            if ($request->hasFile('update_file')) {
                $file = $request->file('update_file');
                $purchaseCode = $request->input('purchase_code');

                $app_url = url('/');
                $app_url = preg_replace('#^https?://#i', '', $app_url);

                // Get current version using HelperService
                $settings = HelperService::systemSettings(['system_version']);

                $current_version = $settings['system_version'] ?? '1.0.0';

                // Validate purchase code
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL =>
                        'https://validator.wrteam.in/elms_validator?purchase_code='
                        . $request->purchase_code
                        . '&domain_url='
                        . $app_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                ]);

                $response = curl_exec($curl);

                curl_close($curl);
                $response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                if ($response['error']) {
                    return ResponseService::errorResponse($response['message']);
                }

                // Set destination path
                $destinationPath = storage_path('app/updates');

                if (!is_dir($destinationPath)) {
                    if (!mkdir($destinationPath, 0777, true)) {
                        return ResponseService::errorResponse('Permission Error while creating Temp Directory');
                    }
                }

                // zip upload
                $zipfile = $request->file('update_file');
                $fileName = $zipfile->getClientOriginalName();
                $zipfile->move($destinationPath, $fileName);

                // This will add public in path
                $target_path = base_path() . DIRECTORY_SEPARATOR;
                $zip = new \ZipArchive();
                $filePath = $destinationPath . '/' . $fileName;
                $zipStatus = $zip->open($filePath);

                if ($zipStatus !== true) {
                    return ResponseService::errorResponse('something_wrong_try_again');
                }

                $zip->extractTo($destinationPath);
                $zip->close();
                unlink($filePath);

                $ver_file = $destinationPath . '/version_info.php';
                $source_path = $destinationPath . '/source_code.zip';

                if (!file_exists($ver_file) && !file_exists($source_path)) {
                    return ResponseService::errorResponse('Zip File is not Uploaded to Correct Path');
                }

                $ver_file1 = $target_path . 'version_info.php';
                $source_path1 = $target_path . 'source_code.zip';

                // MOVE File
                if (!rename($ver_file, $ver_file1) || !rename($source_path, $source_path1)) {
                    return ResponseService::errorResponse('Error Occurred while moving a Zip File');
                }

                $version_file = require $ver_file1;

                if ($current_version == $version_file['update_version']) {
                    unlink($ver_file1);
                    unlink($source_path1);

                    return ResponseService::errorResponse('System is already upto date');
                }

                if ($current_version != $version_file['current_version']) {
                    unlink($ver_file1);
                    unlink($source_path1);

                    return ResponseService::errorResponse($current_version
                    . ' '
                    . trans('Please update nearest version'));
                }

                $zip1 = new \ZipArchive();
                $zipFile1 = $zip1->open($source_path1);

                if ($zipFile1 !== true) {
                    unlink($ver_file1);
                    unlink($source_path1);

                    return ResponseService::errorResponse('Source Code Zip Extraction Failed');
                }

                $zip1->extractTo($target_path);
                $zip1->close();

                \Illuminate\Support\Facades\Artisan::call('migrate');
                \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'SystemUpgradeSeeder']);
                \Illuminate\Support\Facades\Artisan::call('optimize:clear');

                unlink($source_path1);
                unlink($ver_file1);

                Setting::where('name', 'system_version')->update([
                    'value' => $version_file['update_version'],
                ]);

                // Return JSON response for AJAX form submission
                return ResponseService::successResponse('System updated successfully.', null, ['redirect_url' => route(
                    'settings.system-update',
                )]);
            }

            return ResponseService::errorResponse('Update file is required');
        } catch (Throwable $th) {
            return ResponseService::errorResponse(exception: $th);
        }
    }
}
