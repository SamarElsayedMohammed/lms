<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\CustomTranslateMissing::class,
        \App\Console\Commands\CleanupDemoData::class,
        \App\Console\Commands\ConvertVideosToHLS::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    #[\Override]
    protected function schedule(Schedule $schedule)
    {
        // Scheduled tasks are registered in routes/console.php (Laravel 12 convention).
        // Demo cleanup disabled - uncomment in routes/console.php to enable.
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    #[\Override]
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
