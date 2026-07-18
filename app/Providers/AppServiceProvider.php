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
        Model::preventLazyLoading(! $this->app->isProduction());

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

        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(function ($notifiable, $url) {
            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('تأكيد البريد الإلكتروني | Verify Email Address')
                ->view('emails.verify-email', ['verifyUrl' => $url]);
        });

        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function ($notifiable, $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('إعادة تعيين كلمة المرور | Reset Password')
                ->view('emails.reset-password', ['resetUrl' => $url]);
        });
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
