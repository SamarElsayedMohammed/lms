<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WebinarReminderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webinar:remind';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Find webinars starting in exactly 1 hour (between 55 and 65 mins from now)
        $start = now()->addMinutes(55);
        $end = now()->addMinutes(65);

        $webinars = \App\Models\Webinar::where('status', 'scheduled')
            ->whereBetween('start_at', [$start, $end])
            ->with('registrations.user')
            ->get();

        foreach ($webinars as $webinar) {
            foreach ($webinar->registrations as $registration) {
                if ($registration->payment_status !== 'pending' && $registration->user) {
                    $registration->user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar, true));
                }
            }
            $this->info("Reminders sent for webinar: {$webinar->title}");
        }

        return Command::SUCCESS;
    }
}
