<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Course\CourseCertificate;
use Illuminate\Support\Facades\Storage;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Support\Facades\Log;

class BackfillCertificatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificates:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfills missing audit fields and generates QR codes for legacy certificates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting certificate backfill process...");

        $query = CourseCertificate::whereNull('verification_code')
            ->orWhereNull('verification_url')
            ->orWhereNull('qr_code_path');

        $total = $query->count();
        $this->info("Found {$total} certificates requiring backfill.");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updatedCount = 0;
        $failedCount = 0;

        $query->chunkById(100, function ($certificates) use ($bar, &$updatedCount, &$failedCount) {
            foreach ($certificates as $certificate) {
                try {
                    $needsSave = false;

                    // Generate Verification Code
                    if (empty($certificate->verification_code)) {
                        $certificate->verification_code = CourseCertificate::generateVerificationCode();
                        $needsSave = true;
                    }

                    // Generate Verification Token
                    if (empty($certificate->verification_token)) {
                        $certificate->verification_token = CourseCertificate::generateVerificationToken();
                        $needsSave = true;
                    }

                    // Ensure Certificate Number exists
                    if (empty($certificate->certificate_number)) {
                        $certificate->certificate_number = CourseCertificate::generateCertificateNumber($certificate->user_id ?? 0);
                        $needsSave = true;
                    }

                    // Generate Verification URL
                    if (empty($certificate->verification_url)) {
                        $appUrl = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
                        $certificate->verification_url = $appUrl . '/verify-certificate?code=' . $certificate->certificate_number;
                        $needsSave = true;
                    }

                    // Populate Snapshot metadata if missing
                    if (empty($certificate->student_name) && $certificate->user) {
                        $certificate->student_name = $certificate->user->name ?? 'Student';
                        $needsSave = true;
                    }
                    if (empty($certificate->arabic_title) && $certificate->course) {
                        $certificate->arabic_title = $certificate->course->title ?? 'Course';
                        $needsSave = true;
                    }
                    if (empty($certificate->english_title) && $certificate->course) {
                        $certificate->english_title = $certificate->course->title ?? 'Course';
                        $needsSave = true;
                    }
                    if (empty($certificate->instructor_name) && $certificate->course?->user) {
                        $certificate->instructor_name = $certificate->course->user->name ?? 'Instructor';
                        $needsSave = true;
                    }

                    // Save DB Changes
                    if ($needsSave) {
                        $certificate->save();
                    }

                    // Generate QR Code File
                    if (empty($certificate->qr_code_path) || !Storage::disk('public')->exists($certificate->qr_code_path)) {
                        $qrFileName = 'certificates/qr/qr_' . $certificate->verification_code . '.png';
                        $result = (new Builder(data: $certificate->verification_url, size: 150))->build();
                        Storage::disk('public')->put($qrFileName, $result->getString());
                        
                        $certificate->qr_code_path = $qrFileName;
                        $certificate->save();
                    }

                    $updatedCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to backfill certificate ID {$certificate->id}: " . $e->getMessage());
                    $failedCount++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill complete.");
        $this->info("Successfully updated: {$updatedCount}");
        
        if ($failedCount > 0) {
            $this->error("Failed to update: {$failedCount}. Check Laravel logs for details.");
        }
    }
}
