<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! filter_var(config('telescope.enabled', true), FILTER_VALIDATE_BOOL)) {
            Telescope::stopRecording();
            return;
        }

        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        Telescope::filter(function (IncomingEntry $entry) {
            if ($this->app->environment('local')) {
                return true;
            }

            return $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['password', 'password_confirmation', 'credit_card', 'cvv', 'token']);
        Telescope::hideRequestHeaders(['cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization']);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            // Allow Super Admin role
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole(config('constants.SYSTEM_ROLES.SUPER_ADMIN')) ||
                       $user->hasRole('admin');
            }

            // Allow specific emails
            $allowedEmails = array_filter(explode(',', (string) config('telescope.allowed_emails', '')));
            if (in_array($user->email, $allowedEmails, true)) {
                return true;
            }

            // Allow by user ID
            $allowedIds = array_filter(explode(',', (string) config('telescope.allowed_ids', '')));
            if (in_array((string) $user->id, $allowedIds, true)) {
                return true;
            }

            return false;
        });
    }
}
