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
Route::post('mobile-login', [ApiController::class, 'mobileLogin']);
Route::post('mobile-registration', [ApiController::class, 'mobileRegistration']);
Route::post('mobile-reset-password', [ApiController::class, 'mobileResetPassword']);

/********************************************************************************************* */

/**
 * General APIs
 */

Route::get('categories', [ApiController::class, 'getCategories']); // Get Categories
Route::get('get-custom-form-fields', [ApiController::class, 'getCustomFormFields']); // Get Custom Form Fields

Route::post('course-view', [CourseApiController::class, 'courseView']);
Route::get('get-search-suggestions', [CourseApiController::class, 'getSearchSuggestions']);
Route::get('get-quiz-attempt-details', [CourseApiController::class, 'getQuizAttemptDetails']);

Route::get('sales-chart-data', [ApiController::class, 'getSalesChartData']); // Get Sales Chart Data
Route::get('get-sliders', [SliderApiController::class, 'getSliders']); // Get Sliders

Route::get('get-course-languages', [CourseApiController::class, 'getCourseLanguages']); // Get Course Languages
Route::get('get-tags', [CourseApiController::class, 'getCourseTags']); // Get Course Tags
Route::get('get-counts', [HomeApiController::class, 'getCounts']);
Route::get('marketing-pixels/active', [App\Http\Controllers\API\MarketingPixelApiController::class, 'getActivePixels']);
Route::get('dashboard-data', [\App\Http\Controllers\API\DashboardController::class, 'getDashboardData']); // Get Dashboard Data
Route::get('dashboard-test', fn() => response()->json([
    'status' => true,
    'message' => 'Dashboard API is working!',
    'timestamp' => now()->toISOString(),
])); // Test Dashboard API
Route::get('get-categories-with-course-count', [HomeApiController::class, 'getCategoriesWithCourseCount']); // Get categories with courses count
// settings APIs
Route::get('app-settings', [ApiController::class, 'getAppSettings']); // Get App Settings
Route::get('web-settings', [ApiController::class, 'getWebSettings']); // Get Web Settings
Route::get('why-choose-us', [ApiController::class, 'getWhyChooseUs']); // Get Why Choose Us
Route::get('become-instructor', [ApiController::class, 'getBecomeInstructor']); // Get Become Instructor
Route::get('system-languages', [ApiController::class, 'getSystemLanguages']);
Route::get('faqs', [ApiController::class, 'getFaqs']); // Get FAQs
Route::get('pages', [ApiController::class, 'getPages']); // Get Pages (with optional type and language_id filters)
Route::get('seo-settings', [ApiController::class, 'getSeoSettings']); // Get SEO Settings (with optional type, language_id, and language_code filters)

Route::prefix('helpdesk')->group(function (): void {
    Route::get('groups', [HelpdeskApiController::class, 'groups']);
    Route::get('group-details', [HelpdeskApiController::class, 'getGroupDetails']);
    Route::get('check-group-approval', [HelpdeskApiController::class, 'checkGroupApproval']); // Check if user is approved for group
    Route::get('questions', [HelpdeskApiController::class, 'questions']);
    Route::get('question', [HelpdeskApiController::class, 'showQuestion']);
    Route::get('search', [HelpdeskApiController::class, 'search']);
});
Route::post('contact-us', [ApiController::class, 'submitContactForm']); // Submit Contact Us Form

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
        Route::get('withdrawals', [AffiliateApiController::class, 'getWithdrawals']);
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
    Route::get('user-enrolled-courses', [CourseApiController::class, 'getUserEnrolledCourses']); // Get User Courses
    Route::get('my-learning', [CourseApiController::class, 'getMyLearning']); // Get My Learning Courses with Progress

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
        Route::get('/history', [WalletApiController::class, 'getWalletHistory']); // Get wallet history
        Route::post('/top-up', [WalletApiController::class, 'topUp']); // Wallet top-up via Kashier (T095)
        Route::post('/withdrawal-request', [WalletApiController::class, 'createWithdrawalRequest']); // Create withdrawal request
        Route::get('/withdrawal-requests', [WalletApiController::class, 'getWithdrawalRequests']); // Get withdrawal requests
        Route::get('/withdrawal-request/details', [WalletApiController::class, 'getWithdrawalRequestDetails']); // Get withdrawal request details
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
        Route::post('/{id}/toggle', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'toggleStatus']);
        Route::put('/sort', function (\Illuminate\Http\Request $request) {
            $plans = $request->validate(['plans' => 'required|array', 'plans.*.id' => 'required|exists:subscription_plans,id', 'plans.*.sort_order' => 'required|integer|min:0']);
            foreach ($plans['plans'] as $item) {
                \App\Models\SubscriptionPlan::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
            return response()->json(['success' => true, 'message' => 'Sort order updated']);
        });
        Route::post('/{id}/country-prices', [\App\Http\Controllers\Admin\SubscriptionPlanController::class, 'updateCountryPrices']);
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
        Route::get('commissions', [AffiliateController::class, 'allCommissions']);
        Route::get('stats', [AffiliateController::class, 'stats']);
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
