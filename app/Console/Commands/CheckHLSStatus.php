<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Console\Command;

final class CheckHLSStatus extends Command
{
    protected $signature = 'videos:hls-status';

    protected $description = 'Check HLS conversion status for all lecture videos';

    public function handle(): int
    {
        $this->info('Checking HLS conversion status...');
        $this->newLine();

        // Get statistics using a single query
        $stats = CourseChapterLecture::where('type', 'file')
            ->whereNotNull('file')
            ->whereNotNull('file_extension')
            ->selectRaw('
                COUNT(*) as total_videos,
                COUNT(CASE WHEN hls_status IS NULL THEN 1 END) as not_started,
                COUNT(CASE WHEN hls_status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN hls_status = "processing" THEN 1 END) as processing,
                COUNT(CASE WHEN hls_status = "completed" THEN 1 END) as completed,
                COUNT(CASE WHEN hls_status = "failed" THEN 1 END) as failed
            ')
            ->first();

        // Display results in a table
        $this->table(['Status', 'Count'], [
            ['Total Videos', $stats->total_videos],
            ['Not Started', $stats->not_started],
            ['Pending', $stats->pending],
            ['Processing', $stats->processing],
            ['Completed', $stats->completed],
            ['Failed', $stats->failed],
        ]);

        $needsEncoding = $stats->not_started + $stats->pending;

        $this->newLine();
        if ($needsEncoding > 0) {
            $this->warn("🔄 {$needsEncoding} videos need HLS encoding");
            $this->newLine();
            $this->info('To start bulk conversion, run:');
            $this->line('  php artisan videos:convert-to-hls');
            $this->line('  php artisan videos:convert-to-hls --limit=100');
            $this->line('  php artisan videos:convert-to-hls --force (includes failed)');
        } else {
            $this->info('✓ All videos have been processed!');
        }

        if ($stats->failed > 0) {
            $this->newLine();
            $this->error("⚠ {$stats->failed} videos failed encoding");
            $this->info('To retry failed videos:');
            $this->line('  php artisan videos:convert-to-hls --force');
        }

        return Command::SUCCESS;
    }
}
