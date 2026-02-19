<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class EncodeVideoToHLS implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 hour timeout for large videos

    public int $tries = 1; // Only try once - encoding is expensive

    public int $maxExceptions = 1; // Fail immediately on exception

    public function __construct(
        public CourseChapterLecture $lecture,
    ) {
        // Set queue name - using a dedicated queue allows prioritizing other jobs over video encoding
        $this->onQueue('video-encoding');
    }

    public function handle(): void
    {
        Log::info('EncodeVideoToHLS job started', [
            'lecture_id' => $this->lecture->id,
            'lecture_title' => $this->lecture->title,
        ]);

        try {
            // Check if FFmpeg is available on the server
            if (!FFmpegService::isAvailable()) {
                $status = FFmpegService::getStatus();
                $missingRequirements = $status['missing_requirements'];

                $errorMessage =
                    'HLS encoding unavailable. Missing: '
                    . implode(', ', $missingRequirements)
                    . '. Direct video streaming will be used instead.';

                Log::warning('FFmpeg/proc_open not available - skipping HLS encoding', [
                    'lecture_id' => $this->lecture->id,
                    'lecture_title' => $this->lecture->title,
                    'missing_requirements' => $missingRequirements,
                ]);

                $this->lecture->update([
                    'hls_status' => 'failed',
                    'hls_error_message' => $errorMessage,
                ]);

                return;
            }

            // Validate lecture can be encoded
            if ($this->lecture->type !== 'file') {
                Log::warning('Skipping non-file lecture', ['lecture_id' => $this->lecture->id]);
                return;
            }

            $originalFile = $this->lecture->getRawOriginal('file');
            if ($originalFile === null || $originalFile === '') {
                Log::error('No file found for lecture', ['lecture_id' => $this->lecture->id]);
                $this->lecture->update([
                    'hls_status' => 'failed',
                    'hls_error_message' => 'No video file found',
                ]);
                return;
            }

            // Update status to processing
            $this->lecture->update(['hls_status' => 'processing']);

            // Get absolute path to original video
            $originalPath = Storage::disk('public')->path((string) $originalFile);

            if (!file_exists($originalPath)) {
                Log::error('Original video file not found - marking as failed', [
                    'lecture_id' => $this->lecture->id,
                    'expected_path' => $originalPath,
                ]);

                $this->lecture->update([
                    'hls_status' => 'failed',
                    'hls_error_message' => 'Original video file not found - file may have been deleted or moved',
                ]);

                return;
            }

            // Check disk space before encoding
            // HLS output is typically similar size to input, but we need 2x for safety margin
            $originalFileSize = filesize($originalPath);

            if ($originalFileSize === false) {
                Log::error('Unable to determine file size', [
                    'lecture_id' => $this->lecture->id,
                    'file_path' => $originalPath,
                ]);

                $this->lecture->update([
                    'hls_status' => 'failed',
                    'hls_error_message' => 'Unable to determine video file size',
                ]);

                return;
            }

            $requiredSpace = $originalFileSize * 2;
            $storageBasePath = Storage::disk('public')->path('');
            $availableSpace = disk_free_space($storageBasePath);

            Log::info('Disk space check', [
                'lecture_id' => $this->lecture->id,
                'original_file_size_mb' => round(($originalFileSize / 1024) / 1024, 2),
                'required_space_mb' => round(($requiredSpace / 1024) / 1024, 2),
                'available_space_mb' => $availableSpace !== false
                    ? round(($availableSpace / 1024) / 1024, 2)
                    : 'unknown',
            ]);

            if ($availableSpace !== false && $availableSpace < $requiredSpace) {
                $requiredMB = round(($requiredSpace / 1024) / 1024, 2);
                $availableMB = round(($availableSpace / 1024) / 1024, 2);

                Log::error('Insufficient disk space for HLS encoding', [
                    'lecture_id' => $this->lecture->id,
                    'required_mb' => $requiredMB,
                    'available_mb' => $availableMB,
                ]);

                $this->lecture->update([
                    'hls_status' => 'failed',
                    'hls_error_message' => "Insufficient disk space. Required: {$requiredMB}MB, Available: {$availableMB}MB",
                ]);

                return;
            }

            // Create HLS output directory
            $hlsDir = "hls/lectures/{$this->lecture->id}";
            $hlsFullPath = Storage::disk('public')->path($hlsDir);

            if (!is_dir($hlsFullPath)) {
                mkdir($hlsFullPath, 0o755, true);
            }

            // HLS segment file pattern and manifest
            $segmentPattern = $hlsFullPath . '/segment_%03d.ts';
            $manifestPath = $hlsFullPath . '/master.m3u8';

            // FFmpeg command for single-quality HLS with robust encoding
            // Input handling:
            //   -y: Overwrite output files without asking
            //   -map 0:v:0: Map first video stream explicitly
            //   -map 0:a:0?: Optionally map first audio stream (? = don't fail if missing)
            // Video encoding:
            //   -vf: Video filters chained together:
            //     - scale: Ensure dimensions are divisible by 2 (required by libx264)
            //   -c:v libx264: H.264 video codec (best compatibility)
            //   -preset medium: Balance between encoding speed and quality
            //   -crf 23: Constant Rate Factor for quality (18-28 is typical, lower = better)
            //   -pix_fmt yuv420p: Most compatible pixel format for playback
            //   -vsync cfr: Convert variable frame rate (VFR) to constant frame rate (CFR)
            //   -metadata:s:v rotate=0: Clear rotation metadata after auto-rotation is applied
            // Audio encoding:
            //   -c:a aac: AAC audio codec
            //   -b:a 128k: Audio bitrate
            //   -ac 2: Stereo audio
            //   -ar 44100: Standard audio sample rate
            // HLS output:
            //   -hls_time 6: 6-second segments (good balance)
            //   -hls_playlist_type vod: Video on demand playlist
            //   -hls_list_size 0: Keep all segments in playlist
            //   -hls_segment_filename: Segment naming pattern
            $command = [
                'ffmpeg',
                '-y', // Overwrite without asking
                '-i',
                $originalPath,
                '-map',
                '0:v:0', // Map first video stream
                '-map',
                '0:a:0?', // Optionally map first audio stream (? = don't fail if missing)
                '-vf',
                'scale=ceil(iw/2)*2:ceil(ih/2)*2', // Ensure even dimensions for libx264
                '-c:v',
                'libx264',
                '-preset',
                'medium',
                '-crf',
                '23',
                '-pix_fmt',
                'yuv420p', // Most compatible pixel format
                '-vsync',
                'cfr', // Convert VFR to CFR (fixes screen recordings, some phone videos)
                '-metadata:s:v',
                'rotate=0', // Clear rotation metadata after auto-rotation
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-ac',
                '2', // Stereo audio
                '-ar',
                '44100', // Standard audio sample rate
                '-hls_time',
                '6',
                '-hls_playlist_type',
                'vod',
                '-hls_list_size',
                '0',
                '-hls_segment_filename',
                $segmentPattern,
                '-f',
                'hls',
                $manifestPath,
            ];

            Log::info('🔧 Running FFmpeg command', [
                'lecture_id' => $this->lecture->id,
                'command' => implode(' ', $command),
            ]);

            // Execute FFmpeg
            $process = new Process($command);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Verify manifest was created
            if (!file_exists($manifestPath)) {
                throw new \RuntimeException('HLS manifest not created');
            }

            // Fix permissions for web server access
            chmod($hlsFullPath, 0o775);
            $files = glob($hlsFullPath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    chmod($file, 0o644);
                }
            }

            // Update lecture with success
            $manifestRelativePath = $hlsDir . '/master.m3u8';
            $this->lecture->update([
                'hls_status' => 'completed',
                'hls_manifest_path' => $manifestRelativePath,
                'hls_error_message' => null,
                'hls_encoded_at' => now(),
            ]);

            Log::info('EncodeVideoToHLS job completed', [
                'lecture_id' => $this->lecture->id,
                'manifest_path' => $manifestRelativePath,
                'output' => $process->getOutput(),
            ]);
        } catch (\Throwable $e) {
            Log::error('EncodeVideoToHLS job failed', [
                'lecture_id' => $this->lecture->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update lecture with failure
            $this->lecture->update([
                'hls_status' => 'failed',
                'hls_error_message' => $e->getMessage(),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('EncodeVideoToHLS job marked as failed', [
            'lecture_id' => $this->lecture->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
