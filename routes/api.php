<?php

use App\Http\Controllers\API\AdminApiController;
use App\Http\Controllers\API\AffiliateApiController;
use App\Http\Controllers\API\BillingDetailsApiController;
use App\Http\Controllers\API\CartApiController;
use App\Http\Controllers\API\CourseApiController;
use App\Http\Controllers\API\CourseChapterApiController;
use App\Http\Controllers\API\CourseDiscussionApiController;
use App\Http\Controllers\API\FinanceApiController;
use App\Http\Controllers\API\HelpdeskApiController;
use App\Http\Controllers\API\HomeApiController;
use App\Http\Controllers\API\InstructorApiController;
use App\Http\Controllers\API\OrderApiController;
use App\Http\Controllers\API\PromoCodeApiController;
use App\Http\Controllers\API\QuizTrackingApiController;
use App\Http\Controllers\API\RatingApiController;
use App\Http\Controllers\API\RefundApiController;
use App\Http\Controllers\API\ReportsApiController;
use App\Http\Controllers\API\SliderApiController;
use App\Http\Controllers\API\SubscriptionApiController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\LectureAttachmentController;
use App\Http\Controllers\API\LectureProgressApiController;
use App\Http\Controllers\API\VideoStreamController;
use App\Http\Controllers\API\WalletApiController;
use App\Http\Controllers\API\WishlistApiController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CourseChaptersController;
use App\Http\Controllers\CoursesController;
use App\Http\Middleware\OptionalAuth;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | API Routes
 |--------------------------------------------------------------------------
 |
 | Here is where you can register API routes for your application. These
 | routes are loaded by the RouteServiceProvider and all of them will
 | be assigned to the "api" middleware group. Make something great!
 |
 */

/**
 * User Authentication APIs
 */

Route::post('user-exists', [ApiController::class, 'userExists']);
Route::post('user-signup', [ApiController::class, 'userSignup']);
Route::post('user-login', [ApiController::class, 'userLogin']);
Route::post('refresh-token', [ApiController::class, 'refreshToken'])->middleware('auth:sanctum');
Route::post('social-login/{provider}', [\App\Http\Controllers\API\SocialLoginApiController::class, 'handleSocialLogin']);
Route::post('mobile-login', [ApiController::class, 'mobileLogin']);
Route::post('mobile-registration', [ApiController::class, 'mobileRegistration']);
Route::post('mobile-reset-password', [ApiController::class, 'mobileResetPassword']);
Route::post('admin-login', [ApiController::class, 'adminLogin']);

/********************************************************************************************* */

/**
 * Chatbot APIs (Public - No Auth Required)
 */
Route::prefix('chatbot')->group(function (): void {
    Route::get('/config', [\App\Http\Controllers\API\ChatbotApiController::class, 'getConfig']);
    Route::get('/config/{courseId}', [\App\Http\Controllers\API\ChatbotApiController::class, 'getCourseConfig']);
    Route::post('/faq-answer', [\App\Http\Controllers\API\ChatbotApiController::class, 'getFaqAnswer']);
    Route::post('/message', [\App\Http\Controllers\API\ChatbotApiController::class, 'sendMessage'])
        ->middleware('throttle:30,1'); // Rate limit: 30 messages per minute
    Route::get('/debug', [\App\Http\Controllers\API\ChatbotApiController::class, 'debug']);
});

/********************************************************************************************* */

/**
 * General APIs
 */

Route::get('categories', [ApiController::class, 'getCategories']); // Get Categories
Route::get('get-custom-form-fields', [ApiController::class, 'getCustomFormFields']); // Get Custom Form Fields
Route::get('active-popup', [\App\Http\Controllers\API\PopupCampaignApiController::class, 'getActiveCampaign']); // Get active popup campaign

Route::get('ip-debug', function (Illuminate\Http\Request $request, App\Services\GeoLocationService $geo) {
    return response()->json([
        'detected_country' => $geo->getCountryCodeFromRequest($request),
        'real_ip' => $geo->getRealIpAddress($request),
        'request_ip' => $request->ip(),
        'cf_country' => $request->server('HTTP_CF_IPCOUNTRY'),
        'headers' => collect($request->server())->filter(fn($v, $k) => str_starts_with($k, 'HTTP_'))->toArray()
    ]);
});

Route::post('course-view', [CourseApiController::class, 'courseView']);
Route::get('get-search-suggestions', [CourseApiController::class, 'getSearchSuggestions']);
Route::get('get-quiz-attempt-details', [CourseApiController::class, 'getQuizAttemptDetails']);

Route::get('sales-chart-data', [ApiController::class, 'getSalesChartData']); // Get Sales Chart Data
Route::get('get-sliders', [SliderApiController::class, 'getSliders']); // Get Sliders

Route::get('get-course-languages', [CourseApiController::class, 'getCourseLanguages']); // Get Course Languages
Route::get('get-tags', [CourseApiController::class, 'getCourseTags']); // Get Course Tags
Route::get('get-counts', [HomeApiController::class, 'getCounts']);
Route::get('marketing-pixels/active', [App\Http\Controllers\API\MarketingPixelApiController::class, 'getActivePixels']);
Route::get('get-categories-with-course-count', [HomeApiController::class, 'getCategoriesWithCourseCount']); // Get categories with courses count
// settings APIs
Route::get('app-settings', [ApiController::class, 'getAppSettings']); // Get App Settings
Route::get('web-settings', [ApiController::class, 'getWebSettings']); // Get Web Settings
Route::get('why-choose-us', [ApiController::class, 'getWhyChooseUs']); // Get Why Choose Us
Route::get('become-instructor', [ApiController::class, 'getBecomeInstructor']); // Get Become Instructor
Route::get('system-languages', [ApiController::class, 'getSystemLanguages']);
Route::get('faqs', [ApiController::class, 'getFaqs']); // Get FAQs (site-wide)
Route::get('courses/{courseId}/faqs', [\App\Http\Controllers\API\CourseFaqPublicApiController::class, 'index']); // Get Course FAQs (public)
Route::get('pages', [ApiController::class, 'getPages']); // Get Pages (with optional type and language_id filters)
Route::get('seo-settings', [ApiController::class, 'getSeoSettings']); // Get SEO Settings (with optional type, language_id, and language_code filters)

// Public certificate verification — returns only safe fields, no auth needed
Route::get('certificate/verify', [CertificateController::class, 'verifyApi']);

Route::prefix('helpdesk')->group(function (): void {
    Route::get('groups', [HelpdeskApiController::class, 'groups']);
    Route::get('group-details', [HelpdeskApiController::class, 'getGroupDetails']);
    Route::get('check-group-approval', [HelpdeskApiController::class, 'checkGroupApproval']); // Check if user is approved for group
    Route::get('questions', [HelpdeskApiController::class, 'questions']);
    Route::get('question', [HelpdeskApiController::class, 'showQuestion']);
    Route::get('search', [HelpdeskApiController::class, 'search']);
});
Route::post('contact-us', [ApiController::class, 'submitContactForm']); // Submit Contact Us Form
Route::post('become-instructor', [ApiController::class, 'submitBecomeInstructor']); // Submit Become an Instructor Form

/**
 * Subscription APIs
 */
Route::prefix('subscription')->group(function (): void {
    // Public - no auth required
    Route::get('/plans', [SubscriptionApiController::class, 'getPlans']);
    
    // Authenticated
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/my-subscription', [SubscriptionApiController::class, 'getMySubscription']);
        Route::post('/subscribe', [SubscriptionApiController::class, 'subscribe']);
        Route::post('/renew', [SubscriptionApiController::class, 'renew']);
        Route::post('/cancel', [SubscriptionApiController::class, 'cancel']);
        Route::get('/history', [SubscriptionApiController::class, 'getHistory']);
        Route::post('/settings', [SubscriptionApiController::class, 'updateSettings']);
    });
});

/**
 * Affiliate APIs
 * Feature flag checked in controller; returns 404 when disabled.
 */
Route::prefix('affiliate')->group(function (): void {
    Route::get('status', [AffiliateApiController::class, 'status']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('my-link', [AffiliateApiController::class, 'getMyLink']);
        Route::get('stats', [AffiliateApiController::class, 'getStats']);
        Route::get('commissions', [AffiliateApiController::class, 'getCommissions']);
        Route::post('withdraw', [AffiliateApiController::class, 'requestWithdrawal']);
        Route::post('transfer-to-wallet', [AffiliateApiController::class, 'transferToWallet']);
        Route::get('withdrawals', [AffiliateApiController::class, 'getWithdrawals']);
        Route::get('referrals', [AffiliateApiController::class, 'getReferrals']);
        Route::get('marketing-assets', [AffiliateApiController::class, 'getMarketingAssets']);
    });
});
Route::get('ref/{code}', [AffiliateApiController::class, 'trackReferral'])->where('code', '[A-Za-z0-9]+');

/********************************************************************************************* */

/**
 * Authenticated APIs
 */

Route::middleware(OptionalAuth::class)->group(function (): void {
    Route::get('get-feature-sections', [HomeApiController::class, 'getFeatureSections']);
    Route::get('get-courses', [CourseApiController::class, 'getCourses']);
    Route::get('get-course', [CourseApiController::class, 'getCourse']);
    Route::get('get-course-chapters', [CourseChapterApiController::class, 'getCourseChapters']); // Get Course Chapters
    Route::get('get-course-reviews', [CourseApiController::class, 'getCourseReviews']); // Get Course Reviews
    Route::get('get-instructor-reviews', [CourseApiController::class, 'getInstructorReviews']); // Get Instructor Reviews
    Route::get('get-instructors', [InstructorApiController::class, 'getInstructors']); // Get Instructors (with optional auth)
    Route::get('get-instructor-details', [InstructorApiController::class, 'getInstructorDetails']); // Get Instructor Details by ID or Slug
});

/**
 * HLS Video Streaming - Serve manifest and segments with UUID token validation
 * No auth required - UUID token provides access control
 * Rate limited to prevent abuse (300 requests per minute per IP)
 */
Route::options('/hls/{uuid}/{path?}', function () {
    return response('', 200, [
        'Access-Control-Allow-Origin' => request()->header('Origin') ?? '*',
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
        'Access-Control-Max-Age' => '86400',
    ]);
})->where('path', '.*');

Route::get('/hls/{uuid}/{path?}', [VideoStreamController::class, 'serve'])
    ->name('api.hls.serve')
    ->where('path', '.*')
    ->middleware('throttle:300,1');

Route::get('dashboard-data', [\App\Http\Controllers\API\DashboardController::class, 'getDashboardData']);
Route::get('dashboard-charts', [\App\Http\Controllers\API\DashboardController::class, 'getChartsData']);

Route::middleware('auth:sanctum')->group(function (): void {
    /**
     * HLS Video Streaming - Generate UUID token for authenticated users
     * Rate limited to 10 token generations per minute per user
     */
    Route::get('/video/{lectureId}/stream', [VideoStreamController::class, 'stream'])->name(
        'api.video.stream',
    )->middleware('throttle:10,1');

    // Video progress tracking (85% rule)
    Route::post('/lecture/{lectureId}/progress', [LectureProgressApiController::class, 'updateProgress'])
        ->middleware('throttle:10,1');
    Route::get('/lecture/{lectureId}/progress', [LectureProgressApiController::class, 'getProgress']);
    Route::get('/course/{courseId}/progress', [LectureProgressApiController::class, 'getCourseProgress']);

    // Lecture attachments (user-facing, gated by feature flag)
    Route::get('/lecture/{lectureId}/attachments', [CourseChapterApiController::class, 'getLectureAttachments']);

    // Handle CORS preflight for video streaming
    Route::options('/video/{lectureId}/stream', function () {
        return response('', 200, [
            'Access-Control-Allow-Origin' => request()->header('Origin') ?? '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Max-Age' => '86400',
        ]);
    });

    /**
     * User APIs
     */
    Route::get('user/credit-cards', [\App\Http\Controllers\API\UserCreditCardApiController::class, 'index']);
    Route::post('user/credit-cards', [\App\Http\Controllers\API\UserCreditCardApiController::class, 'store']);
    Route::put('user/credit-cards/{id}', [\App\Http\Controllers\API\UserCreditCardApiController::class, 'update']);
    Route::delete('user/credit-cards/{id}', [\App\Http\Controllers\API\UserCreditCardApiController::class, 'destroy']);
    Route::post('user/credit-cards/{id}/set-default', [\App\Http\Controllers\API\UserCreditCardApiController::class, 'setDefault']);
    
    Route::get('get-assignments', [CourseChapterApiController::class, 'getAssignmentSubmissionHistory']); // Get Assignment Submission History
    Route::get('get-resources', [CourseApiController::class, 'getResources']); // Get Course Resources
    Route::post('instructor/update-details', [InstructorApiController::class, 'updateDetails']);
    Route::get('get-user-details', [ApiController::class, 'getUserDetails']); // Get User Details
    Route::get('is-email-exist', [ApiController::class, 'isEmailExist']); // Check if logged-in user's email exists
    Route::post('update-profile', [ApiController::class, 'updateProfile']); // Update User Profile (handles both user and instructor details)
    Route::post('change-password', [ApiController::class, 'changePassword']); // Change User Password
    Route::get('notifications', [ApiController::class, 'getUserNotifications']); // Get User Notifications
    Route::post('notifications/mark-read', [ApiController::class, 'markNotificationAsRead']); // Mark Notification as Read
    Route::post('notifications/mark-all-read', [ApiController::class, 'markAllNotificationsAsRead']); // Mark All Notifications as Read
    Route::post('delete-account', [ApiController::class, 'deleteAccount']); // Delete User Account
    Route::get('contact-messages', [ApiController::class, 'userContactMessages']); // Get User Contact Messages
    Route::get('user-enrolled-courses', [CourseApiController::class, 'getUserEnrolledCourses']); // Get User Courses
    Route::get('my-learning', [CourseApiController::class, 'getMyLearning']); // Get My Learning Courses with Progress
    Route::get('user/dashboard', [\App\Http\Controllers\API\User\UserDashboardApiController::class, 'getDashboardData']); // User Dashboard API
    Route::get('user/certificates', [\App\Http\Controllers\API\User\UserReportApiController::class, 'getUserCertificates']); // User Certificates List
    Route::get('user/learning-stats', [\App\Http\Controllers\API\User\UserReportApiController::class, 'getLearningStats']); // Student learning progress summary
    Route::get('reports/comprehensive', [\App\Http\Controllers\API\User\UserReportApiController::class, 'getComprehensiveReport']); // Comprehensive User Report
    
    // Course Chatbot (AI — per course, subscribers only)
    Route::post('chatbot/course-message', [\App\Http\Controllers\API\ChatbotApiController::class, 'sendCourseMessage'])
        ->middleware('throttle:30,1');
    
    // Chat Conversations (Threads)
    Route::get('chatbot/conversations', [\App\Http\Controllers\API\ChatbotApiController::class, 'getConversations']);
    Route::get('chatbot/conversations/{id}', [\App\Http\Controllers\API\ChatbotApiController::class, 'getConversationMessages']);

    // Financial Transactions (Unified)
    Route::get('user/financial-transactions', [\App\Http\Controllers\API\User\UserFinanceReportApiController::class, 'getFinancialTransactions']);

    // Account Security & Sessions
    Route::get('user/active-sessions', [ApiController::class, 'getActiveSessions']);
    Route::post('user/active-sessions/{id}/logout', [ApiController::class, 'logoutSession']);

    // Notification Settings
    Route::get('user/notification-settings', [ApiController::class, 'getNotificationSettings']);
    Route::post('user/notification-settings', [ApiController::class, 'updateNotificationSettings']);

    // Countries (SPA بدون admin/ — نفس المتحكم؛ القائمة: GET countries فقط)
    Route::get('countries', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'index']);
    Route::get('countries/{id}', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'show']);
    Route::post('countries', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'store']);
    Route::match(['put', 'patch'], 'countries/{id}', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'update']);

    // Carts
    Route::group(['prefix' => 'cart'], function (): void {
        Route::get('/', [CartApiController::class, 'getUserCart']);
        Route::post('/add', [CartApiController::class, 'addToCart']);
        Route::post('/remove', [CartApiController::class, 'removeFromCart']);
        Route::post('/clear', [CartApiController::class, 'clearCart']);
        Route::post('/apply-promo', [CartApiController::class, 'applyPromoCodeToCart']);
        Route::post('/remove-promo', [CartApiController::class, 'removePromoCode']);
    });

    Route::group(['prefix' => 'billing-details'], static function (): void {
        Route::get('/', [BillingDetailsApiController::class, 'show']);
        Route::post('/', [BillingDetailsApiController::class, 'store']);
        Route::patch('/', [BillingDetailsApiController::class, 'update']);
    });

    // Tracking
    Route::group(['prefix' => 'track'], function (): void {
        Route::post('/course', [CourseApiController::class, 'userTrackCourse']);
        Route::post('/course-chapter', [CourseChapterApiController::class, 'trackCourseChapter']);
    });

    // Curriculum Tracking
    Route::group(['prefix' => 'curriculum'], function (): void {
        Route::get('/progress', [CourseChapterApiController::class, 'getCurriculumProgress']);
        Route::get('/chapter-details', [CourseChapterApiController::class, 'getChapterCurriculumDetails']);
        Route::post('/mark-completed', [CourseChapterApiController::class, 'markCurriculumItemCompleted']);
        Route::get('/detailed-tracking', [CourseChapterApiController::class, 'getDetailedCurriculumTracking']);
        Route::get('/current', [CourseChapterApiController::class, 'getCurrentCurriculum']);
        Route::get('/course-completion', [CourseChapterApiController::class, 'checkCourseCompletion']);
    });

    // Tracking
    Route::group(['prefix' => 'quiz'], function (): void {
        Route::post('/start', [QuizTrackingApiController::class, 'startAttempt']);
        Route::post('/answer', [QuizTrackingApiController::class, 'storeAnswer']);
        Route::post('/finish', [QuizTrackingApiController::class, 'finishAttempt']);

        Route::get('/quiz', [QuizTrackingApiController::class, 'getQuizDetails']);
        Route::get('/quiz/attempts', [QuizTrackingApiController::class, 'getUserAttempts']);
        Route::get('/quiz/attempt', [QuizTrackingApiController::class, 'getAttemptDetails']);
        Route::get('/summary', [QuizTrackingApiController::class, 'getQuizSummary']);
    });

    // Discuss
    Route::group(['prefix' => 'discussion'], function (): void {
        Route::get('/course', [CourseDiscussionApiController::class, 'getCourseDiscussion']);
        Route::post('/course', [CourseDiscussionApiController::class, 'storeCourseDiscussion']);
    });

    //order
    Route::get('/orders', [OrderApiController::class, 'getOrder']);
    Route::post('/place_order', [OrderApiController::class, 'placeOrder']);
    Route::post('/purchase-certificate', [OrderApiController::class, 'purchaseCertificate']);
    Route::post('/download-invoice', [OrderApiController::class, 'downloadInvoice']);
    Route::get('/invoice-data', [OrderApiController::class, 'getInvoiceData']);
    Route::get('/test-invoice', [OrderApiController::class, 'testInvoiceDownload']);

    // Wallet & Withdrawal
    Route::group(['prefix' => 'wallet'], function (): void {
        Route::get('/summary', [WalletApiController::class, 'getWalletSummary']); // Get wallet summary
        Route::get('/overview', [WalletApiController::class, 'getWalletSummary']); // Alias
        Route::get('/history', [WalletApiController::class, 'getWalletHistory']); // Get wallet history
        Route::get('/transactions', [WalletApiController::class, 'getWalletHistory']); // Alias
        Route::post('/top-up', [WalletApiController::class, 'topUp']); // Wallet top-up via Kashier (T095)
        
        // Withdrawals
        Route::get('/withdrawal-methods', [WalletApiController::class, 'getWithdrawalMethods']);
        Route::post('/withdrawal-request', [WalletApiController::class, 'createWithdrawalRequest']); // Create withdrawal request
        Route::post('/withdrawal-requests', [WalletApiController::class, 'createWithdrawalRequest']); // Alias
        Route::get('/withdrawal-requests', [WalletApiController::class, 'getWithdrawalRequests']); // Get withdrawal requests
        Route::get('/withdrawal-request/details', [WalletApiController::class, 'getWithdrawalRequestDetails']); // Get withdrawal request details
        
        // Manual Deposits
        Route::get('/manual-deposits/methods', [\App\Http\Controllers\API\ManualDepositApiController::class, 'getMethods']);
        Route::get('/deposit-methods', [\App\Http\Controllers\API\ManualDepositApiController::class, 'getMethods']);
        Route::post('/deposit-requests', [\App\Http\Controllers\API\ManualDepositApiController::class, 'submitDeposit']);
        
        // Webinars (User)
        Route::get('/webinars', [\App\Http\Controllers\API\WebinarApiController::class, 'index']);
        Route::get('/webinars/{id}', [\App\Http\Controllers\API\WebinarApiController::class, 'show']);
        Route::post('/webinars/{id}/register', [\App\Http\Controllers\API\WebinarApiController::class, 'register']);
        Route::get('/webinars/{id}/join', [\App\Http\Controllers\API\WebinarApiController::class, 'join']);
    });

    // rating_reviews
    Route::group(['prefix' => 'rating'], function (): void {
        Route::post('/add', [RatingApiController::class, 'addRating']);
        Route::post('/update', [RatingApiController::class, 'updateRating']);
        Route::delete('/delete', [RatingApiController::class, 'deleteRating']);
    });

    // promo_code
    Route::group(['prefix' => 'promo-code'], function (): void {
        Route::get('/by-course', [PromoCodeApiController::class, 'getPromoCodesByCourse']);
        Route::get('/for-course', [PromoCodeApiController::class, 'getPromoCodesForCourse']);
        Route::get('/get-valid-list', [PromoCodeApiController::class, 'getValidPromoCodes']);
        Route::post('/apply-promo-code', [PromoCodeApiController::class, 'applyPromoCode']);
        Route::get('/get-applied-list', [PromoCodeApiController::class, 'getAppliedPromoCodes']);
    });

    // Wishlists
    Route::group(['prefix' => 'wishlist'], function (): void {
        Route::get('/', [WishlistApiController::class, 'getWishlist']);
        Route::post('/add-update-wishlist', [WishlistApiController::class, 'addUpdateWishlist']);
    });

    // Assignment Submissions
    Route::group(['prefix' => 'assignments'], function (): void {
        Route::post('/submit', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'submitAssignment',
        ]);
        Route::get('/submissions', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'getUserSubmissions',
        ]);
        Route::get('/submission/{id}', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'getSubmissionDetails',
        ]);
        Route::get('/submission', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'getSubmissionDetails',
        ]); // Query parameter version
        Route::get('/course/{courseId}', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'getCourseAssignments',
        ]);
        Route::post('/submission/{id}', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'updateSubmission',
        ]);
        Route::post('/submission', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'updateSubmission',
        ]); // Query parameter version
        Route::delete('/submission', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'deleteSubmission',
        ]); // Query parameter version
        Route::delete('/submission/{id}', [
            \App\Http\Controllers\API\UserAssignmentSubmissionController::class,
            'deleteSubmission',
        ]);
    });

    //Certificate
    Route::group(['prefix' => 'certificate'], function (): void {
        Route::get('/course/generate', [CertificateController::class, 'getCertificate']); // Get/Check certificate for course
        Route::post('/course/download', [CertificateController::class, 'download']); // Generate and download certificate PDF
        Route::post('/quiz/generate', [CertificateController::class, 'generateQuizCertificate']);
    });

    /********************************************************************************** */

    /**
     * Instructor Panel APIs
     * All routes require active user and non-suspended instructor status
     */
    Route::middleware([\App\Http\Middleware\CheckInstructorAccess::class])->group(function (): void {
        /**
         * Instructor Panel Course APIs
         */
        Route::get('get-added-courses', [CourseApiController::class, 'getAddedCourses']); // Get Added Courses
        Route::get('get-course-details', [CourseApiController::class, 'getCourseDetails']);
        Route::get('get-course-enrolled-students', [CourseApiController::class, 'getCourseEnrolledStudents']); // Get Course Enrolled Students
        Route::get('get-assignments-list', [CourseApiController::class, 'getCourseAssignmentDetails']); // Get Course Assignment Details
        Route::get('get-assignment-submissions', [CourseApiController::class, 'getCourseAssignmentSubmissions']); // Get Course Assignment Submissions
        Route::put('update-assignment-status', [CourseApiController::class, 'updateAssignmentStatus']); // Update Assignment Status

        Route::get('get-instructor-dashboard', [CourseApiController::class, 'getInstructorDashboard']); // Get Instructor Dashboard
        Route::get('get-quiz-reports', [CourseApiController::class, 'getQuizReports']); // Get Quiz Reports
        Route::get('get-quiz-report-details', [CourseApiController::class, 'getQuizReportDetails']); // Get Detailed Quiz Report
        Route::get('get-quiz-result-details', [CourseApiController::class, 'getQuizResultDetails']); // Get Quiz Result Details (View Result)
        Route::get('get-most-selling-courses', [CourseApiController::class, 'getMostSellingCourses']); // Get Most Selling Courses
        Route::get('get-reviews', [CourseApiController::class, 'getReviews']); // Get Reviews (Course/Instructor/Team Member)
        Route::get('get-discussion', [CourseApiController::class, 'getDiscussion']); // Get Course Discussions (Instructor Panel)
        Route::post('reply-discussion', [CourseApiController::class, 'replyDiscussion']); // Reply to Course Discussion (Instructor Panel)
        Route::post('create-course', [CoursesController::class, 'store']); // Create Course
        Route::put('update-course', [CoursesController::class, 'update']);
        Route::post('update-course-status', [InstructorApiController::class, 'updateCourseStatus']);
        Route::delete('delete-course/{id}', [CoursesController::class, 'destroy']);
        Route::delete('permanent-delete-course', [CourseApiController::class, 'deleteCoursePermanently']); // Permanent Delete Course

        // Course Chapters
        Route::post('create-course-chapter', [CourseChaptersController::class, 'store']);
        Route::put('update-course-chapter', [CourseChaptersController::class, 'update']);
        Route::get('get-added-course-chapters', [CourseChapterApiController::class, 'getAddedCourseChapters']);
        Route::delete('delete-course-chapter', [CourseChapterApiController::class, 'deleteCourseChapter']);
        Route::post('update-curriculum', [CourseChapterApiController::class, 'updateCurriculum']);
        Route::put('/common/change-status', [Controller::class, 'changeStatus']);

        // Instructor Earnings APIs
        Route::get('earnings', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'getInstructorEarnings',
        ]);
        Route::get('sales-statistics', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'getInstructorSalesStatistics',
        ]);
        Route::get('withdrawal-details', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'getWithdrawalDetails',
        ]);
        Route::get('withdrawal-history', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'getWithdrawalHistory',
        ]);
        Route::post('request-withdrawal', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'requestWithdrawal',
        ]);
        Route::get('course-analysis', [
            \App\Http\Controllers\API\InstructorEarningsApiController::class,
            'getCourseAnalysis',
        ]);

        Route::group(['prefix' => 'course-chapters'], function (): void {
            Route::post('curriculum', [CourseChaptersController::class, 'curriculumStore']);
            Route::get('curriculum/list', [CourseChaptersController::class, 'getCurriculumDataList']);
            Route::put('/curriculum/update-order', [CourseChaptersController::class, 'updateRankOfCurriculum']);
            //Route::put('{id}/curriculum/change-status', [CourseChaptersController::class, 'changeCurriculumStatus']);
            Route::get('particular-curriculum/details', [
                CourseChaptersController::class,
                'getParticularCurriculumDetails',
            ]);
            Route::delete('curriculum/destroy', [CourseChaptersController::class, 'curriculumDestroy']);
            Route::get('curriculum/trashed', [CourseChaptersController::class, 'getTrashedCurriculumList']);
            Route::put('curriculum/restore', [CourseChaptersController::class, 'restoreCurriculum']);
            Route::post('curriculum/quiz/add-question', [CourseChaptersController::class, 'quizQuestionsStore']);
            Route::get('curriculum/quiz/get-question', [CourseChaptersController::class, 'quizQuestionGet']);
            Route::post('curriculum/quiz/update-question', [CourseChaptersController::class, 'quizQuestionsUpdate']);
            Route::delete('curriculum/quiz/delete-question', [CourseChaptersController::class, 'quizQuestionsDelete']);
            Route::delete('curriculum/quiz/delete-questions', [
                CourseChaptersController::class,
                'quizQuestionsBulkDelete',
            ]);

            // Update Lecture Curriculum
            Route::put('curriculum/lecture/update', [CourseChaptersController::class, 'curriculumLectureUpdate']);
            // Update Resource Curriculum
            Route::put('curriculum/resource/update', [CourseChaptersController::class, 'curriculumResourceUpdate']);
            // Update Quiz Curriculum
            Route::put('curriculum/quiz/update', [CourseChaptersController::class, 'curriculumQuizUpdate']);
            // Update Assignment Curriculum
            Route::put('curriculum/assignment/update', [CourseChaptersController::class, 'curriculumAssignmentUpdate']);

            // Quiz Questions Routes
            Route::get('quiz/questions/list', [CourseChaptersController::class, 'quizQuestionsList']);
        });

        /**
         * Team Members APIs
         */
        Route::post('add-team-member', [InstructorApiController::class, 'addTeamMember']); // Add Team Member
        Route::get('accept-team-invitation/{token}', [InstructorApiController::class, 'acceptTeamInvitation']); // Accept or Reject Team Invitation (GET for email links)
        Route::post('accept-team-invitation', [InstructorApiController::class, 'acceptTeamInvitation']); // Accept or Reject Team Invitation (POST with invitation_token)
        Route::get('get-pending-invitations', [InstructorApiController::class, 'getPendingInvitations']); // Get Pending Invitations for Logged-in User
        Route::delete('remove-team-member', [InstructorApiController::class, 'removeTeamMember']); // Remove Team Member
        Route::get('team-members', [InstructorApiController::class, 'getTeamMembers']); // Get Team Members
        Route::get('invitors', [InstructorApiController::class, 'getInvitors']); // Get Invitors (Teams where user is a member)
        Route::get('commissions', [InstructorApiController::class, 'getCommissions']); // Get Instructor Commissions
        Route::get('wallet-details', [InstructorApiController::class, 'getWalletDetails']); // Get Instructor Wallet
        Route::get('wallet-history', [InstructorApiController::class, 'getWalletHistory']); // Get Instructor Wallet History
        Route::get('get-categories', [InstructorApiController::class, 'getCategories']); // Get Categories for Instructor Panel

        /**
         * Promo Code APIs
         */
        Route::post('add-promo-code', [InstructorApiController::class, 'storePromoCodeByInstructor']); // Add Promo Code
        Route::get('promo-codes', [InstructorApiController::class, 'getPromoCodesByInstructor']); // List Promo Codes
        Route::get('promo-code', [InstructorApiController::class, 'getPromoCodeByInstructor']); // Get Single Promo Code
        Route::put('promo-code', [InstructorApiController::class, 'updatePromoCodeByInstructor']); // Update Promo Code
        Route::delete('promo-code', [InstructorApiController::class, 'deletePromoCodeByInstructor']); // Delete Promo Code
        Route::get('get-courses-for-coupon', [InstructorApiController::class, 'getCoursesForCoupon']);

        /**
         * Assignment Management APIs
         */
        Route::get('assignment-submissions', [InstructorApiController::class, 'getAssignmentSubmissions']); // Get Assignment Submissions
        Route::get('assignment-submission/{id}', [InstructorApiController::class, 'getAssignmentSubmissionDetails']); // Get Assignment Submission Details
        Route::get('assignment-submission', [InstructorApiController::class, 'getAssignmentSubmissionDetails']); // Get Assignment Submission Details (Query param)
        Route::put('edit-assignment-submission', [InstructorApiController::class, 'updateAssignmentSubmission']); // Update Assignment Submission Status
    });

    /**
     * Admin Assignment Management APIs
     */
    Route::get('admin/assignment-submissions', [AdminApiController::class, 'getAssignmentSubmissions']); // Get All Assignment Submissions (Admin)
    Route::get('admin/assignment-submission/{id}', [AdminApiController::class, 'getAssignmentSubmissionDetails']); // Get Assignment Submission Details (Admin)
    Route::get('admin/assignment-submission', [AdminApiController::class, 'getAssignmentSubmissionDetails']); // Get Assignment Submission Details (Admin Query param)
    Route::put('admin/assignment-submission', [AdminApiController::class, 'updateAssignmentSubmission']); // Update Assignment Submission Status (Admin)
    Route::put('admin/assignment-submissions/bulk-update', [
        AdminApiController::class,
        'bulkUpdateAssignmentSubmissions',
    ]); // Bulk Update Assignment Submissions (Admin)
    Route::get('admin/assignment-statistics', [AdminApiController::class, 'getAssignmentStatistics']); // Get Assignment Statistics (Admin)

    // Admin lecture attachments
    Route::post('admin/lecture/{lectureId}/attachments', [LectureAttachmentController::class, 'store']);
    Route::delete('admin/lecture/{lectureId}/attachments/{attachmentId}', [LectureAttachmentController::class, 'destroy']);

    // Admin subscription plan management (T018)
    Route::prefix('admin/subscription-plans')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'store']);
        Route::get('/{subscriptionPlan}', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'show']);
        Route::put('/{subscriptionPlan}', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'update']);
        Route::delete('/{subscriptionPlan}', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'destroy']);
        Route::post('/{id}/restore', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'restore']);
        Route::delete('/{id}/trash', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'trash']);

        Route::post('/{id}/toggle', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'toggleStatus']);
        Route::put('/sort', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'updateSortOrder']);
    });

    // Admin approval management (T029)
    Route::prefix('admin/reviews')->group(function (): void {
        Route::get('/pending', [\App\Http\Controllers\Admin\ApprovalController::class, 'pendingRatings']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Admin\ApprovalController::class, 'approveRating']);
        Route::post('/{id}/reject', [\App\Http\Controllers\Admin\ApprovalController::class, 'rejectRating']);
    });
    Route::prefix('admin/comments')->group(function (): void {
        Route::get('/pending', [\App\Http\Controllers\Admin\ApprovalController::class, 'pendingComments']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Admin\ApprovalController::class, 'approveComment']);
        Route::post('/{id}/reject', [\App\Http\Controllers\Admin\ApprovalController::class, 'rejectComment']);
    });

    // Admin affiliate management
    Route::prefix('admin/affiliate')->group(function (): void {
        Route::get('settings', [AffiliateController::class, 'settings']);
        Route::put('settings', [AffiliateController::class, 'updateSettings']);
        Route::get('withdrawals/pending', [AffiliateController::class, 'pendingWithdrawals']);
        Route::post('withdrawals/{id}/approve', [AffiliateController::class, 'approveWithdrawal']);
        Route::post('withdrawals/{id}/reject', [AffiliateController::class, 'rejectWithdrawal']);

        // Admin Popup Campaigns
        Route::prefix('popup-campaigns')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'store']);
            Route::get('/trashed', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'trashed']);
            Route::get('/{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'destroy']);
            Route::put('/{id}/restore', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'restore']);
        });
        Route::get('commissions', [AffiliateController::class, 'allCommissions']);
        Route::get('stats', [AffiliateController::class, 'stats']);
    });

    /**
     * Admin Dashboard CRUD APIs
     */
    Route::prefix('admin')->group(function (): void {
        // Notification Global Settings
        Route::get('settings/notifications', [\App\Http\Controllers\API\Admin\NotificationSettingsAdminApiController::class, 'getSettings']);
        Route::put('settings/notifications', [\App\Http\Controllers\API\Admin\NotificationSettingsAdminApiController::class, 'updateSettings']);

        // Categories
        Route::get('categories', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'index']);
        Route::post('categories', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'store']);
        Route::post('categories/reorder', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'reorder']);
        Route::post('categories/{id}/toggle-featured', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'toggleFeatured']);
        Route::get('categories/{id}', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'show']);
        Route::put('categories/{id}', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'update']);
        Route::delete('categories/{id}', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'destroy']);
        Route::put('categories/{id}/restore', [\App\Http\Controllers\API\Admin\CategoryAdminApiController::class, 'restore']);

        // Users
        Route::get('users', [\App\Http\Controllers\API\Admin\UserAdminApiController::class, 'index']);
        Route::get('users/{id}', [\App\Http\Controllers\API\Admin\UserAdminApiController::class, 'show']);
        Route::put('users/{id}', [\App\Http\Controllers\API\Admin\UserAdminApiController::class, 'update']);
        Route::post('users/{id}/toggle-status', [\App\Http\Controllers\API\Admin\UserAdminApiController::class, 'toggleStatus']);

        // Admin User Credit Cards Management
        Route::get('users/{userId}/credit-cards', [\App\Http\Controllers\API\Admin\UserCreditCardAdminApiController::class, 'indexByUserId']);
        Route::post('users/{userId}/credit-cards', [\App\Http\Controllers\API\Admin\UserCreditCardAdminApiController::class, 'storeForUser']);
        Route::put('credit-cards/{id}', [\App\Http\Controllers\API\Admin\UserCreditCardAdminApiController::class, 'update']);
        Route::delete('credit-cards/{id}', [\App\Http\Controllers\API\Admin\UserCreditCardAdminApiController::class, 'destroy']);
        Route::post('credit-cards/{id}/set-default', [\App\Http\Controllers\API\Admin\UserCreditCardAdminApiController::class, 'setDefault']);
        Route::post('users/{id}/assign-role', [\App\Http\Controllers\API\Admin\UserAdminApiController::class, 'assignRole']);

        // User Devices (Admin) — view & revoke registered devices
        Route::get('users/{userId}/devices', [\App\Http\Controllers\API\Admin\UserDeviceAdminApiController::class, 'index']);
        Route::delete('users/{userId}/devices', [\App\Http\Controllers\API\Admin\UserDeviceAdminApiController::class, 'destroyAll']);
        Route::delete('users/{userId}/devices/{deviceId}', [\App\Http\Controllers\API\Admin\UserDeviceAdminApiController::class, 'destroy']);


        // Certificates
        Route::get('certificates', [\App\Http\Controllers\API\Admin\CourseCertificateAdminApiController::class, 'index']);
        Route::post('certificates', [\App\Http\Controllers\API\Admin\CourseCertificateAdminApiController::class, 'store']);
        Route::get('certificates/{id}', [\App\Http\Controllers\API\Admin\CourseCertificateAdminApiController::class, 'show']);
        Route::put('certificates/{id}', [\App\Http\Controllers\API\Admin\CourseCertificateAdminApiController::class, 'update']);
        Route::delete('certificates/{id}', [\App\Http\Controllers\API\Admin\CourseCertificateAdminApiController::class, 'destroy']);

        // Orders
        Route::get('orders', [\App\Http\Controllers\API\Admin\OrderAdminApiController::class, 'index']);
        Route::get('orders/{id}', [\App\Http\Controllers\API\Admin\OrderAdminApiController::class, 'show']);
        Route::put('orders/{id}/status', [\App\Http\Controllers\API\Admin\OrderAdminApiController::class, 'updateStatus']);

        // FAQs
        Route::get('faqs', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'index']);
        Route::post('faqs', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'store']);
        Route::post('faqs/reorder', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'reorder']);
        Route::get('faqs/{id}', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'show']);
        Route::put('faqs/{id}', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'update']);
        Route::delete('faqs/{id}', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'destroy']);
        Route::put('faqs/{id}/restore', [\App\Http\Controllers\API\Admin\FaqAdminApiController::class, 'restore']);

        // Promo Codes
        Route::get('promo-codes', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'index']);
        Route::get('promo-codes/trashed', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'trashed']);
        Route::get('promo-codes/{id}', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'show']);
        Route::post('promo-codes', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'store']);
        Route::put('promo-codes/{id}', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'update']);
        Route::delete('promo-codes/{id}', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'destroy']);
        Route::put('promo-codes/{id}/restore', [\App\Http\Controllers\API\Admin\PromoCodeAdminApiController::class, 'restore']);

        // Countries
        Route::get('countries', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'index']);
        Route::get('countries/{id}', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'show']);
        Route::post('countries', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'store']);
        Route::put('countries/{id}', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'update']);
        Route::delete('countries/{id}', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'destroy']);
        Route::post('countries/{id}/toggle-status', [\App\Http\Controllers\API\Admin\CountryAdminApiController::class, 'toggleStatus']);

        // Subscription plan (SPA admin — same path as curl: .../admin/subscription-plan)
        Route::post('subscription-plan', [\App\Http\Controllers\API\Admin\SubscriptionPlanAdminApiController::class, 'store']);

        // Currencies (SupportedCurrency)
        Route::get('currencies', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'index']);
        Route::post('currencies/refresh-rates', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'refreshRates']);
        Route::get('currencies/{id}', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'show']);
        Route::post('currencies', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'store']);
        Route::put('currencies/{id}', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'update']);
        Route::delete('currencies/{id}', [\App\Http\Controllers\API\Admin\CurrencyAdminApiController::class, 'destroy']);

        // Contact Messages
        Route::get('contact-messages', [\App\Http\Controllers\API\Admin\ContactMessageAdminApiController::class, 'index']);
        Route::get('contact-messages/{id}', [\App\Http\Controllers\API\Admin\ContactMessageAdminApiController::class, 'show']);
        Route::put('contact-messages/{id}/read', [\App\Http\Controllers\API\Admin\ContactMessageAdminApiController::class, 'markRead']);
        Route::put('contact-messages/{id}/status', [\App\Http\Controllers\API\Admin\ContactMessageAdminApiController::class, 'updateStatus']);
        Route::post('contact-messages/{id}/reply', [\App\Http\Controllers\API\Admin\ContactMessageAdminApiController::class, 'reply']);

        // Enrollments
        Route::get('enrollments', [\App\Http\Controllers\API\Admin\EnrollmentAdminApiController::class, 'index']);
        Route::get('enrollments/{id}', [\App\Http\Controllers\API\Admin\EnrollmentAdminApiController::class, 'show']);
        // Admin: Download student certificate by enrollment_id
        Route::get('enrollments/{enrollmentId}/certificate/download', [\App\Http\Controllers\API\Admin\AdminCertificateController::class, 'downloadByEnrollment']);

        // Admin: Certificate revoke / restore
        Route::post('certificates/{id}/revoke', [\App\Http\Controllers\API\Admin\AdminCertificateController::class, 'revoke']);
        Route::post('certificates/{id}/restore', [\App\Http\Controllers\API\Admin\AdminCertificateController::class, 'restore']);

        // Courses
        Route::get('courses', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'index']);
        Route::post('courses', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'store']);
        Route::post('courses/import-excel', [\App\Http\Controllers\API\Admin\CourseExcelImportApiController::class, 'store']);
        Route::post('courses/{id}/approve', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'approve']);
        Route::post('courses/{id}/reject', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'reject']);
        Route::put('courses/{id}/restore', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'restore']);
        Route::post('courses/{id}/update', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'update']);
        Route::delete('courses/{id}/chatbot', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'removeAiInfo']);
        Route::post('courses/{id}/toggle-featured', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'toggleFeatured']);

        // Notifications
        Route::get('notifications', [\App\Http\Controllers\API\Admin\NotificationAdminApiController::class, 'index']);
        Route::post('notifications/send-bulk', [\App\Http\Controllers\API\Admin\NotificationAdminApiController::class, 'sendBulkNotification']);
        Route::delete('notifications/{id}', [\App\Http\Controllers\API\Admin\NotificationAdminApiController::class, 'destroy']);
        Route::match(['put', 'patch'], 'courses/{id}', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'update']);
        Route::get('courses/{id}', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'show']);
        Route::get('courses/{id}/students', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'students']);
        Route::delete('courses/{id}', [\App\Http\Controllers\API\Admin\CourseAdminApiController::class, 'destroy']);

        // [14] Course FAQs (Admin / Instructor)
        Route::get('courses/{courseId}/faqs', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'index']);
        Route::post('courses/{courseId}/faqs', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'store']);
        Route::post('courses/{courseId}/faqs/reorder', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'reorder']);
        Route::get('courses/{courseId}/faqs/{id}', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'show']);
        Route::put('courses/{courseId}/faqs/{id}', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'update']);
        Route::delete('courses/{courseId}/faqs/{id}', [\App\Http\Controllers\API\Admin\CourseFaqAdminApiController::class, 'destroy']);

        // Course Country Prices
        Route::get('courses/{id}/country-prices', [\App\Http\Controllers\API\Admin\CourseCountryPricesAdminController::class, 'index']);
        Route::post('courses/{id}/country-prices', [\App\Http\Controllers\API\Admin\CourseCountryPricesAdminController::class, 'store']);
        Route::post('courses/{id}/country-prices/bulk', [\App\Http\Controllers\API\Admin\CourseCountryPricesAdminController::class, 'bulk']);
        Route::delete('courses/{id}/country-prices/{country_code}', [\App\Http\Controllers\API\Admin\CourseCountryPricesAdminController::class, 'destroy']);

        // Instructors
        Route::get('instructors', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'index']);
        Route::post('instructors', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'store']);
        Route::get('instructors/{id}', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'show']);
        Route::put('instructors/{id}', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'update']);
        Route::delete('instructors/{id}', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'destroy']);
        Route::post('instructors/{id}/approve', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'approve']);
        Route::post('instructors/{id}/reject', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'reject']);
        Route::post('instructors/{id}/suspend', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'suspend']);
        Route::post('instructors/{id}/restore', [\App\Http\Controllers\API\Admin\InstructorAdminApiController::class, 'restore']);

        // Tags
        Route::get('tags', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'index']);
        Route::get('tags/{id}', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'show']);
        Route::post('tags', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'store']);
        Route::put('tags/{id}', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'update']);
        Route::delete('tags/{id}', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'destroy']);
        Route::put('tags/{id}/restore', [\App\Http\Controllers\API\Admin\TagAdminApiController::class, 'restore']);

        // Feature Sections
        Route::get('feature-sections', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'index']);
        Route::get('feature-sections/{id}', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'show']);
        Route::post('feature-sections', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'store']);
        Route::put('feature-sections/{id}', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'update']);
        Route::delete('feature-sections/{id}', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'destroy']);
        Route::put('feature-sections/{id}/restore', [\App\Http\Controllers\API\Admin\FeatureSectionAdminApiController::class, 'restore']);

        // Instructor Requests
        Route::get('instructor-requests', [\App\Http\Controllers\API\Admin\InstructorRequestAdminApiController::class, 'index']);
        Route::get('instructor-requests/{id}', [\App\Http\Controllers\API\Admin\InstructorRequestAdminApiController::class, 'show']);
        Route::put('instructor-requests/{id}/status', [\App\Http\Controllers\API\Admin\InstructorRequestAdminApiController::class, 'updateStatus']);
        Route::delete('instructor-requests/{id}', [\App\Http\Controllers\API\Admin\InstructorRequestAdminApiController::class, 'destroy']);

        // Staff & Roles
        Route::prefix('staff')->group(function (): void {
            Route::get('roles', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'index']);
            Route::get('roles/{id}', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'show']);
            Route::post('roles', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'store']);
            Route::put('roles/{id}', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'update']);
            Route::delete('roles/{id}', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'destroy']);
            Route::get('permissions', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'permissions']);
        });

        // Manual Deposit Methods & Requests (Admin)
        Route::prefix('manual-deposits')->group(function (): void {
            Route::get('methods', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'indexMethods']);
            Route::post('methods', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'storeMethod']);
            Route::put('methods/{id}', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'updateMethod']);
            Route::delete('methods/{id}', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'destroyMethod']);
            
            Route::get('/', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'indexDeposits']);
            Route::post('{id}/status', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'updateDepositStatus']);
        });

        // Manual Subscription Management (Admin)
        Route::prefix('manual-subscriptions')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\SubscriptionAdminApiController::class, 'index']);
            Route::post('{id}/approve', [\App\Http\Controllers\API\Admin\SubscriptionAdminApiController::class, 'approve']);
            Route::post('{id}/reject', [\App\Http\Controllers\API\Admin\SubscriptionAdminApiController::class, 'reject']);
        });

        // Comprehensive Subscriptions (Admin)
        Route::prefix('subscriptions')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\SubscriptionAdminApiController::class, 'comprehensiveIndex']);
            // تقرير المشتركين لكل باقة — للسوبر أدمن فقط
            Route::get('/plan-report', [\App\Http\Controllers\API\Admin\SubscriptionAdminApiController::class, 'planReport']);
        });

        // Webinars (Admin/Instructor)
        Route::prefix('webinars')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'update']);
            Route::match(['put', 'patch'], '{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'destroy']);
            Route::post('{id}/change-status', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'updateStatus']);
            Route::post('{id}/cancel', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'cancel']);
            Route::post('{id}/toggle-publish', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'togglePublish']);
            Route::post('{id}/toggle-featured', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'toggleFeatured']);
            Route::get('{id}/registrants', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'registrants']);
            Route::get('{id}/registrants/export', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'exportRegistrants']);
        });

        // Popup Campaigns (Admin)
        Route::prefix('marketing/popups')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'store']);
            Route::get('trashed', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'trashed']);
            Route::get('{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'destroy']);
            Route::put('{id}/restore', [\App\Http\Controllers\API\Admin\PopupCampaignAdminApiController::class, 'restore']);
        });

        // Marketing Pixels (Admin)
        Route::prefix('marketing/pixels')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\API\Admin\MarketingPixelAdminApiController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\API\Admin\MarketingPixelAdminApiController::class, 'store']);
            Route::put('{id}', [\App\Http\Controllers\API\Admin\MarketingPixelAdminApiController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\API\Admin\MarketingPixelAdminApiController::class, 'destroy']);
        });

        // Chatbot Management (Admin)
        Route::prefix('chatbot')->group(function (): void {
            // Settings
            Route::get('/settings', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'getSettings']);
            Route::put('/settings', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'updateSettings']);
            Route::post('/settings', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'updateSettings']); // POST variant for file uploads

            // FAQ Buttons
            Route::get('/faqs', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'indexFaqs']);
            Route::post('/faqs', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'storeFaq']);
            Route::get('/faqs/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'showFaq']);
            Route::put('/faqs/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'updateFaq']);
            Route::delete('/faqs/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'destroyFaq']);
            Route::put('/faqs/{id}/restore', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'restoreFaq']);

            // Knowledge Base
            Route::get('/knowledge', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'indexKnowledge']);
            Route::post('/knowledge', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'storeKnowledge']);
            Route::get('/knowledge/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'showKnowledge']);
            Route::put('/knowledge/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'updateKnowledge']);
            Route::post('/knowledge/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'updateKnowledge']); // POST variant for file uploads
            Route::delete('/knowledge/{id}', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'destroyKnowledge']);
            Route::post('/knowledge/{id}/toggle', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'toggleKnowledge']);

            // Chat History (Logs)
            Route::get('/logs', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'indexLogs']);

            // Test Chat
            Route::post('/test', [\App\Http\Controllers\API\Admin\ChatbotAdminApiController::class, 'testChat']);
        });
    });

// Wallet Funding Suite (Admin Contract - v1) — must stay INSIDE auth:sanctum group
Route::middleware('auth:sanctum')->prefix('v1/admin/wallet')->group(function (): void {
    Route::get('/deposit-requests', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'indexDeposits']);
    Route::get('/withdrawal-requests', [FinanceApiController::class, 'getAdminWithdrawalRequests']);
    Route::put('/deposit-requests/{id}/status', [\App\Http\Controllers\API\Admin\ManualDepositAdminApiController::class, 'updateDepositStatus']);
    Route::put('/withdrawal-requests/{id}/status', [FinanceApiController::class, 'updateWithdrawalRequestStatusViaPath']);
});

    /********************************************************************************** */

    Route::prefix('helpdesk')->group(function (): void {
        Route::post('groups', [HelpdeskApiController::class, 'storeGroup']); // admin
        Route::post('groups/request', [HelpdeskApiController::class, 'requestJoin']);
        Route::post('question', [HelpdeskApiController::class, 'storeQuestion']);
        Route::post('question/reply', [HelpdeskApiController::class, 'storeReply']);
    });

    /*
     * Payment APIs
     */
    Route::post('get-payment-intent', [ApiController::class, 'getPaymentIntent']);

    /*
     * Refund APIs
     */
    Route::prefix('refund')->group(function (): void {
        Route::post('request', [RefundApiController::class, 'requestRefund']);
        Route::get('my-refunds', [RefundApiController::class, 'getUserRefunds']);
        Route::post('check-eligibility', [RefundApiController::class, 'checkRefundEligibility']);
    });
    

    // Finance Management APIs (Admin/Instructor)
    Route::prefix('finance')->group(function (): void {
        Route::get('dashboard', [FinanceApiController::class, 'getFinanceDashboard']); // Admin only
        Route::get('commissions', [FinanceApiController::class, 'getCommissions']); // Admin only
        Route::get('instructor-earnings', [FinanceApiController::class, 'getInstructorEarnings']); // Admin only
        Route::get('wallet-transactions', [FinanceApiController::class, 'getWalletTransactions']); // Admin/User
        Route::post('process-commission', [FinanceApiController::class, 'processCommission']); // Admin only
        Route::get('reports', [FinanceApiController::class, 'getFinanceReports']); // Admin only

        // Instructor Wallet APIs
        Route::get('wallet-summary', [FinanceApiController::class, 'getWalletSummary']); // Instructor
        Route::post('withdrawal-request', [FinanceApiController::class, 'createWithdrawalRequest']); // Instructor
        Route::get('withdrawal-requests', [FinanceApiController::class, 'getWithdrawalRequests']); // Instructor

        // Admin Withdrawal Management APIs
        Route::get('admin/withdrawal-requests', [FinanceApiController::class, 'getAdminWithdrawalRequests']); // Admin
        Route::post('admin/withdrawal-request/update-status', [
            FinanceApiController::class,
            'updateWithdrawalRequestStatus',
        ]); // Admin
        Route::get('admin/withdrawal-request/details', [FinanceApiController::class, 'getWithdrawalRequestDetails']); // Admin
    });

    // Reports APIs (Admin)
    Route::prefix('reports')->group(function (): void {
        Route::get('filters', [ReportsApiController::class, 'getReportFilters']); // Get all filter options
        Route::get('sales', [ReportsApiController::class, 'getSalesReport']); // Sales reports
        Route::get('commission', [ReportsApiController::class, 'getCommissionReport']); // Commission reports
        Route::get('course', [ReportsApiController::class, 'getCourseReport']); // Course reports
        Route::get('instructor', [ReportsApiController::class, 'getInstructorReport']); // Instructor reports
        Route::get('enrollment', [ReportsApiController::class, 'getEnrollmentReport']); // Enrollment reports
        Route::get('revenue', [ReportsApiController::class, 'getRevenueReport']); // Revenue reports
        Route::get('credit-cards-revenue', [ReportsApiController::class, 'getCreditCardRevenue']); // Credit Card Revenue

        // [8] Student Reports
        Route::get('students/completion-stats', [\App\Http\Controllers\API\Admin\StudentReportAdminApiController::class, 'completionStats']);
        Route::get('students/{id}', [\App\Http\Controllers\API\Admin\StudentReportAdminApiController::class, 'show']);
        Route::get('students', [\App\Http\Controllers\API\Admin\StudentReportAdminApiController::class, 'index']);
    });

    // [9] Payment Gateway Settings API (Admin)
    Route::prefix('admin/settings')->group(function (): void {
        Route::get('payment-gateways', [\App\Http\Controllers\API\Admin\PaymentGatewaySettingsAdminApiController::class, 'index']);
        Route::put('payment-gateways', [\App\Http\Controllers\API\Admin\PaymentGatewaySettingsAdminApiController::class, 'update']);
        Route::post('payment-gateways', [\App\Http\Controllers\API\Admin\PaymentGatewaySettingsAdminApiController::class, 'update']); // POST variant
    });


    // Certificate Generation APIs (Requires Authentication)
    Route::post('generate-course-certificate', [CourseApiController::class, 'generateCourseCertificate']); // Generate Course Completion Certificate
    Route::post('generate-exam-certificate', [CourseApiController::class, 'generateExamCertificate']); // Generate Exam Completion Certificate
});

// Certificate Templates (Public - no authentication required)
Route::get('certificate-templates', [CourseApiController::class, 'getCertificateTemplates']); // Get Certificate Templates

/********************************************************************************************* */

/**
 * Instructor Earnings API
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('instructor/earnings', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'getInstructorEarnings',
    ]);
    Route::get('instructor/sales-statistics', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'getInstructorSalesStatistics',
    ]);
    Route::get('instructor/withdrawal-details', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'getWithdrawalDetails',
    ]);
    Route::get('instructor/withdrawal-history', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'getWithdrawalHistory',
    ]);
    Route::post('instructor/request-withdrawal', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'requestWithdrawal',
    ]);
    Route::get('instructor/course-analysis', [
        \App\Http\Controllers\API\InstructorEarningsApiController::class,
        'getCourseAnalysis',
    ]);
});

/**
 * For Development Purposes
 */

Route::delete('remove-user', [ApiController::class, 'removeUser']); // Remove User

/********************************************************************************************* */
