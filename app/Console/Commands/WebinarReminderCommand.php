<?php

namespace App\Console\Commands;

use App\Events\WebinarStartingSoon;
use App\Models\Webinar;
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
    protected $description = 'Dispatches starting-soon reminders for webinars starting within 1 hour';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Query all published, scheduled webinars starting within the next 60 minutes that have not been reminded yet
        $dueWebinars = Webinar::where('status', 'scheduled')
            ->where('is_published', true)
            ->whereNull('reminder_sent_at')
            ->where('start_at', '<=', now()->addMinutes(60))
            ->where('start_at', '>', now())
            ->get();

        $count = 0;
        foreach ($dueWebinars as $webinar) {
            // Atomic claim: set reminder_sent_at only if it is still null
            $claimed = Webinar::where('id', $webinar->id)
                ->whereNull('reminder_sent_at')
                ->update(['reminder_sent_at' => now()]);

            if ($claimed > 0) {
                if (class_exists(WebinarStartingSoon::class)) {
                    event(new WebinarStartingSoon($webinar));
                }
                $this->info("WebinarStartingSoon event fired for webinar #{$webinar->id}: {$webinar->title}");
                $count++;
            }
        }

        $this->info("Webinar reminder scan completed. Dispatched {$count} reminder(s).");
        return Command::SUCCESS;
    }
}
