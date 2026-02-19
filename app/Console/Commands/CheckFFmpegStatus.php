<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FFmpegService;
use Illuminate\Console\Command;

final class CheckFFmpegStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ffmpeg:status
                          {--clear-cache : Clear the FFmpeg availability cache}';

    /**
     * The console command description.
     */
    protected $description = 'Check if FFmpeg is available on the server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clear-cache')) {
            FFmpegService::clearCache();
            $this->info('✓ FFmpeg cache cleared');
            $this->newLine();
        }

        $this->info('Checking FFmpeg availability...');
        $this->newLine();

        $status = FFmpegService::getStatus();

        // Display status
        $this->components->twoColumnDetail('Available', $status['available'] ? '<fg=green>Yes</>' : '<fg=red>No</>');
        $this->components->twoColumnDetail(
            'proc_open function',
            $status['proc_open_available'] ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>',
        );

        if ($status['available']) {
            $this->components->twoColumnDetail('Path', $status['path'] ?? 'Unknown');
            $this->components->twoColumnDetail('Version', $status['version'] ?? 'Unknown');
            $this->components->twoColumnDetail('Cached', $status['cached'] ? 'Yes' : 'No (fresh check)');

            $this->newLine();
            $this->info('✓ FFmpeg is installed and working');
            $this->info('  HLS video encoding is ENABLED for uploaded videos');
        } else {
            $this->newLine();
            $this->warn('✗ FFmpeg/HLS is not available on this server');
            $this->warn('  HLS video encoding is DISABLED');
            $this->warn('  Videos will be served directly without HLS streaming');

            // Show what's missing
            if (!empty($status['missing_requirements'])) {
                $this->newLine();
                $this->error('Missing Requirements:');
                foreach ($status['missing_requirements'] as $requirement) {
                    $this->line('  • ' . $requirement);
                }
            }

            $this->newLine();
            $this->info('To enable HLS video encoding:');

            if (!$status['proc_open_available']) {
                $this->newLine();
                $this->comment('1. Enable proc_open in PHP:');
                $this->info('   • Edit php.ini and remove "proc_open" from disable_functions');
                $this->info('   • Common location: /etc/php/8.3/fpm/php.ini or /etc/php/8.3/cli/php.ini');
                $this->info('   • After editing: restart PHP-FPM service');
                $this->info('   • In aaPanel: App Store → PHP 8.3 → Settings → Disabled functions → Remove proc_open');
            }

            if ($status['proc_open_available'] && $status['path'] === null) {
                $this->newLine();
                $this->comment('2. Install FFmpeg:');
                $this->info('   • Ubuntu/Debian: sudo apt-get install ffmpeg');
                $this->info('   • CentOS/RHEL:   sudo yum install ffmpeg');
                $this->info('   • macOS:         brew install ffmpeg');
                $this->info('   • Then run: php artisan ffmpeg:status --clear-cache');
            }

            $this->newLine();
            $this->comment('Note: Core LMS functionality works without HLS. This is an optional security enhancement.');
        }

        return $status['available'] ? self::SUCCESS : self::FAILURE;
    }
}
