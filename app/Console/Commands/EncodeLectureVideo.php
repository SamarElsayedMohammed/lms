<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EncodeVideoToHLS;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Console\Command;

final class EncodeLectureVideo extends Command
{
    protected $signature = 'lecture:encode {id : Lecture ID} {--check : Check status only} {--force : Force re-encode regardless of status}';
    protected $description = 'Manually encode lecture video to HLS or check encoding status';

    public function handle(): int
    {
        $lectureId = $this->argument('id');
        $checkOnly = $this->option('check');

        $lecture = CourseChapterLecture::find($lectureId);

        if (!$lecture) {
            $this->error("Lecture with ID {$lectureId} not found.");
            return 1;
        }

        if ($lecture->type !== 'file') {
            $this->error("Lecture {$lectureId} is not a file type lecture.");
            return 1;
        }

        $this->info("Lecture: {$lecture->title} (ID: {$lecture->id})");
        $this->info("Type: {$lecture->type}");
        $this->info('File: ' . ($lecture->file ?? 'None'));

        $this->newLine();
        $this->info('HLS Status: ' . ($lecture->hls_status ?? 'null'));

        if ($lecture->hls_manifest_path) {
            $this->info("HLS Manifest: {$lecture->hls_manifest_path}");
        }

        if ($lecture->hls_error_message) {
            $this->error("HLS Error: {$lecture->hls_error_message}");
        }

        if ($lecture->hls_encoded_at) {
            $this->info("HLS Encoded At: {$lecture->hls_encoded_at}");
        }

        $this->newLine();
        $this->info('Has HLS: ' . ($lecture->hasHls() ? 'Yes' : 'No'));
        $this->info('Needs HLS: ' . ($lecture->needsHlsEncoding() ? 'Yes' : 'No'));

        if ($checkOnly) {
            return 0;
        }

        $this->newLine();

        $forceEncode = $this->option('force');

        if (!$lecture->needsHlsEncoding() && !$forceEncode) {
            $this->info("Lecture does not need HLS encoding (status: {$lecture->hls_status}).");
            $this->info('Use --force to override and re-encode.');
            return 0;
        }

        if ($forceEncode) {
            $this->warn("Force mode: Will override current status ({$lecture->hls_status}) and re-encode.");
        }

        if ($this->confirm(($forceEncode ? 'Force re-encode' : 'Encode') . " lecture {$lectureId} to HLS?")) {
            $this->info('Dispatching HLS encoding job...');

            $lecture->update([
                'hls_status' => 'pending',
                'hls_error_message' => null,
                'hls_manifest_path' => null,
                'hls_encoded_at' => null,
            ]);

            EncodeVideoToHLS::dispatch($lecture);

            $this->info('HLS encoding job dispatched successfully.');
            $this->info("Run 'php artisan queue:work' to process the job.");
            $this->info("Check status with: php artisan lecture:encode {$lectureId} --check");
        }

        return 0;
    }
}
