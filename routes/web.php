<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HlsManagementController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CourseChaptersController;
use App\Http\Controllers\CoursesController;
use App\Http\Controllers\CustomFormFieldController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FeatureSectionController;
use App\Http\Controllers\FlutterwaveController;
use App\Http\Controllers\HelpdeskGroupController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SliderController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\KashierController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\PanelAuthenticate;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Web Routes
 |--------------------------------------------------------------------------
 |
 | Here is where you can register web routes for your application. These
 | routes are loaded by the RouteServiceProvider within a group which
 | contains the "web" middleware group. Now create something great!
 |
 */

/** Public Certificate Verification (T089) */
Route::get('certificate/verify/{number}', [\App\Http\Controllers\CertificateController::class , 'verify'])
    ->name('certificate.verify')
    ->middleware('throttle:30,1');

/** Serve public storage files (fixes 403 when symlink not followed, e.g. php artisan serve) */
Route::get('storage/{path}', function (string $path) {
    $path = str_replace(['../', '..\\'], '', $path);
    if ($path === '' || !\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        abort(404);
    }
    $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
    $mimeType = \Illuminate\Support\Facades\File::mimeType($fullPath);
    return response()->file($fullPath, ['Content-Type' => $mimeType]);
})->where('path', '.*')->name('storage.serve');

/** Authentication Routes */
Route::get('login', [AuthController::class , 'showLoginForm'])->name('login-page');
Route::post('login', [AuthController::class , 'login'])->name('login');
/***************************************************************************************************** */

/** Protected Admin Routes */
Route::middleware([PanelAuthenticate::class , SetAdminLocale::class])->group(function (): void {
    /** Dashboard Routes */
    Route::get('/', [DashboardController::class , 'index'])->name('dashboard');

    /***************************************************************************************************** */

    /** Settings Routes */
    // System Settings Routes
    Route::get('/feature-flags', [FeatureFlagController::class , 'index'])->name('feature-flags.index');
    Route::post('/feature-flags/{id}/toggle', [FeatureFlagController::class , 'toggle'])->name('feature-flags.toggle');

    Route::get('/marketing-pixels', [App\Http\Controllers\Admin\MarketingPixelController::class , 'index'])->name('marketing-pixels.index');
    Route::post('/marketing-pixels', [App\Http\Controllers\Admin\MarketingPixelController::class , 'store'])->name('marketing-pixels.store');
    Route::delete('/marketing-pixels/{id}', [App\Http\Controllers\Admin\MarketingPixelController::class , 'destroy'])->name('marketing-pixels.destroy');

    Route::get('/currencies', [App\Http\Controllers\Admin\CurrencyController::class , 'index'])->name('currencies.index');
    Route::post('/currencies', [App\Http\Controllers\Admin\CurrencyController::class , 'store'])->name('currencies.store');
    Route::put('/currencies/{id}', [App\Http\Controllers\Admin\CurrencyController::class , 'update'])->name('currencies.update');
    Route::delete('/currencies/{id}', [App\Http\Controllers\Admin\CurrencyController::class , 'destroy'])->name('currencies.destroy');

    Route::get('/system-settings', [SettingsController::class , 'system'])->name('settings.system');
    Route::post('/system-settings', [SettingsController::class , 'update'])->name('settings.system.update');

    // Firebase Settings Routes
    Route::get('/firebase-settings', [SettingsController::class , 'firebase'])->name('settings.firebase');
    Route::post('/firebase-settings', [SettingsController::class , 'updateFirebase'])->name('settings.firebase.update');

    // Refund Settings Routes
    Route::get('/refund-settings', [SettingsController::class , 'refund'])->name('settings.refund');
    Route::post('/refund-settings', [SettingsController::class , 'updateRefund'])->name('settings.refund.update');

    // Instructor Terms Settings Routes
    Route::get('/instructor-terms-settings', [SettingsController::class , 'instructorTerms'])->name(
        'settings.instructor-terms',
    );
    Route::post('/instructor-terms-settings', [SettingsController::class , 'updateInstructorTerms'])->name(
        'settings.instructor-terms.update',
    );

    // Why Choose Us Settings
    Route::get('/why-choose-us-settings', [SettingsController::class , 'whyChooseUs'])->name('settings.why-choose-us');
    Route::post('/why-choose-us-settings', [SettingsController::class , 'updateWhyChooseUs'])->name(
        'settings.why-choose-us.update',
    );

    // System Update Routes — معطّلة حتى لا يُرسل أي بيانات للمطوّر
    // Route::get('/system-update', [SettingsController::class , 'systemUpdate'])->name('settings.system-update');
    // Route::post('/system-update', [SettingsController::class , 'updateSystem'])->name('settings.system-update.update');

    // HLS Video Management Routes
    Route::prefix('hls-management')
        ->name('settings.hls.')
        ->group(function (): void {
            Route::get('/', [HlsManagementController::class , 'index'])->name('index');
            Route::post('/refresh-status', [HlsManagementController::class , 'refreshStatus'])->name('refresh-status');
            Route::get('/videos', [HlsManagementController::class , 'getVideos'])->name('videos');
            Route::post('/encode/{lecture}', [HlsManagementController::class , 'encodeVideo'])->name('encode');
            Route::post('/retry/{lecture}', [HlsManagementController::class , 'retryEncoding'])->name('retry');
            Route::post('/reencode/{lecture}', [HlsManagementController::class , 'reencodeVideo'])->name('reencode');
            Route::post('/bulk-retry', [HlsManagementController::class , 'bulkRetryFailed'])->name('bulk-retry');
            Route::post('/settings', [HlsManagementController::class , 'updateSettings'])->name('update-settings');
        }
        );

        // Become Instructor Settings
        Route::get('/become-instructor-settings', [SettingsController::class , 'becomeInstructor'])->name(
            'settings.become-instructor',
        );

        // SEO Settings Routes
        Route::resource('seo-settings', \App\Http\Controllers\Admin\SeoSettingsController::class)->names([
            'index' => 'admin.seo-settings.index',
            'create' => 'admin.seo-settings.create',
            'store' => 'admin.seo-settings.store',
            'show' => 'admin.seo-settings.show',
            'edit' => 'admin.seo-settings.edit',
            'update' => 'admin.seo-settings.update',
            'destroy' => 'admin.seo-settings.destroy',
        ]);
        Route::put('seo-settings/{id}/restore', [
            \App\Http\Controllers\Admin\SeoSettingsController::class ,
            'restore',
        ])->name('admin.seo-settings.restore');
        Route::delete('seo-settings/{id}/trash', [\App\Http\Controllers\Admin\SeoSettingsController::class , 'trash'])->name(
            'admin.seo-settings.trash',
        );
        Route::post('/become-instructor-settings', [SettingsController::class , 'updateBecomeInstructor'])->name(
            'settings.become-instructor.update',
        );

        // Social Media Settings Routes
        Route::delete('/settings/social-medias/{id}', [SettingsController::class , 'destroySocialMedia'])->name(
            'settings.social-medias.destroy',
        );

        // App Settings Routes
        Route::get('/app-settings', [SettingsController::class , 'appSettings'])->name('settings.app');
        Route::post('/app-settings', [SettingsController::class , 'updateAppSettings'])->name('settings.app.update');

        // Currency Settings Routes
        Route::get('get-currency-symbol', [SettingsController::class , 'getCurrencySymbol'])->name('get-currency-symbol');

        // Payment Gateway Settings Routes
        Route::get('/payment-gateway-settings', [SettingsController::class , 'paymentGateway'])->name(
            'settings.payment-gateway',
        );
        Route::post('/payment-gateway-settings', [SettingsController::class , 'updatePaymentGateway'])->name(
            'settings.payment-gateway.update',
        );

        // Language Settings Routes
        /*** Language Module : START ***/

        Route::group(['prefix' => 'language'], static function (): void {
            Route::get('language', [SettingsController::class , 'page'])->name('settings.language');
            Route::get('set-language/{lang}', [LanguageController::class , 'setLanguage'])->name('language.set-current');
            Route::get('download/panel', [LanguageController::class , 'downloadPanelFile'])->name(
                'language.download.panel.json',
            );
            Route::get('download/app', [LanguageController::class , 'downloadAppFile'])->name('language.download.app.json');
            Route::get('download/web', [LanguageController::class , 'downloadWebFile'])->name('language.download.web.json');
            Route::get('download-sample/{type}', [LanguageController::class , 'downloadSampleFile'])->name(
                'language.download.sample',
            );

            Route::put('/language/update/{id}/{type}', [LanguageController::class , 'updatelanguage'])->name(
                'updatelanguage',
            );
            Route::get('languageedit/{id}/{type}', [LanguageController::class , 'editLanguage'])->name('languageedit');

            // New auto-translate and save functionality
            Route::post('auto-translate/{id}/{type}/{locale}', [LanguageController::class , 'autoTranslate'])->name(
                'language.auto-translate',
            );

            // Debug route for testing auto-translate
            Route::get('test-auto-translate/{id}', function ($id) {
                    $language = \App\Models\Language::findOrFail($id);
                    $languageCode = $language->code;

                    $defaultFile = base_path('resources/lang/en.json');
                    $jsonFile = base_path("resources/lang/{$languageCode}.json");

                    $enContent = File::exists($defaultFile) ? json_decode(File::get($defaultFile), true) : [];
                    $targetContent = File::exists($jsonFile) ? json_decode(File::get($jsonFile), true) : [];

                    $missingTranslations = [];
                    foreach ($enContent as $key => $englishValue) {
                        $currentValue = isset($targetContent[$key]) ? trim((string)$targetContent[$key]) : '';
                        if (empty($currentValue) || $currentValue === $englishValue) {
                            $missingTranslations[$key] = $englishValue;
                        }
                    }

                    return response()->json([
                    'language' => $language->toArray(),
                    'total_english_strings' => count($enContent),
                    'target_file_exists' => File::exists($jsonFile),
                    'target_strings_count' => count($targetContent),
                    'missing_translations_count' => count($missingTranslations),
                    'missing_keys' => array_keys($missingTranslations),
                    'sample_missing' => array_slice($missingTranslations, 0, 5, true),
                    ]);
                }
                )->name('language.test-auto-translate');
            }
            );
            Route::resource('language', LanguageController::class);
            // Route::get('auto-translate/{id}/{type}/{locale}', function ($id, $type, $locale) {
            //     Log::info("Running auto-translate with ID: $id, Type: $type, Locale: $locale");
            //     $exitCode = Artisan::call('custom:translate-missing', [
            //         'type' => $type,
            //         'locale' => $locale
            //     ]);
            //     if ($exitCode === 0) {
            //         Log::info("Auto translation completed successfully.");
            //         return redirect()->route('languageedit', ['id' => $id, 'type' => $type])
            //                         ->with('success', 'Auto translation completed successfully.');
            //     } else {
            //         Log::error("Auto translation failed with exit code: $exitCode");
            //         return redirect()->route('languageedit', ['id' => $id, 'type' => $type])
            //                         ->with('error', 'Auto translation failed.');
            //     }
            // })->name('auto-translate');
        
            /*** Language Module : END ***/
            /***************************************************************************************************** */

            // Category Management
            Route::resource('categories', CategoryController::class);
            // Route::get('/categories/create', [CategoryController::class, 'create'])->name('category.create');
        
            Route::group(['prefix' => 'category'], static function (): void {
            Route::get('/{id}/subcategories', [CategoryController::class , 'getSubCategories'])->name(
                'category.subcategories',
            );
            Route::get('/categories/order', [CategoryController::class , 'categoriesReOrder'])->name('categories.order');
            Route::post('/categories/order', [CategoryController::class , 'updateOrder'])->name('categories.order.update');
            Route::put('/categories/{id}/restore', [CategoryController::class , 'restore'])->name('categories.restore');
            Route::delete('/categories/{id}/trash', [CategoryController::class , 'trash'])->name('categories.trash');
            Route::get('/{id}/sub-category/change-order', [CategoryController::class , 'subcategoriesOrder'])->name(
                'sub.category.order.change',
            );
        }
        );
        /***************************************************************************************************** */

        // custom-fields Module
        Route::put('custom-form-fields/update-rank', [CustomFormFieldController::class , 'updateRankOfFields']);
        Route::delete('custom-form/default-value/{id}', [CustomFormFieldController::class , 'deleteOption'])->name(
            'custom-form.default-value.destroy',
        );
        Route::put('custom-form-fields/{id}/restore', [CustomFormFieldController::class , 'restore'])->name(
            'custom-form-fields.restore',
        );
        Route::delete('custom-form-fields/{id}/trash', [CustomFormFieldController::class , 'trash'])->name(
            'custom-form-fields.trash',
        );
        Route::resource('/custom-form-fields', CustomFormFieldController::class);

        // slider Module
        Route::put('sliders/update-rank', [SliderController::class , 'updateRankOfFields']);
        Route::resource('/sliders', SliderController::class);

        /***************************************************************************************************** */
        // Tax Module
        Route::resource('/taxes', TaxController::class);
        Route::put('/taxes/{id}/restore', [TaxController::class , 'restore'])->name('taxes.restore');
        Route::delete('/taxes/{id}/trash', [TaxController::class , 'trash'])->name('taxes.trash');

        /***************************************************************************************************** */
        // Feature Section Module
        Route::put('feature-sections/update-rank', [FeatureSectionController::class , 'updateRankOfFeatureSections']);
        Route::put('/feature-sections/{id}/restore', [FeatureSectionController::class , 'restore'])->name(
            'feature-sections.restore',
        );
        Route::delete('/feature-sections/{id}/trash', [FeatureSectionController::class , 'trash'])->name(
            'feature-sections.trash',
        );
        Route::resource('/feature-sections', FeatureSectionController::class);

        /***************************************************************************************************** */
        // Promo Code Module
        Route::resource('/promo-codes', PromoCodeController::class);
        Route::put('/promo-codes/{id}/restore', [PromoCodeController::class , 'restore'])->name('promo-codes.restore');
        Route::delete('/promo-codes/{id}/trash', [PromoCodeController::class , 'trash'])->name('promo-codes.trash');

        /***************************************************************************************************** */
        // Subscription Plans Module
        Route::resource('/subscription-plans', SubscriptionPlanController::class)->names([
            'index' => 'subscription-plans.index',
            'create' => 'subscription-plans.create',
            'store' => 'subscription-plans.store',
            'show' => 'subscription-plans.show',
            'edit' => 'subscription-plans.edit',
            'update' => 'subscription-plans.update',
            'destroy' => 'subscription-plans.destroy',
        ]);
        Route::put('/subscription-plans/{id}/restore', [SubscriptionPlanController::class , 'restore'])->name('subscription-plans.restore');
        Route::delete('/subscription-plans/{id}/trash', [SubscriptionPlanController::class , 'trash'])->name('subscription-plans.trash');
        Route::post('/subscription-plans/{id}/toggle', [SubscriptionPlanController::class , 'toggleStatus'])->name('subscription-plans.toggle');
        Route::put('/subscription-plans/{id}/sort', [SubscriptionPlanController::class , 'updateSortOrder'])->name('subscription-plans.sort');

        /***************************************************************************************************** */
        // Countries Module
        Route::post('/countries/{id}/toggle', [CountryController::class , 'toggleStatus'])->name('countries.toggle');
        Route::resource('/countries', CountryController::class);

        /***************************************************************************************************** */
        // Certificate Module
        Route::resource('certificates', \App\Http\Controllers\Admin\CertificateController::class)->names([
            'index' => 'admin.certificates.index',
            'create' => 'admin.certificates.create',
            'store' => 'admin.certificates.store',
            'show' => 'admin.certificates.show',
            'edit' => 'admin.certificates.edit',
            'update' => 'admin.certificates.update',
            'destroy' => 'admin.certificates.destroy',
        ]);
        Route::post('certificates/{certificate}/toggle-status', [
            \App\Http\Controllers\Admin\CertificateController::class ,
            'toggleStatus',
        ])->name('admin.certificates.toggle-status');
        Route::get('certificates/{certificate}/preview', [
            \App\Http\Controllers\Admin\CertificateController::class ,
            'preview',
        ])->name('admin.certificates.preview');
        Route::get('certificates/{certificate}/editor', [
            \App\Http\Controllers\Admin\CertificateController::class ,
            'editor',
        ])->name('admin.certificates.editor');
        Route::post('certificates/{certificate}/update-design', [
            \App\Http\Controllers\Admin\CertificateController::class ,
            'updateDesign',
        ])->name('admin.certificates.update-design');

        /** Courses Module */
        Route::group(['prefix' => 'courses'], function (): void {
            // Course Publish Requests & Approval (must be before {id} routes)
            Route::get('requests', [CoursesController::class , 'requests'])->name('courses.requests'); // page
            Route::get('requests/list', [CoursesController::class , 'requestsList'])->name('courses.requests.list'); // data
            // Rejected Courses (must be before {id} routes)
            Route::get('rejected', [CoursesController::class , 'rejected'])->name('courses.rejected'); // page
            Route::get('rejected/list', [CoursesController::class , 'rejectedList'])->name('courses.rejected.list'); // data
            // Courses Learnings Routes (must be before {id} route)
            Route::get('{id}/learnings', [CoursesController::class , 'courseLearnings'])->name('courses.learnings'); // page for learnings
            Route::post('{id}/learnings', [CoursesController::class , 'storeLearnings'])->name('courses.learnings.store'); // store learnings
            Route::delete('{id}/learnings', [CoursesController::class , 'destroyLearnings'])->name(
                'courses.learnings.destroy',
            ); // destroy learnings
            // Courses Requirements Routes (must be before {id} route)
            Route::get('{id}/requirements', [CoursesController::class , 'courseRequirements'])->name('courses.requirements'); // page for requirements
            Route::post('{id}/requirements', [CoursesController::class , 'storeRequirements'])->name(
                'courses.requirements.store',
            ); // store requirements
            Route::delete('{id}/requirements', [CoursesController::class , 'destroyRequirements'])->name(
                'courses.requirements.destroy',
            ); // destroy requirements
            // Courses Main Routes
            Route::get('{id}/view', [CoursesController::class , 'view'])->name('courses.view'); // view course (read-only) - must be after specific routes
            Route::put('{id}/restore', [CoursesController::class , 'restore'])->name('courses.restore'); // restore
            Route::delete('{id}/trash', [CoursesController::class , 'trash'])->name('courses.trash'); // force delete
            Route::post('{id}/approve', [CoursesController::class , 'approve'])->name('courses.approve');

            // Course Languages Routes
            Route::get('languages', [CoursesController::class , 'languagesIndex'])->name('courses.languages.index');
            Route::post('languages', [CoursesController::class , 'languagesStore'])->name('courses.languages.store');
            Route::get('languages/list', [CoursesController::class , 'languagesList'])->name('courses.languages.list');
            Route::put('languages/edit/{id}', [CoursesController::class , 'languagesEdit'])->name('courses.languages.edit');
            Route::delete('languages/{id}', [CoursesController::class , 'languagesDestroy'])->name(
                'courses.languages.destroy',
            );
            Route::put('languages/{id}/restore', [CoursesController::class , 'languagesRestore'])->name(
                'courses.languages.restore',
            );
            Route::delete('languages/{id}/trash', [CoursesController::class , 'languagesTrash'])->name(
                'courses.languages.trash',
            );

            // Course Tags Routes
            Route::get('tags', [CoursesController::class , 'tagIndex'])->name('tags.index');
            Route::post('tags', [CoursesController::class , 'tagStore'])->name('tags.store');
            Route::get('tags/list', [CoursesController::class , 'tagList'])->name('tags.list');
            Route::put('tags/edit/{id}', [CoursesController::class , 'tagEdit'])->name('tags.edit');
            Route::delete('tags/{id}', [CoursesController::class , 'tagDestroy'])->name('tags.destroy');
            Route::put('tags/restore/{id}', [CoursesController::class , 'tagRestore'])->name('tags.restore');
            Route::delete('tags/trash/{id}', [CoursesController::class , 'tagTrash'])->name('tags.trash');
        }
        );
        // Courses Routes
        Route::resource('courses', CoursesController::class); // main routes
    
        /***************************************************************************************************** */

        /** Course Chapters Module */
        Route::group(['prefix' => 'course-chapters'], function (): void {
            Route::get('{id}/curriculum', [CourseChaptersController::class , 'curriculumIndex'])->name(
                'course-chapters.curriculum.index',
            );
            Route::post('{id}/curriculum', [CourseChaptersController::class , 'curriculumStore'])->name(
                'course-chapters.curriculum.store',
            );
            Route::get('{id}/curriculum/list', [CourseChaptersController::class , 'getCurriculumDataList'])->name(
                'course-chapters.curriculum.list',
            );
            Route::put('{id}/curriculum/change-status', [CourseChaptersController::class , 'changeCurriculumStatus'])->name(
                'course-chapters.curriculum.change-status',
            );
            Route::get('{id}/{type}/particular-curriculum/details', [
                CourseChaptersController::class ,
                'getParticularCurriculumDetails',
            ])->name('course-chapters.curriculum.particular-details');
            Route::get('{id}/{type}/curriculum/edit', [CourseChaptersController::class , 'curriculumEdit'])->name(
                'course-chapters.curriculum.edit',
            );
            Route::delete('{id}/{type}/curriculum/destroy', [CourseChaptersController::class , 'curriculumDestroy'])->name(
                'course-chapters.curriculum.destroy',
            );
            Route::get('{id}/curriculum/trashed', [CourseChaptersController::class , 'getTrashedCurriculumList'])->name(
                'course-chapters.curriculum.trashed',
            );
            Route::put('{id}/{type}/curriculum/restore', [CourseChaptersController::class , 'restoreCurriculum'])->name(
                'course-chapters.curriculum.restore',
            );

            // Update Lecture Curriculum
            Route::put('{id}/curriculum/lecture/update', [
                CourseChaptersController::class ,
                'curriculumLectureUpdate',
            ])->name('course-chapters.curriculum.lecture.update');
            // Update Resource Curriculum
            Route::put('{id}/curriculum/resource/update', [
                CourseChaptersController::class ,
                'curriculumResourceUpdate',
            ])->name('course-chapters.curriculum.resource.update');
            // Update Quiz Curriculum
            Route::put('{id}/curriculum/quiz/update', [CourseChaptersController::class , 'curriculumQuizUpdate'])->name(
                'course-chapters.curriculum.quiz.update',
            );
            // Update Assignment Curriculum
            Route::put('{id}/curriculum/assignment/update', [
                CourseChaptersController::class ,
                'curriculumAssignmentUpdate',
            ])->name('course-chapters.curriculum.assignment.update');

            // Quiz Questions Routes
            Route::get('{id}/quiz/questions/list', [CourseChaptersController::class , 'quizQuestionsList'])->name(
                'course-chapters.quiz.questions.list',
            );
            Route::put('{id}/quiz/questions/update', [CourseChaptersController::class , 'quizQuestionsUpdate'])->name(
                'course-chapters.quiz.questions.update',
            );
            Route::delete('{id}/quiz/questions/destroy', [CourseChaptersController::class , 'quizQuestionsDestroy'])->name(
                'course-chapters.quiz.questions.destroy',
            );
            Route::get('{id}/{type}/curriculum/reorder', [CourseChaptersController::class , 'reorder'])->name(
                'course-chapters.curriculum.reorder',
            );
            Route::put('{id}/{type}/curriculum/reorder-update', [CourseChaptersController::class , 'reorderUpdate'])->name(
                'course-chapters.curriculum.reorder-update',
            );

            // Curriculum reorder all items (inline) - using standard pattern
            Route::put('{id}/curriculum/update-rank', [CourseChaptersController::class , 'updateRankOfCurriculum'])->name(
                'course-chapters.curriculum.update-rank',
            );
            // Helper endpoint to fetch courses for an instructor (admin only usage on filter)
            Route::get('instructor/{instructor_id}/courses', function ($instructor_id) {
                    // Admin may own courses; instructors own their courses
                    // Return all courses (same as courses list page - no approval_status filter)
                    $courses = \App\Models\Course\Course::where('user_id', $instructor_id)
                        ->select('id', 'title')
                        ->orderBy('title')
                        ->get();
                    return response()->json($courses);
                }
                )->name('course-chapters.instructor.courses');
            }
            );
            Route::put('course-chapters/{id}/restore', [CourseChaptersController::class , 'restore'])->name(
                'course-chapters.restore',
            );
            Route::delete('course-chapters/{id}/trash', [CourseChaptersController::class , 'trash'])->name(
                'course-chapters.trash',
            ); // force delete
            Route::resource('course-chapters', CourseChaptersController::class);
            /***************************************************************************************************** */

            /** Staffs Module */
            Route::group(['prefix' => 'staffs'], static function (): void {
            Route::put('/{id}/change-password', [StaffController::class , 'changePassword'])->name('staffs.change-password');
        }
        );
        Route::resource('staffs', StaffController::class);

        /***************************************************************************************************** */

        /** Roles Module */
        Route::get('/roles-list', [RoleController::class , 'list'])->name('roles.list');
        Route::resource('roles', RoleController::class);
        /***************************************************************************************************** */

        /** Faq Module */
        Route::prefix('faqs')->group(function (): void {
            Route::put('/{id}/restore', [FaqController::class , 'restore'])->name('faqs.restore');
            Route::delete('/{id}/force-delete', [FaqController::class , 'trash'])->name('faqs.trash');
        }
        );
        Route::resource('/faqs', FaqController::class);

        /***************************************************************************************************** */
        /** Pages Module */
        Route::prefix('pages')->group(function (): void {
            Route::put('/{id}/restore', [PageController::class , 'restore'])->name('pages.restore');
            Route::delete('/{id}/force-delete', [PageController::class , 'trash'])->name('pages.trash');
        }
        );
        Route::resource('/pages', PageController::class);

        // Public Page View Route (for About, Terms, Privacy, Cookies pages)
        Route::get('/page/{slug}', [PageController::class , 'viewPage'])->name('pages.view');

        /***************************************************************************************************** */
        /** Instructor Terms Module */
        Route::resource('instructor', InstructorController::class);
        Route::get('instructor/show-form/{id}', [InstructorController::class , 'showForm'])->name('instructor.show-form');
        Route::put('instructor/status-update/{id}', [InstructorController::class , 'updateStatus'])->name(
            'instructor.status.update',
        );
        Route::get('instructor-wallet-history', [InstructorController::class , 'walletHistory'])->name(
            'instructor.wallet-history',
        );
        Route::get('instructor-wallet-history-data', [InstructorController::class , 'getWalletHistoryData'])->name(
            'instructor.wallet-history.data',
        );
        Route::get('instructor-withdrawal-requests', [InstructorController::class , 'withdrawalRequests'])->name(
            'instructor.withdrawal-requests',
        );
        Route::get('test-withdrawal', function () {
            try {
                $count = \App\Models\WithdrawalRequest::count();
                return 'WithdrawalRequest model works! Count: ' . $count;
            }
            catch (\Exception $e) {
                return 'Error: ' . $e->getMessage();
            }
        }
        );

        Route::get('test-withdrawal-page', function () {
            try {
                $count = \App\Models\WithdrawalRequest::count();
                return response('Test page works! Withdrawal count: ' . $count, 200)->header('Content-Type', 'text/html');
            }
            catch (\Exception $e) {
                return response('Error: ' . $e->getMessage(), 500);
            }
        }
        );

        Route::get('create-test-withdrawal', function () {
            try {
                $user = \App\Models\User::first();
                if ($user) {
                    $withdrawal = \App\Models\WithdrawalRequest::create([
                        'user_id' => $user->id,
                        'amount' => 100,
                        'status' => 'pending',
                        'payment_method' => 'bank_transfer',
                        'payment_details' => [
                            'account_holder_name' => 'Test User',
                            'account_number' => '1234567890',
                            'bank_name' => 'Test Bank',
                            'ifsc_code' => 'TEST0001234',
                        ],
                        'notes' => 'Test withdrawal request',
                    ]);
                    return response('Created test withdrawal request ID: ' . $withdrawal->id, 200);
                }
                else {
                    return response('No users found', 500);
                }
            }
            catch (\Exception $e) {
                return response('Error: ' . $e->getMessage(), 500);
            }
        }
        );
        Route::get('instructor-withdrawal-requests-data', [InstructorController::class , 'getWithdrawalRequestsData'])->name(
            'instructor.withdrawal-requests.data',
        );
        Route::post('instructor-withdrawal-request-update-status', [
            InstructorController::class ,
            'updateWithdrawalRequestStatus',
        ])->name('instructor.withdrawal-request.update-status');
        /***************************************************************************************************** */

        /** Notification Module */
        Route::resource('notifications', NotificationController::class);

        /** HelpDesk Module */
        Route::prefix('helpdesk')->group(function (): void {
            Route::put('groups/update-rank', [HelpdeskGroupController::class , 'updateRankOfGroups'])->name(
                'helpdesk.groups.update-rank',
            );
            Route::resource('groups', HelpdeskGroupController::class)->names([
                'index' => 'groups.index',
                'create' => 'groups.create',
                'store' => 'groups.store',
                'show' => 'groups.show',
                'edit' => 'groups.edit',
                'update' => 'groups.update',
                'destroy' => 'groups.destroy',
            ]);
            Route::get('groups/{id}/restore', [HelpdeskGroupController::class , 'restore'])->name('groups.restore');
            Route::delete('groups/{id}/trash', [HelpdeskGroupController::class , 'trash'])->name('groups.trash');
        }
        );

        /** Admin HelpDesk Module */
        Route::prefix('admin/helpdesk')
            ->name('admin.helpdesk.')
            ->group(function (): void {
            // Group Requests
            Route::get('group-requests', [
                App\Http\Controllers\Admin\HelpdeskGroupRequestController::class ,
                'index',
            ])->name('group-requests.index');
            Route::get('group-requests/{id}', [
                App\Http\Controllers\Admin\HelpdeskGroupRequestController::class ,
                'show',
            ])->name('group-requests.show');
            Route::post('group-requests/{id}/status', [
                App\Http\Controllers\Admin\HelpdeskGroupRequestController::class ,
                'updateStatus',
            ])->name('group-requests.update-status');
            Route::get('group-requests/dashboard/data', [
                App\Http\Controllers\Admin\HelpdeskGroupRequestController::class ,
                'getDashboardData',
            ])->name('group-requests.dashboard');

            // Questions
            Route::get('questions', [App\Http\Controllers\Admin\HelpdeskQuestionController::class , 'index'])->name(
                'questions.index',
            );
            Route::get('questions/{id}', [App\Http\Controllers\Admin\HelpdeskQuestionController::class , 'show'])->name(
                'questions.show',
            );
            Route::get('questions/slug/{slug}', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'showBySlug',
            ])->name('questions.show.slug');
            Route::get('questions/{id}/edit', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'edit',
            ])->name('questions.edit');
            Route::put('questions/{id}', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'update',
            ])->name('questions.update');
            Route::delete('questions/{id}', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'destroy',
            ])->name('questions.destroy');
            Route::put('questions/{id}/restore', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'restore',
            ])->name('questions.restore');
            Route::delete('questions/{id}/trash', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'trash',
            ])->name('questions.trash');
            Route::get('questions/dashboard/data', [
                App\Http\Controllers\Admin\HelpdeskQuestionController::class ,
                'getDashboardData',
            ])->name('questions.dashboard');

            // Replies
            Route::get('replies', [App\Http\Controllers\Admin\HelpdeskReplyController::class , 'index'])->name(
                'replies.index',
            );
            Route::get('replies/{id}', [App\Http\Controllers\Admin\HelpdeskReplyController::class , 'show'])->name(
                'replies.show',
            );
            Route::get('replies/{id}/edit', [App\Http\Controllers\Admin\HelpdeskReplyController::class , 'edit'])->name(
                'replies.edit',
            );
            Route::put('replies/{id}', [App\Http\Controllers\Admin\HelpdeskReplyController::class , 'update'])->name(
                'replies.update',
            );
            Route::delete('replies/{id}', [App\Http\Controllers\Admin\HelpdeskReplyController::class , 'destroy'])->name(
                'replies.destroy',
            );
            Route::get('replies/dashboard/data', [
                App\Http\Controllers\Admin\HelpdeskReplyController::class ,
                'getDashboardData',
            ])->name('replies.dashboard');
        }
        );

        /** Refund Management Module */
        Route::prefix('refunds')
            ->name('admin.refunds.')
            ->group(function (): void {
            Route::get('/', [RefundController::class , 'index'])->name('index');
            Route::get('/{id}', [RefundController::class , 'show'])->name('show');
            Route::post('/{id}/process', [RefundController::class , 'process'])->name('process');
        }
        );

        /** Wallet Management Module */
        Route::prefix('wallets')
            ->name('admin.wallets.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\WalletController::class , 'index'])->name('index');
            Route::get('/data', [App\Http\Controllers\Admin\WalletController::class , 'getWalletData'])->name('data');
        }
        );

        /** Withdrawal Management Module */
        Route::prefix('withdrawals')
            ->name('admin.withdrawals.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\WithdrawalController::class , 'index'])->name('index');
            Route::get('/data', [App\Http\Controllers\Admin\WithdrawalController::class , 'getWithdrawalData'])->name(
                'data',
            );
            Route::get('/{id}', [App\Http\Controllers\Admin\WithdrawalController::class , 'show'])->name('show');
            Route::post('/{id}/update-status', [
                App\Http\Controllers\Admin\WithdrawalController::class ,
                'updateStatus',
            ])->name('update-status');
        }
        );

        /** Contact Messages Management Module */
        Route::prefix('contact-messages')
            ->name('admin.contact-messages.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\ContactMessageController::class , 'index'])->name(
                'index',
            )->middleware('can:contact-messages-list');
            Route::get('/data', [App\Http\Controllers\Admin\ContactMessageController::class , 'getData'])->name(
                'data',
            )->middleware('can:contact-messages-list');
            Route::get('/{id}', [App\Http\Controllers\Admin\ContactMessageController::class , 'show'])->name(
                'show',
            )->middleware('can:contact-messages-edit');
            Route::post('/{id}/update-status', [
                App\Http\Controllers\Admin\ContactMessageController::class ,
                'updateStatus',
            ])
                ->name('update-status')
                ->middleware('can:contact-messages-edit');
            Route::delete('/{id}', [App\Http\Controllers\Admin\ContactMessageController::class , 'destroy'])
                ->name('destroy')
                ->middleware('can:contact-messages-delete');
        }
        );

        /** Approvals (Ratings & Comments) */
        Route::get('/approvals', fn() => view('admin.approvals.index', ['type_menu' => 'approvals']))->name('admin.approvals.index');
        Route::prefix('reviews')->name('admin.reviews.')->group(function (): void {
            Route::get('/pending', [App\Http\Controllers\Admin\ApprovalController::class , 'pendingRatings'])->name('pending');
            Route::post('/{id}/approve', [App\Http\Controllers\Admin\ApprovalController::class , 'approveRating'])->name('approve');
            Route::post('/{id}/reject', [App\Http\Controllers\Admin\ApprovalController::class , 'rejectRating'])->name('reject');
        }
        );
        Route::prefix('comments')->name('admin.comments.')->group(function (): void {
            Route::get('/pending', [App\Http\Controllers\Admin\ApprovalController::class , 'pendingComments'])->name('pending');
            Route::post('/{id}/approve', [App\Http\Controllers\Admin\ApprovalController::class , 'approveComment'])->name('approve');
            Route::post('/{id}/reject', [App\Http\Controllers\Admin\ApprovalController::class , 'rejectComment'])->name('reject');
        }
        );

        /** Ratings Management Module */
        Route::prefix('ratings')
            ->name('admin.ratings.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\RatingController::class , 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\RatingController::class , 'show'])->name('show');
            Route::delete('/{id}', [App\Http\Controllers\Admin\RatingController::class , 'destroy'])->name('destroy');
            Route::get('/dashboard/data', [
                App\Http\Controllers\Admin\RatingController::class ,
                'getDashboardData',
            ])->name('dashboard.data');
        }
        );

        /** Orders Management Module */
        Route::prefix('orders')
            ->name('admin.orders.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OrdersController::class , 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\OrdersController::class , 'show'])->name('show');
            Route::patch('/{id}/status', [App\Http\Controllers\Admin\OrdersController::class , 'updateStatus'])->name(
                'update-status',
            );
            Route::get('/dashboard/data', [
                App\Http\Controllers\Admin\OrdersController::class ,
                'getDashboardData',
            ])->name('dashboard.data');
        }
        );

        /** Enrollment Management Module */
        Route::prefix('enrollments')
            ->name('admin.enrollments.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\EnrollmentController::class , 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\EnrollmentController::class , 'show'])->name('show');
            Route::get('/dashboard/data', [
                App\Http\Controllers\Admin\EnrollmentController::class ,
                'getDashboardData',
            ])->name('dashboard.data');
        }
        );

        /** Tracking Management Module */
        Route::prefix('tracking')
            ->name('admin.tracking.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\TrackingController::class , 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\TrackingController::class , 'show'])->name('show');
            Route::patch('/{id}/progress', [
                App\Http\Controllers\Admin\TrackingController::class ,
                'updateProgress',
            ])->name('update-progress');
            Route::get('/dashboard/data', [
                App\Http\Controllers\Admin\TrackingController::class ,
                'getDashboardData',
            ])->name('dashboard.data');
        }
        );

        Route::prefix('users')
            ->name('admin.users.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\UserController::class , 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\UserController::class , 'show'])->name('show');
            Route::get('/{id}/details', [App\Http\Controllers\Admin\UserController::class , 'details'])->name('details');
            Route::post('/{id}/toggle-status', [
                App\Http\Controllers\Admin\UserController::class ,
                'toggleStatus',
            ])->name('toggle-status');
        }
        );

        /** Assignment Management Routes */
        Route::prefix('assignments')
            ->name('admin.assignments.')
            ->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\AssignmentController::class , 'index'])->name('index');
            Route::get('/pending', [App\Http\Controllers\Admin\AssignmentController::class , 'pending'])->name(
                'pending',
            );
            Route::get('/accepted', [App\Http\Controllers\Admin\AssignmentController::class , 'accepted'])->name(
                'accepted',
            );
            Route::get('/rejected', [App\Http\Controllers\Admin\AssignmentController::class , 'rejected'])->name(
                'rejected',
            );
            Route::get('/statistics', [App\Http\Controllers\Admin\AssignmentController::class , 'statistics'])->name(
                'statistics',
            );
            Route::get('/{id}', [App\Http\Controllers\Admin\AssignmentController::class , 'show'])->name('show');
            Route::patch('/{id}/status', [
                App\Http\Controllers\Admin\AssignmentController::class ,
                'updateStatus',
            ])->name('update-status');
            Route::patch('/bulk-update', [App\Http\Controllers\Admin\AssignmentController::class , 'bulkUpdate'])->name(
                'bulk-update',
            );
            Route::get('/dashboard/data', [
                App\Http\Controllers\Admin\AssignmentController::class ,
                'getDashboardData',
            ])->name('dashboard.data');
        }
        );

        /** Reports Routes */
        Route::prefix('reports')->group(function (): void {
            Route::get('/sales', [ReportsController::class , 'sales'])->name('reports.sales');
            Route::get('/commission', [ReportsController::class , 'commission'])->name('reports.commission');
            Route::get('/course', [ReportsController::class , 'course'])->name('reports.course');
            Route::get('/instructor', [ReportsController::class , 'instructor'])->name('reports.instructor');
            Route::get('/enrollment', [ReportsController::class , 'enrollment'])->name('reports.enrollment');
            Route::get('/revenue', [ReportsController::class , 'revenue'])->name('reports.revenue');

            // AJAX endpoints for reports data
            Route::get('/filters', [ReportsController::class , 'getReportFilters'])->name('reports.filters');
            Route::get('/sales-data', [ReportsController::class , 'getSalesReportData'])->name('reports.sales.data');
            Route::get('/commission-data', [ReportsController::class , 'getCommissionReportData'])->name(
                'reports.commission.data',
            );
            Route::get('/course-data', [ReportsController::class , 'getCourseReportData'])->name('reports.course.data');
            Route::get('/instructor-data', [ReportsController::class , 'getInstructorReportData'])->name(
                'reports.instructor.data',
            );
            Route::get('/enrollment-data', [ReportsController::class , 'getEnrollmentReportData'])->name(
                'reports.enrollment.data',
            );
            Route::get('/revenue-data', [ReportsController::class , 'getRevenueReportData'])->name('reports.revenue.data');
            Route::post('/sales/export', [ReportsController::class , 'exportSalesReport'])->name('reports.sales.export');
            Route::post('/commission/export', [ReportsController::class , 'exportCommissionReport'])->name(
                'reports.commission.export',
            );
            Route::post('/course/export', [ReportsController::class , 'exportCourseReport'])->name('reports.course.export');
            Route::post('/instructor/export', [ReportsController::class , 'exportInstructorReport'])->name(
                'reports.instructor.export',
            );
            Route::post('/enrollment/export', [ReportsController::class , 'exportEnrollmentReport'])->name(
                'reports.enrollment.export',
            );
            Route::post('/revenue/export', [ReportsController::class , 'exportRevenueReport'])->name(
                'reports.revenue.export',
            );
        }
        );

        /** Profile Routes */
        Route::get('profile', [ProfileController::class , 'index'])->name('admin.profile');
        Route::post('profile', [ProfileController::class , 'update'])->name('admin.profile.update');

        /** Logout */
        Route::get('logout', [AuthController::class , 'logout'])->name('admin.logout');

    /***************************************************************************************************** */
    });

// Other Common Routes
Route::group(['prefix' => 'common'], static function (): void {
    Route::get('/js/lang', [Controller::class , 'readLanguageFile'])->name('common.language.read');
    Route::put('/change-status', [Controller::class , 'changeStatus'])->name('common.change-status');
});
/***************************************************************************************************** */

/** Migration Routes */
Route::get('/migrate', static function (): void {
    Artisan::call('migrate');
    $output = Artisan::output();
    echo nl2br($output); // Convert newlines to <br> for better readability in HTML
});
// Route::get('/migrate-rollback', static function () {
//     Artisan::call('migrate:rollback');
//     $output = Artisan::output();
//     echo nl2br($output); // Convert newlines to <br> for better readability in HTML
// });
// Route::get('/migrate-status', static function () {
//     Artisan::call('migrate:status');
//     $output = Artisan::output();
//     echo nl2br($output); // Convert newlines to <br> for better readability in HTML
// });

/***************************************************************************************************** */

/** Seeders Routes */
// Route::get('/seed', static function () {
//     Artisan::call('db:seed');
//     echo Artisan::output();
// });

// Super Admin Seeder Route
Route::get('/seed-superadmin', static function () {
    try {
        Artisan::call('db:seed', ['--class' => 'SuperAdminSeeder']);
        $output = Artisan::output();
        return response()->json([
        'success' => true,
        'message' => 'Super Admin user created successfully!',
        'output' => $output,
        'credentials' => [
        'email' => 'superadmin@elms.com',
        'password' => 'Super@Admin#2024!ELMS',
        ],
        ]);
    }
    catch (\Exception $e) {
        return response()->json([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        ], 500);
    }
})->name('seed.superadmin');
/***************************************************************************************************** */

/** Clear Routes */
Route::get('clear', static function () {
    Artisan::call('config:clear');
    Artisan::call('view:clear');
    Artisan::call('cache:clear');
    Artisan::call('optimize:clear');
    Artisan::call('debugbar:clear');
    return redirect()->back();
});

// Webhook Routes
Route::post('webhook/razorpay', [WebhookController::class , 'razorpay'])->name('webhook.razorpay');
Route::match (['get', 'post'], 'webhooks/kashier', [KashierController::class , 'handleWebhook'])->name('webhooks.kashier');
/***************************************************************************************************** */

/** Storage Link */
Route::get('storage-link', static function () {
    $storageLink = public_path('storage');

    // If storage link already exists, delete it before recreating
    if (File::exists($storageLink)) {
        File::delete($storageLink);
    }

    Artisan::call('storage:link');

    return 'Storage link refreshed';
});

/***************************************************************************************************** */

Route::get('language/view-json/{locale}', function ($locale) {
    $path = resource_path("lang/{$locale}");

    if (!File::exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->name('language.view.json');

// ======================================
// PAYMENT GATEWAY ROUTES
// ======================================
Route::get('/stripe-callback', [StripeController::class , 'handleStripeCallback'])->name('stripe-callback');
Route::get('/flutterwave-callback', [FlutterwaveController::class , 'handleFlutterwaveCallback'])->name(
    'flutterwave-callback',
);
Route::get('/stripe-cancel', [StripeController::class , 'handleStripeCancel'])->name('stripe-cancel');
Route::get('/stripe-status', function (\Illuminate\Http\Request $request) {
    $status = $request->query('status'); // Get the `status` query parameter
    return 'Stripe status: ' . $status; // Print or return the value
});
Route::get('/flutterwave-status', function (\Illuminate\Http\Request $request) {
    $status = $request->query('status'); // Get the `status` query parameter
    return 'Flutterwave status: ' . $status; // Print or return the value
});

// Razorpay Payment Routes
Route::get('/razorpay/payment', [RazorpayController::class , 'showPaymentPage'])->name('razorpay.payment');
Route::post('/razorpay/callback', [RazorpayController::class , 'handleCallback'])->name('razorpay.callback');
Route::get('/razorpay/success', [RazorpayController::class , 'handleSuccess'])->name('razorpay.success');
Route::get('/razorpay/cancel', [RazorpayController::class , 'handleCancel'])->name('razorpay.cancel');

Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class , 'index']);