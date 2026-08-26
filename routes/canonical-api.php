<?php

use Illuminate\Support\Facades\Route;

// Staff Permissions Canonical Path
Route::get('admin/staff/permissions', [\App\Http\Controllers\API\Admin\RoleAdminApiController::class, 'permissions'])
    ->middleware(['auth:sanctum', 'role:Super Admin|Supervisor|Staff']);

// Instructor Wallet History
Route::get('admin/instructor-wallet-history', [\App\Http\Controllers\API\FinanceApiController::class, 'getInstructorEarnings'])
    ->middleware(['auth:sanctum', 'role:Super Admin|Supervisor|Staff']);

// Certificate Verify (Public)
Route::get('certificate/verify', [\App\Http\Controllers\CertificateController::class, 'verifyApi'])->middleware('throttle:10,1');

Route::get('blog', [\App\Http\Controllers\API\PublicBlogController::class, 'index']);
Route::get('blog/{slug}', [\App\Http\Controllers\API\PublicBlogController::class, 'show']);
Route::get('article/{slug}', [\App\Http\Controllers\API\PublicBlogController::class, 'show']);

// Webinar Contract
// Canonical public/admin webinar CRUD lives in routes/api.php (loaded first; first match wins).
// Keep only endpoints that are unique to this file so callers have one source of truth.
Route::post('admin/webinars/{id}/toggle-featured', [\App\Http\Controllers\API\Admin\WebinarAdminApiController::class, 'toggleFeatured'])
    ->middleware(['auth:sanctum', 'role:Super Admin|Supervisor|Staff']);

// Affiliate Contract
Route::post('affiliate/withdraw', [\App\Http\Controllers\API\AffiliateApiController::class, 'requestWithdrawal'])
    ->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'role:Super Admin|Supervisor|Staff'])->group(function () {
    Route::get('admin/subscription-plans', [\App\Http\Controllers\API\SubscriptionApiController::class, 'getPlans']);
    Route::post('admin/subscription-plans', [\App\Http\Controllers\API\Admin\SubscriptionPlanAdminApiController::class, 'store']);
    Route::put('admin/subscription-plans/{subscriptionPlan}', [\App\Http\Controllers\API\Admin\SubscriptionPlanAdminApiController::class, 'update']);
});
Route::get('ref/{code}', [\App\Http\Controllers\API\AffiliateApiController::class, 'trackReferral'])->where('code', '[A-Za-z0-9]+');
Route::post('refresh-token', [\App\Http\Controllers\ApiController::class, 'refreshToken'])->middleware('auth:sanctum');
