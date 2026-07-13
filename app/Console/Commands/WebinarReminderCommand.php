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
            ->get();

        foreach ($webinars as $webinar) {
            // Fire event instead of handling directly to decouple logic and use the queue
            if (class_exists(\App\Events\WebinarStartingSoon::class)) {
                event(new \App\Events\WebinarStartingSoon($webinar));
            }
            $this->info("WebinarStartingSoon event fired for webinar: {$webinar->title}");
        }

        return Command::SUCCESS;
    }
}
