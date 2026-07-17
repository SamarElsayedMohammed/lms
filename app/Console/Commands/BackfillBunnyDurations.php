<?php

namespace App\Console\Commands;

use App\Jobs\FetchBunnyVideoDurationJob;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Console\Command;

class BackfillBunnyDurations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'skillso:backfill-bunny-durations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to fetch duration for existing Bunny Stream lectures that have 0 duration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Finding lectures with Bunny Stream URLs and missing durations...');

        $lectures = CourseChapterLecture::where('type', 'video')
            ->where(function ($query) {
                $query->where('youtube_url', 'like', '%iframe.mediadelivery.net%')
                      ->orWhere('file', 'like', '%iframe.mediadelivery.net%');
            })
            ->where('duration_seconds', '<=', 0)
            ->get();

        if ($lectures->isEmpty()) {
            $this->info('No lectures need backfilling.');
            return;
        }

        $this->info('Found ' . $lectures->count() . ' lectures. Dispatching jobs...');

        $count = 0;
        foreach ($lectures as $lecture) {
            $url = $lecture->youtube_url ?: $lecture->file;
            
            if (preg_match('/iframe\.mediadelivery\.net\/embed\/([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
                $libraryId = $matches[1];
                $videoGuid = $matches[2];
                FetchBunnyVideoDurationJob::dispatch($lecture->id, $libraryId, $videoGuid);
                $count++;
            }
        }

        $this->info("Successfully dispatched {$count} jobs.");
    }
}
