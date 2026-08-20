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
    protected $signature = 'skillso:backfill-bunny-durations
        {--limit=1000 : Maximum jobs to dispatch in one run}
        {--chunk=100 : Lectures loaded per database query}';

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

        $limit = max(1, min(10000, (int) $this->option('limit')));
        $chunkSize = max(1, min(500, (int) $this->option('chunk')));

        $query = CourseChapterLecture::whereIn('type', ['youtube_url', 'file'])
            ->where(function ($query) {
                $query->where('youtube_url', 'like', '%iframe.mediadelivery.net%')
                      ->orWhere('file', 'like', '%iframe.mediadelivery.net%');
            })
            ->where('duration_seconds', '<=', 0);

        $matchingCount = min($limit, (clone $query)->count());
        if ($matchingCount === 0) {
            $this->info('No lectures need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Dispatching up to {$matchingCount} of {$limit} bounded jobs...");

        $count = 0;
        $query->select(['id', 'youtube_url', 'file'])->chunkById(
            $chunkSize,
            function ($lectures) use (&$count, $limit): bool {
                foreach ($lectures as $lecture) {
                    if ($count >= $limit) {
                        return false;
                    }

                    $url = $lecture->youtube_url ?: $lecture->getRawOriginal('file');
                    if (!is_string($url)) {
                        continue;
                    }

                    if (preg_match('/iframe\.mediadelivery\.net\/embed\/([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
                        FetchBunnyVideoDurationJob::dispatch($lecture->id, $matches[1], $matches[2]);
                        $count++;
                    }
                }

                return $count < $limit;
            }
        );

        $this->info("Successfully dispatched {$count} jobs.");
        return self::SUCCESS;
    }
}
