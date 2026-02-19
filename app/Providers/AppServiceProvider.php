<?php

namespace App\Providers;

use App\Services\HelperService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading();

        // Set timezone from database settings
        try {
            // Check if settings table exists
            if (Schema::hasTable('settings')) {
                $timezone = HelperService::systemSettings('timezone');
                if (!empty($timezone)) {
                    date_default_timezone_set($timezone);
                    config(['app.timezone' => $timezone]);
                }
            }
        } catch (\Exception) {
            // If settings table doesn't exist or query fails, use default timezone
            // This is expected during installation
        }
    }
}
