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
Schedule::command('affiliate:release-commissions')->daily();
Schedule::command('subscriptions:send-expiry-notifications')->daily();
Schedule::command('subscriptions:handle-expired')->daily();
