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

            try {
                // Get all available languages first
                $languages = \App\Services\CachingService::getLanguages();

                // Get current language from session
                $currentLanguage = session('language');

                // If no current language or it's not in available languages, use the default language
                if (!$currentLanguage || !$languages->contains('code', $currentLanguage->code)) {
                    // Get the default language from database
                    $currentLanguage = \App\Models\Language::getDefault();

                    // If no default language is set, fallback to English
                    if (!$currentLanguage) {
                        $currentLanguage = $languages->where('code', 'en')->first();
                    }

                    // If English is not available, use the first available language
                    if (!$currentLanguage && $languages->count() > 0) {
                        $currentLanguage = $languages->first();
                    }
                }

                // Get RTL flag from session
                $isRTL = session('rtl', false);

                $view->with('currentLanguage', $currentLanguage);
                $view->with('languages', $languages);
                $view->with('isRTL', $isRTL);
            } catch (\Exception) {
                // If languages table doesn't exist or query fails, provide empty data
                $view->with('currentLanguage', null);
                $view->with('languages', collect([]));
                $view->with('isRTL', false);
            }
        });
    }
}
