<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 |--------------------------------------------------------------------------
 | Console Routes
 |--------------------------------------------------------------------------
 |
 | This file is where you may define all of your Closure based console
 | commands. Each Closure is bound to a command instance allowing a
 | simple approach to interacting with each command's IO methods.
 |
 */

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 |--------------------------------------------------------------------------
 | Scheduled Tasks (T090)
 |--------------------------------------------------------------------------
 */
Schedule::command('affiliate:release-commissions')->daily()->withoutOverlapping();
Schedule::command('subscriptions:send-expiry-notifications')->daily()->withoutOverlapping();
Schedule::command('subscriptions:handle-expired')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('subscriptions:activate-queued')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('currencies:update-rates')->everyFourHours()->withoutOverlapping()->runInBackground();
Schedule::command('webinar:remind')->everyFifteenMinutes()->withoutOverlapping();

// Telescope data pruning — every 6 hours, keeps last 24 hours only
Schedule::command('telescope:prune --hours=24')->everySixHours()->withoutOverlapping();

// Feature sections analytics
Schedule::job(new \App\Jobs\FlushFeatureSectionAnalyticsJob)->everyFiveMinutes()->withoutOverlapping();
