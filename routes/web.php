<?php

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

Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class , 'index']);