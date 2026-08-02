<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Controller;

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

Route::get('storage/{path}', [Controller::class, 'serveStorage'])->where('path', '.*')->name('storage.serve');
// Other Common Routes
Route::group(['prefix' => 'common'], static function (): void {
    Route::get('/js/lang', [Controller::class , 'readLanguageFile'])->name('common.language.read');
    Route::put('/change-status', [Controller::class , 'changeStatus'])->name('common.change-status');
});
/***************************************************************************************************** */

// Fallback API routes without the /api prefix
Route::middleware('api')->group(function () {
    Route::post('user-exists', [\App\Http\Controllers\ApiController::class, 'userExists'])->middleware('throttle:5,1');
    Route::post('user-signup', [\App\Http\Controllers\ApiController::class, 'userSignup'])->middleware('throttle:5,1');
    Route::post('user-login', [\App\Http\Controllers\ApiController::class, 'userLogin'])->middleware('throttle:5,1');
    Route::post('refresh-token', [\App\Http\Controllers\ApiController::class, 'refreshToken'])->middleware('auth:sanctum');
});
/***************************************************************************************************** */

// Developer maintenance helpers must never be registered in production.
if (app()->isLocal()) {
    Route::get('/migrate', static function (): void {
        Artisan::call('migrate');
        echo nl2br(Artisan::output());
    });

    Route::get('/seed-superadmin', static function () {
        Artisan::call('db:seed', ['--class' => 'SuperAdminSeeder']);

        return response()->json([
            'success' => true,
            'message' => 'Super Admin user created successfully!',
            'output' => Artisan::output(),
        ]);
    })->name('seed.superadmin');

    Route::get('clear', static function () {
        Artisan::call('optimize:clear');

        return redirect()->back();
    });

    Route::get('storage-link', static function () {
        $storageLink = public_path('storage');
        if (File::exists($storageLink)) {
            File::delete($storageLink);
        }

        Artisan::call('storage:link');

        return 'Storage link refreshed';
    });
    if (class_exists(\Rap2hpoutre\LaravelLogViewer\LogViewerController::class)) {
        Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);
    }
}
