<?php

namespace App\Providers;

use App\Services\HelperService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    #[\Override]
    public function register()
    {
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        /*** App Blade File ***/
        View::composer('layouts.app', static function (\Illuminate\View\View $view): void {
            // Skip during installation
            $isInstallerRoute = request()->is('install*') || request()->is('update*');
            $isInstalled = file_exists(storage_path('installed'));

            if ($isInstallerRoute || !$isInstalled) {
                // During installation, provide empty/default values
                $view->with('settingLogos', []);
                $view->with('systemColor', '');
                return;
            }

            try {
                $settingLogos = HelperService::systemSettings(['horizontal_logo', 'vertical_logo', 'favicon']);
                $systemColorSettings = HelperService::systemSettings(['system_color']);
                $systemColor = $systemColorSettings['system_color'] ?? '';

                $view->with('settingLogos', $settingLogos);
                $view->with('systemColor', $systemColor);
            } catch (\Exception) {
                // If settings table doesn't exist or query fails, provide empty values
                $view->with('settingLogos', []);
                $view->with('systemColor', '');
            }
        });

        /*** Language Data for All Views ***/
        View::composer('*', static function (\Illuminate\View\View $view): void {
            // Skip language loading during installation
            $isInstallerRoute = request()->is('install*') || request()->is('update*');
            $isInstalled = file_exists(storage_path('installed'));

            if ($isInstallerRoute || !$isInstalled) {
                // During installation, provide empty/default language data
                $view->with('currentLanguage', null);
                $view->with('languages', collect([]));
                $view->with('isRTL', false);
                return;
            }

            // Force Arabic locale and RTL for admin panel (authenticated users, except login page)
            $isAdminPanel = auth()->check() && !request()->routeIs('login-page');
            if ($isAdminPanel) {
                \Illuminate\Support\Facades\App::setLocale('ar');
            }

            // RTL always true for admin panel (Arabic locale)
            $isRTL = $isAdminPanel || session('rtl', false);

            try {
                $languages = \App\Services\CachingService::getLanguages();

                $currentLanguage = session('language');
                $currentCode = null;
                if (is_object($currentLanguage) && isset($currentLanguage->code)) {
                    $currentCode = is_string($currentLanguage->code) ? $currentLanguage->code : null;
                } elseif (is_string($currentLanguage) && $currentLanguage !== '') {
                    $currentCode = $currentLanguage;
                    $currentLanguage = $languages->firstWhere('code', $currentCode);
                }

                if (!$currentCode || !$languages->contains('code', $currentCode)) {
                    $currentLanguage = \App\Models\Language::getDefault();

                    if (!$currentLanguage) {
                        $currentLanguage = $languages->where('code', 'en')->first();
                    }

                    if (!$currentLanguage && $languages->count() > 0) {
                        $currentLanguage = $languages->first();
                    }
                }

                if (app()->getLocale() === 'ar') {
                    $isRTL = true;
                    $currentLanguage = $currentLanguage ?? (object) ['code' => 'ar', 'name' => 'العربية'];
                }

                $view->with('currentLanguage', $currentLanguage);
                $view->with('languages', $languages);
                $view->with('isRTL', $isRTL);
            } catch (\Throwable) {
                // If languages table doesn't exist or query fails, provide empty data
                $view->with('currentLanguage', $isAdminPanel ? (object) ['code' => 'ar', 'name' => 'العربية'] : null);
                $view->with('languages', collect([]));
                $view->with('isRTL', $isRTL);
            }
        });
    }
}
