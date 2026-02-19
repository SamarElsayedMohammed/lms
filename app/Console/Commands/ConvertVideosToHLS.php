<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EncodeVideoToHLS;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Console\Command;

final class ConvertVideosToHLS extends Command
{
    protected $signature = 'videos:convert-to-hls
                            {--limit=50 : Maximum number of videos to process}
                            {--force : Re-encode failed videos}';

    protected $description = 'Convert existing lecture videos to HLS format';

    public function handle(): int
    {
        $this->info('Starting HLS video conversion...');

        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        // Build query
        /** @var \Illuminate\Database\Eloquent\Builder<CourseChapterLecture> $query */
        $query = CourseChapterLecture::where('type', 'file')->whereNotNull('file')->whereNotNull('file_extension');

        if ($force) {
            // Include failed videos when force flag is set
            $query->where(function ($q): void {
                $q->whereNull('hls_status')->orWhere('hls_status', 'pending')->orWhere('hls_status', 'failed');
            });
        } else {
            // Only pending or null status
            $query->where(function ($q): void {
                $q->whereNull('hls_status')->orWhere('hls_status', 'pending');
            });
        }

        // Get lectures to process
        /**
         * @var \Illuminate\Database\Eloquent\Collection<int, CourseChapterLecture> $lectures
         */
        $lectures = $query->limit($limit)->get();

        if ($lectures->isEmpty()) {
            $this->info('No videos found that need encoding.');
            return Command::SUCCESS;
        }

        $this->info("Found {$lectures->count()} videos to process.");

        $progressBar = $this->output->createProgressBar($lectures->count());
        $progressBar->start();

        $queued = 0;
        $skipped = 0;

        foreach ($lectures as $lecture) {
            // Verify it's a video file
            $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'];
            $extension = strtolower($lecture->file_extension ?? '');

            if (!in_array($extension, $videoExtensions, true)) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            // Update status to pending
            $lecture->update([
                'hls_status' => 'pending',
                'hls_error_message' => null,
            ]);

            // Dispatch encoding job
            EncodeVideoToHLS::dispatch($lecture);
            $queued++;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info(" Queued {$queued} videos for HLS encoding.");

        if ($skipped > 0) {
            $this->warn(" Skipped {$skipped} non-video files.");
        }

        $this->info(' Monitor progress with: php artisan queue:listen');

        return Command::SUCCESS;
    }
}
