<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\CustomFormField;
use App\Models\CustomFormFieldOption;
use App\Models\Faq;
use App\Models\Instructor;
use App\Models\InstructorOtherDetail;
use App\Models\InstructorPersonalDetail;
use App\Models\InstructorSocialMedia;
use App\Models\Language;
use App\Models\Page;
use App\Models\PaymentTransaction;
use App\Models\SeoSetting;
use App\Models\SocialLogin;
use App\Models\SocialMedia;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserFcmToken;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Payment\PaymentService;
use App\Support\RoleManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

trait ServesApiPublicContent
{
    public function getAppSettings(Request $request)
    {
        try {
            $generalSystemSettings = ApiService::getGeneralSystemSettings();
            $appSettings = HelperService::systemSettings([
                'playstore_url',
                'appstore_url',
                'android_version',
                'ios_version',
                'app_version',
                'maintaince_mode',
                'force_update',
                'app_name',
                'website_url',
                'announcement_bar',
                'favicon',
                'vertical_logo',
                'horizontal_logo',
                'placeholder_image',
                'contact_address',
                'contact_email',
                'contact_phone',
            ]);

            // Convert file paths to full URLs (only if not already a URL)
            $fileFields = ['favicon', 'vertical_logo', 'horizontal_logo', 'placeholder_image'];
            foreach ($fileFields as $field) {
                if (!(!empty($appSettings[$field]) && !filter_var($appSettings[$field], FILTER_VALIDATE_URL))) {
                    continue;
                }

                $appSettings[$field] = FileService::getFileUrl($appSettings[$field]);
            }

            // Get default language
            $defaultLanguage = Language::where('status', 1)->where('is_default', true)->first();

            // If no default language found, try to get English, otherwise get first active language
            if (!$defaultLanguage) {
                $defaultLanguage = Language::where('status', 1)->where('code', 'en')->first();
            }

            if (!$defaultLanguage) {
                $defaultLanguage = Language::where('status', 1)->first();
            }

            // Add default language id and code to app settings
            if ($defaultLanguage) {
                $appSettings['default_language_id'] = $defaultLanguage->id;
                $appSettings['default_language_code'] = $defaultLanguage->code;
            } else {
                $appSettings['default_language_id'] = null;
                $appSettings['default_language_code'] = 'EN';
            }

            $appSettings = array_merge($generalSystemSettings, $appSettings);
            ApiResponseService::successResponse('Data Fetched Successfully', $appSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function getWebSettings(Request $request)
    {
        try {
            $generalSystemSettings = ApiService::getGeneralSystemSettings();
            $webSettings = HelperService::systemSettings([
                'individual_instructor_terms',
                'team_instructor_terms',
                'app_name',
                'website_url',
                'announcement_bar',
                'playstore_url',
                'appstore_url',
                'favicon',
                'vertical_logo',
                'horizontal_logo',
                'placeholder_image',
                'contact_address',
                'contact_email',
                'contact_phone',
                'schema',
                'system_light_colour',
                'maintaince_mode',
                'hover_color',
                'footer_description',
                'website_copyright',
            ]);

            // Convert file paths to full URLs (only if not already a URL)
            $fileFields = ['favicon', 'vertical_logo', 'horizontal_logo', 'placeholder_image'];
            foreach ($fileFields as $field) {
                if (!(!empty($webSettings[$field]) && !filter_var($webSettings[$field], FILTER_VALIDATE_URL))) {
                    continue;
                }

                $webSettings[$field] = FileService::getFileUrl($webSettings[$field]);
            }

            // Process copyright to replace {year} placeholder
            $webSettings['website_copyright'] = HelperService::getCopyright();

            $socialMedia = SocialMedia::select('id', 'name', 'icon', 'url')->get();
            $webSettings = array_merge($generalSystemSettings, $webSettings, ['social_media' => $socialMedia]);
            ApiResponseService::successResponse('Data Fetched Successfully', $webSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function getWhyChooseUs(Request $request)
    {
        try {
            $whyChooseUsSettings = HelperService::systemSettings([
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

            // Format the response for better structure
            $formattedData = [
                'title' => $whyChooseUsSettings['why_choose_us_title'] ?? '',
                'description' => $whyChooseUsSettings['why_choose_us_description'] ?? '',
                'image' => $whyChooseUsSettings['why_choose_us_image'] ?? null,
                'button_text' => $whyChooseUsSettings['why_choose_us_button_text'] ?? '',
                'button_link' => $whyChooseUsSettings['why_choose_us_button_link'] ?? '',
                'points' => [
                    $whyChooseUsSettings['why_choose_us_point_1'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_2'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_3'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_4'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_5'] ?? '',
                ],
            ];

            return ApiResponseService::successResponse('Why Choose Us data fetched successfully', $formattedData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getWhyChooseUs Method');
            return ApiResponseService::errorResponse('Failed to retrieve Why Choose Us data');
        }
    }

    public function getBecomeInstructor(Request $request)
    {
        try {
            // In single instructor mode, return empty data
            if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                return ApiResponseService::successResponse('Become Instructor is disabled in Single Instructor mode', [
                    'title' => '',
                    'description' => '',
                    'button_text' => '',
                    'button_link' => '',
                    'steps' => [],
                ]);
            }

            $becomeInstructorSettings = HelperService::systemSettings([
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

            // Format the response for better structure
            $formattedData = [
                'title' => $becomeInstructorSettings['become_instructor_title'] ?? '',
                'description' => $becomeInstructorSettings['become_instructor_description'] ?? '',
                'button_text' => $becomeInstructorSettings['become_instructor_button_text'] ?? '',
                'button_link' => $becomeInstructorSettings['become_instructor_button_link'] ?? '',
                'steps' => [
                    [
                        'step' => 1,
                        'title' => $becomeInstructorSettings['become_instructor_step_1_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_1_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_1_image'] ?? null,
                    ],
                    [
                        'step' => 2,
                        'title' => $becomeInstructorSettings['become_instructor_step_2_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_2_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_2_image'] ?? null,
                    ],
                    [
                        'step' => 3,
                        'title' => $becomeInstructorSettings['become_instructor_step_3_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_3_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_3_image'] ?? null,
                    ],
                    [
                        'step' => 4,
                        'title' => $becomeInstructorSettings['become_instructor_step_4_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_4_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_4_image'] ?? null,
                    ],
                ],
            ];

            return ApiResponseService::successResponse('Become Instructor data fetched successfully', $formattedData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getBecomeInstructor Method');
            return ApiResponseService::errorResponse('Failed to retrieve Become Instructor data');
        }
    }

    public function getPaymentIntent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required',
            'platform_type' => 'required|in:app,web',
        ]);
        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $paymentSettings = HelperService::getActivePaymentDetails();
            if (empty($paymentSettings)) {
                ApiResponseService::validationError('None of payment method is activated');
            }

            $course = Course::where(['id' => $request->course_id, 'course_type' => 'paid', 'is_active' => 1])->first();
            if (empty($course)) {
                ApiResponseService::validationError('No course found');
            }

            $purchasedCourse = UserCourseTrack::where([
                'user_id' => Auth::user()?->id,
                'course_id' => $request->course_id,
            ])->first();
            if (!empty($purchasedCourse)) {
                ApiResponseService::validationError('You already have purchased this course');
            }

            //Add Payment Data to Payment Transactions Table
            $paymentTransactionData = PaymentTransaction::create([
                'user_id' => Auth::user()?->id,
                'course_id' => $request->course_id,
                'amount' => !empty($course->discounted_price) ? $course->discounted_price : $course->price,
                'payment_gateway' => $paymentSettings['payment_method'],
                'payment_status' => 'pending',
                'order_id' => null,
                'payment_type' => 'online',
            ]);

            $paymentIntent = PaymentService::create($paymentSettings)->createAndFormatPaymentIntent(
                round($course->price, 2),
                [
                    'payment_transaction_id' => $paymentTransactionData->id,
                    'course_id' => $course->id,
                    'user_id' => Auth::user()?->id,
                    'email' => Auth::user()?->email,
                    'platform_type' => $request->platform_type,
                    'description' => $request->description ?? $course->title,
                    'user_name' => Auth::user()->name ?? '',
                    'address_line1' => Auth::user()->address ?? '',
                    'address_city' => Auth::user()->city ?? '',
                ],
            );
            $paymentTransactionData->update(['order_id' => $paymentIntent['id']]);

            $paymentTransactionData = PaymentTransaction::findOrFail($paymentTransactionData->id);
            // Custom Array to Show as response
            $paymentGatewayDetails = [
                ...$paymentIntent,
                'payment_transaction_id' => $paymentTransactionData->id,
            ];

            DB::commit();
            ApiResponseService::successResponse('', [
                'payment_intent' => $paymentGatewayDetails,
                'payment_transaction' => $paymentTransactionData,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();
            ApiResponseService::logErrorResponse($e);
            ApiResponseService::errorResponse();
        }
    }

    public function getSystemLanguages(Request $request)
    {
        $systemType = $request->get('system_type'); // app | web | null
        $code = $request->get('code'); // optional

        if ($code) {
            // 🔹 Only requested language
            $language = Language::where('status', 1)->where('code', $code)->first();

            if (!$language) {
                return ApiResponseService::errorResponse('Language not found', [], 404);
            }

            $langData = [
                'id' => $language->id,
                'name' => $language->name,
                'code' => $language->code,
                'is_rtl' => (bool) $language->rtl,
                'is_default' => (bool) $language->is_default,
                'image' => $language->image,
            ];

            // system_type = app
            if ($systemType === 'app') {
                $file_app = resource_path("lang/{$language->code}_app.json");
                $langData['translations_app'] = File::exists($file_app) ? json_decode(File::get($file_app), true) : [];
            }
            // system_type = web
            elseif ($systemType === 'web') {
                $file_web = resource_path("lang/{$language->code}_web.json");
                $langData['translations_web'] = File::exists($file_web) ? json_decode(File::get($file_web), true) : [];
            }
            // system_type = null → include both
            else {
                $file_app = resource_path("lang/{$language->code}_app.json");
                $file_web = resource_path("lang/{$language->code}_web.json");

                $langData['translations_app'] = File::exists($file_app) ? json_decode(File::get($file_app), true) : [];

                $langData['translations_web'] = File::exists($file_web) ? json_decode(File::get($file_web), true) : [];
            }

            $result = [
                'languages' => [$langData],
            ];

            return ApiResponseService::successResponse('Language Fetched Successfully', $result);
        }

        // 🔹 If no code → fetch all
        $languages = Language::where('status', 1)->get();
        if ($languages->isEmpty()) {
            return ApiResponseService::errorResponse('No language found', [], 404);
        }

        // 🔹 Find default language
        $defaultLang =
            Language::where('status', 1)->where('is_default', true)->first() ?? Language::where('status', 1)
                ->where('code', 'en')
                ->first() ?? $languages->first();

        // 🔹 Prepare languages list with empty translations
        $formattedLanguages = $languages->map(static function ($language) use ($systemType) {
            $lang = [
                'id' => $language->id,
                'name' => $language->name,
                'code' => $language->code,
                'is_rtl' => (bool) $language->rtl,
                'is_default' => (bool) $language->is_default,
                'image' => $language->image,
            ];

            // only default_lang should have translations, so list = empty
            if ($systemType === 'app') {
                $lang['translations_app'] = [];
            } elseif ($systemType === 'web') {
                $lang['translations_web'] = [];
            } else {
                $lang['translations_app'] = [];
                $lang['translations_web'] = [];
            }

            return $lang;
        });

        // 🔹 Default language with translations
        $defaultLangData = [
            'id' => $defaultLang->id,
            'name' => $defaultLang->name,
            'code' => $defaultLang->code,
            'is_rtl' => (bool) $defaultLang->rtl,
            'is_default' => (bool) $defaultLang->is_default,
            'image' => $defaultLang->image,
        ];

        if ($systemType === 'app') {
            $file_app = resource_path("lang/{$defaultLang->code}_app.json");
            $defaultLangData['translations_app'] = File::exists($file_app)
                ? json_decode(File::get($file_app), true)
                : [];
        } elseif ($systemType === 'web') {
            $file_web = resource_path("lang/{$defaultLang->code}_web.json");
            $defaultLangData['translations_web'] = File::exists($file_web)
                ? json_decode(File::get($file_web), true)
                : [];
        } else {
            $file_app = resource_path("lang/{$defaultLang->code}_app.json");
            $file_web = resource_path("lang/{$defaultLang->code}_web.json");

            $defaultLangData['translations_app'] = File::exists($file_app)
                ? json_decode(File::get($file_app), true)
                : [];

            $defaultLangData['translations_web'] = File::exists($file_web)
                ? json_decode(File::get($file_web), true)
                : [];
        }

        $result = [
            'languages' => $formattedLanguages,
            'default_lang' => $defaultLangData,
        ];

        return ApiResponseService::successResponse('Language Fetched Successfully', $result);
    }

    /**
     * Get Sales Chart Data
     */
    public function getSalesChartData(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'preset' => ['nullable', Rule::in(['today', '7d', '30d', '90d', '12m', 'this_year', 'custom'])],
                'date_from' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'group_by' => ['nullable', Rule::in(['day', 'month', 'year'])],
                'payment_method' => ['nullable', 'string', 'max:100'],
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            [$start, $end] = $this->resolveSalesChartPeriod(
                (string) $request->input('preset', '12m'),
                $request->input('date_from'),
                $request->input('date_to'),
            );
            $groupBy = (string) $request->input(
                'group_by',
                $start->diffInDays($end) > 90 ? 'month' : 'day',
            );

            $driver = DB::connection()->getDriverName();
            $bucketSql = $this->salesChartBucketSql($driver, $groupBy);
            $commissionTotals = DB::table('commissions')
                ->select('order_id')
                ->selectRaw('SUM(admin_commission_amount) as admin_profit')
                ->where('status', '!=', 'cancelled')
                ->groupBy('order_id');

            $rows = DB::table('orders')
                ->leftJoinSub($commissionTotals, 'commission_totals', function ($join): void {
                    $join->on('commission_totals.order_id', '=', 'orders.id');
                })
                ->selectRaw($bucketSql . ' as period')
                ->selectRaw('COUNT(DISTINCT orders.id) as sales_count')
                ->selectRaw('SUM(COALESCE(NULLIF(orders.amount_egp, 0), orders.final_price * COALESCE(orders.exchange_rate_snapshot, 1), 0)) as revenue_egp')
                ->selectRaw('SUM(COALESCE(commission_totals.admin_profit, 0) * COALESCE(orders.exchange_rate_snapshot, 1)) as profit_egp')
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', [$start, $end])
                ->when($request->filled('payment_method'), fn ($query) => $query->where('orders.payment_method', $request->input('payment_method')))
                ->groupBy(DB::raw($bucketSql))
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $salesChartData = $this->fillSalesChartPeriods($rows, $start, $end, $groupBy);

            return ApiResponseService::successResponse('Sales chart data retrieved successfully', $salesChartData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to retrieve sales chart data: ' . $e->getMessage());
        }
    }

    private function resolveSalesChartPeriod(string $preset, ?string $dateFrom, ?string $dateTo): array
    {
        $now = Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()],
            'custom' => [Carbon::parse($dateFrom)->startOfDay(), Carbon::parse($dateTo)->endOfDay()],
            default => [$now->copy()->subMonths(11)->startOfMonth(), $now->copy()],
        };
    }

    private function salesChartBucketSql(string $driver, string $groupBy): string
    {
        if ($groupBy === 'year') {
            return match ($driver) {
                'sqlite' => "strftime('%Y', orders.created_at)",
                'pgsql' => "TO_CHAR(orders.created_at, 'YYYY')",
                default => "DATE_FORMAT(orders.created_at, '%Y')",
            };
        }

        if ($groupBy === 'month') {
            return match ($driver) {
                'sqlite' => "strftime('%Y-%m-01', orders.created_at)",
                'pgsql' => "TO_CHAR(orders.created_at, 'YYYY-MM-01')",
                default => "DATE_FORMAT(orders.created_at, '%Y-%m-01')",
            };
        }

        return 'DATE(orders.created_at)';
    }

    private function fillSalesChartPeriods($rows, Carbon $start, Carbon $end, string $groupBy): array
    {
        $cursor = match ($groupBy) {
            'year' => $start->copy()->startOfYear(),
            'month' => $start->copy()->startOfMonth(),
            default => $start->copy()->startOfDay(),
        };
        $last = match ($groupBy) {
            'year' => $end->copy()->startOfYear(),
            'month' => $end->copy()->startOfMonth(),
            default => $end->copy()->startOfDay(),
        };
        $result = [];

        while ($cursor->lte($last)) {
            $key = match ($groupBy) {
                'year' => $cursor->format('Y'),
                default => $cursor->format('Y-m-d'),
            };
            $row = $rows->get($key);
            $result[] = [
                'date' => $key,
                'name' => match ($groupBy) {
                    'year' => $cursor->format('Y'),
                    'month' => $cursor->format('M Y'),
                    default => $cursor->format('d M Y'),
                },
                'sales' => (int) ($row->sales_count ?? 0),
                'revenue' => round((float) ($row->revenue_egp ?? 0), 2),
                'profit' => round((float) ($row->profit_egp ?? 0), 2),
            ];

            $cursor = match ($groupBy) {
                'year' => $cursor->addYear(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $result;
    }

    /**
     * Get FAQs (Frequently Asked Questions)
     */
    public function getFaqs(Request $request)
    {
        try {
            // Get pagination parameters
            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);

            // Validate pagination parameters
            $perPage = max(1, min(100, (int) $perPage)); // Limit between 1 and 100
            $page = max(1, (int) $page);

            // Get only active FAQs with pagination
            $faqs = Faq::where('is_active', true)->orderBy('sequence')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

            // Transform data for response
            $faqs->getCollection()->transform(static fn($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'created_at' => $faq->created_at,
                'updated_at' => $faq->updated_at,
            ]);

            return ApiResponseService::successResponse('FAQs retrieved successfully', $faqs);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getFaqs Method');
            return ApiResponseService::errorResponse('Failed to retrieve FAQs');
        }
    }

    public function getPages(Request $request)
    {
        try {
            $type = $request->input('type'); // page_type filter
            $languageId = $request->input('language_id'); // language_id filter
            $languageCode = $request->input('language_code'); // language_code filter

            $pagesQuery = Page::where('status', 1)->with('language'); // Only active pages

            // Filter by page_type if provided
            if (!empty($type)) {
                $pagesQuery->where('page_type', $type);
            }

            // Filter by language_code if provided (priority over language_id)
            if (!empty($languageCode)) {
                $language = Language::where('code', $languageCode)->where('status', 1)->first();
                if ($language) {
                    $pagesQuery->where('language_id', $language->id);
                } else {
                    // If language code not found, return empty result
                    return ApiResponseService::successResponse('Pages retrieved successfully', []);
                }
            }
            // Filter by language_id if provided (only if language_code is not provided)
            elseif (!empty($languageId)) {
                $pagesQuery->where('language_id', $languageId);
            }

            $pages = $pagesQuery
                ->orderBy('id', 'asc')
                ->get()
                ->map(static function ($page) {
                    // Map page_type to slug
                    $pageTypeSlugMap = [
                        'About Us' => 'about-us',
                        'Cookies Policy' => 'cookies-policy',
                        'Privacy Policy' => 'privacy-policy',
                        'Terms & Conditions' => 'terms-and-conditions',
                        'Custom' => 'custom',
                    ];

                    $pageTypeSlug = $pageTypeSlugMap[$page->page_type] ?? strtolower(str_replace(
                        ' ',
                        '-',
                        $page->page_type,
                    ));

                    return [
                        'id' => $page->id,
                        'language_id' => $page->language_id,
                        'language_name' => $page->language->name ?? null,
                        'title' => $page->title,
                        'page_type' => $page->page_type,
                        'page_type_slug' => $pageTypeSlug,
                        'slug' => $page->slug,
                        'page_content' => $page->page_content,
                        'page_icon' => $page->page_icon,
                        'og_image' => $page->og_image,
                        'schema_markup' => $page->schema_markup,
                        'meta_title' => $page->meta_title,
                        'meta_description' => $page->meta_description,
                        'meta_keywords' => $page->meta_keywords,
                        'is_custom' => $page->is_custom,
                        'is_termspolicy' => $page->is_termspolicy,
                        'is_privacypolicy' => $page->is_privacypolicy,
                        'status' => $page->status,
                        'created_at' => $page->created_at,
                        'updated_at' => $page->updated_at,
                    ];
                });

            return ApiResponseService::successResponse('Pages retrieved successfully', $pages);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getPages Method');
            return ApiResponseService::errorResponse('Failed to retrieve pages');
        }
    }

    /**
     * Get SEO Settings
     * Accepts language_id or language_code (language_code takes priority) and type (page_type)
     */
    public function getSeoSettings(Request $request)
    {
        try {
            $type = $request->input('type'); // page_type filter
            $languageId = $request->input('language_id'); // language_id filter
            $languageCode = $request->input('language_code'); // language_code filter

            $seoSettingsQuery = SeoSetting::with('language');

            // Filter by page_type if provided
            if (!empty($type)) {
                $seoSettingsQuery->where('page_type', $type);
            }

            // Filter by language_code if provided (priority over language_id)
            if (!empty($languageCode)) {
                $language = Language::where('code', $languageCode)->where('status', 1)->first();
                if ($language) {
                    $seoSettingsQuery->where('language_id', $language->id);
                } else {
                    // If language code not found, return empty result
                    return ApiResponseService::successResponse('SEO settings retrieved successfully', []);
                }
            }
            // Filter by language_id if provided (only if language_code is not provided)
            elseif (!empty($languageId)) {
                $seoSettingsQuery->where('language_id', $languageId);
            }

            $seoSettings = $seoSettingsQuery
                ->orderBy('id', 'asc')
                ->get()
                ->map(static fn($seoSetting) => [
                    'id' => $seoSetting->id,
                    'language_id' => $seoSetting->language_id,
                    'language_name' => $seoSetting->language->name ?? null,
                    'language_code' => $seoSetting->language->code ?? null,
                    'page_type' => $seoSetting->page_type,
                    'meta_title' => $seoSetting->meta_title,
                    'meta_description' => $seoSetting->meta_description,
                    'meta_keywords' => $seoSetting->meta_keywords,
                    'schema_markup' => $seoSetting->schema_markup,
                    'og_image' => $seoSetting->og_image
                        ? url(\Illuminate\Support\Facades\Storage::url($seoSetting->og_image))
                        : null,
                    'created_at' => $seoSetting->created_at,
                    'updated_at' => $seoSetting->updated_at,
                ]);

            return ApiResponseService::successResponse('SEO settings retrieved successfully', $seoSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getSeoSettings Method');
            return ApiResponseService::errorResponse('Failed to retrieve SEO settings');
        }
    }

    /**
     * Check if logged-in user's email exists
     */
    public function isEmailExist(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            $emailExists = !empty($user->email);

            return ApiResponseService::successResponse('Email check completed', [
                'email_exists' => $emailExists,
                'email' => $user->email ?? null,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> isEmailExist Method');
            return ApiResponseService::errorResponse('Failed to check email existence');
        }
    }
    /**
     * Submit Become an Instructor form
     */
    public function submitBecomeInstructor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'specialty' => 'nullable|string|max:255',
                'experience_bio' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Save to database
            $instructorRequest = \App\Models\InstructorRequest::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'specialty' => $request->specialty,
                'experience_bio' => $request->experience_bio,
                'status' => 'pending',
            ]);

            // Log the request
            Log::info('New Become an Instructor request submitted:', [
                'id' => $instructorRequest->id,
                'name' => $instructorRequest->name,
                'email' => $instructorRequest->email,
            ]);

            return ApiResponseService::successResponse(
                'Your request has been submitted successfully! We will contact you soon.',
                ['request_id' => $instructorRequest->id]
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            ApiResponseService::logErrorResponse($th, 'API Controller -> submitBecomeInstructor Method');
            return ApiResponseService::errorResponse('Failed to submit request. Please try again later.', exception: $th);
        }
    }
}
