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
