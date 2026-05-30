<?php

use Illuminate\Support\Facades\Route;

// Staff Permissions Canonical Path
Route::get('admin/staff/permissions', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'permissions']);

// Instructor Wallet History
Route::get('admin/instructor-wallet-history', [\App\Http\Controllers\API\FinanceApiController::class, 'getInstructorEarnings']);

// Certificate Verify (Public)
Route::get('certificate/verify', [\App\Http\Controllers\API\CertificateApiController::class, 'verifyPublic']);

// Webinar Contract
Route::get('webinars', [\App\Http\Controllers\API\WebinarApiController::class, 'index']);
Route::get('webinars/{id}', [\App\Http\Controllers\API\WebinarApiController::class, 'show']);
Route::post('webinars/{id}/register', [\App\Http\Controllers\API\WebinarApiController::class, 'register']);

Route::prefix('admin/webinars')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'store']);
    Route::get('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'show']);
    Route::put('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'update']);
    Route::patch('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'destroy']);
    Route::post('{id}/change-status', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'updateStatus']);
    Route::post('{id}/cancel', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'cancel']);
    Route::post('{id}/toggle-publish', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'togglePublish']);
    Route::post('{id}/toggle-featured', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'toggleFeatured']);
    Route::get('{id}/registrants', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'registrants']);
    Route::get('{id}/registrants/export', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'exportRegistrants']);
});

// Affiliate Contract
Route::post('affiliate/withdraw', [\App\Http\Controllers\API\AffiliateApiController::class, 'requestWithdrawal']);
Route::get('admin/subscription-plans', [\App\Http\Controllers\API\SubscriptionApiController::class, 'getPlans']);
Route::post('admin/subscription-plans', [\App\Http\Controllers\API\Admin\SubscriptionPlanAdminApiController::class, 'store']);
Route::get('ref/{code}', [\App\Http\Controllers\API\AffiliateApiController::class, 'trackReferral'])->where('code', '[A-Za-z0-9]+');
