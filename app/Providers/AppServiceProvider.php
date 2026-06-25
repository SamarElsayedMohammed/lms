<?php

namespace App\Providers;

use App\Services\HelperService;
use App\Services\Mail\MailFromResolver;
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

        $this->configureMailFrom();
    }

    private function configureMailFrom(): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }

            $resolver = app(MailFromResolver::class);

            if (!$resolver->isConfigured()) {
                return;
            }

            $from = $resolver->resolve();

            config([
                'mail.from.address' => $from['address'],
                'mail.from.name' => $from['name'],
            ]);
        } catch (\Throwable) {
            // Expected during installation or when mail is not configured yet
        }
    }
}
