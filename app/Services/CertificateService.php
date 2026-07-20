<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course\Course;
use App\Models\User;
use App\Models\OrderCourse;
use App\Models\Course\CourseCertificate;
use App\Models\UserNotification;
use App\Notifications\ManualCustomNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class CertificateService
{
    /**
     * Public API: check if user has completed the course.
     * Used by CertificateController as single source of truth.
     */
    public function checkCourseCompletionStatus(int $userId, int $courseId): bool
    {
        $progress = app(CourseProgressService::class)->getProgressWithCache($userId, $courseId);
        return $progress->progress_percentage == 100;
    }

    /**
     * Auto generate certificate upon 100% completion or payment.
     *
     * Uses a distributed Cache lock to prevent race conditions:
     * only one process can create a certificate for (userId, courseId) at a time.
     * The second concurrent request will re-query and return the record created by the first.
     */
    public function autoGenerateCertificate(int $userId, int $courseId): ?CourseCertificate
    {
        try {
            $user   = User::findOrFail($userId);
            $course = Course::findOrFail($courseId);

            // ── Fast path: already issued ─────────────────────────────────────────
            $existing = CourseCertificate::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();

            if ($existing) {
                return $existing;
            }

            // ── Business rule checks (before acquiring lock to reduce lock time) ──

            if (!$course->certificate_enabled) {
                return null;
            }

            if (!$this->checkCourseCompletionStatus($userId, $courseId)) {
                return null;
            }

            if ($course->certificate_fee > 0) {
                $purchased = OrderCourse::where('course_id', $courseId)
                    ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
                    ->where('certificate_purchased', true)
                    ->exists();

                if (!$purchased) {
                    return null;
                }
            }

            // ── Distributed lock: prevents duplicate creation under concurrency ──
            $lockKey = "cert_issue_{$userId}_{$courseId}";
            $lock    = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);

            return $lock->block(10, function () use ($userId, $courseId, $user, $course) {
                // Re-check inside the lock — another request may have already created it
                $existing = CourseCertificate::where('user_id', $userId)
                    ->where('course_id', $courseId)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $certificateNumber = CourseCertificate::generateCertificateNumber($userId);
                $verificationCode  = CourseCertificate::generateVerificationCode();
                $verificationToken = CourseCertificate::generateVerificationToken();

                // QR URL uses the unguessable verification_token (32-char hex)
                $verificationUrl = config('app.url') . '/certificates/verify/' . $verificationToken;

                $template = Certificate::where('type', 'course_completion')
                    ->where('is_active', true)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $certificate = CourseCertificate::create([
                    'user_id'                => $userId,
                    'course_id'              => $courseId,
                    'certificate_number'     => $certificateNumber,
                    'student_name'           => $user->name,
                    'arabic_title'           => $course->title,
                    'english_title'          => $course->title,
                    'instructor_name'        => $course->user->name ?? '',
                    'issued_date'            => now()->toDateString(),
                    'status'                 => 'active',
                    'verification_code'      => $verificationCode,
                    'verification_token'     => $verificationToken,
                    'verification_url'       => $verificationUrl,
                    'completed_at'           => now(),
                    'certificate_template_id' => $template ? $template->id : null,
                    'issuer_id'              => $course->user_id ?? null,
                ]);

                try {
                    $result    = (new \Endroid\QrCode\Builder\Builder(data: $verificationUrl, size: 150))->build();
                    $qrPng     = $result->getString();
                    $qrFileName = 'certificates/qr/qr_' . $verificationToken . '.png';
                    Storage::disk('public')->put($qrFileName, $qrPng);
                    $certificate->update(['qr_code_path' => $qrFileName]);
                } catch (\Exception $e) {
                    Log::warning('Failed to generate QR code on issue: ' . $e->getMessage());
                }

                $this->notifyUserOfCertificate($user, $course, $certificate);

                return $certificate;
            });

        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Another process held the lock too long; return whatever was created
            Log::warning("Certificate lock timeout for user={$userId} course={$courseId}");
            return CourseCertificate::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();
        } catch (\Exception $e) {
            Log::error('Auto Generate Certificate Error: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'course_id' => $courseId,
                'trace'     => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function notifyUserOfCertificate(User $user, Course $course, CourseCertificate $certificate)
    {
        try {
            $title = 'تهانينا! شهادتك جاهزة 🎓';
            $message = "لقد أتممت بنجاح دورة: {$course->title}. يمكنك الآن عرض وتحميل شهادتك من لوحة التحكم.";
            
            // App notification
            UserNotification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => 'certificate_earned',
                'url' => '/student/my-certificates',
                'icon' => 'Award',
                'icon_color' => '#22c55e', // Success green
            ]);
            
            // Email
            $user->notify(new ManualCustomNotification([
                'title' => $title,
                'message' => $message,
                'channels' => ['mail'],
                'url' => config('app.frontend_url') . '/student/my-certificates',
            ]));
        } catch (\Exception $e) {
            Log::error('Failed to notify user of certificate: ' . $e->getMessage());
        }
    }

    /**
     * Generate a certificate for exam completion
     */
    public function generateExamCompletionCertificate($userId, $courseId, $certificateId = null)
    {
        $user = User::findOrFail($userId);
        $course = Course::findOrFail($courseId);

        // Get certificate template
        if ($certificateId) {
            $certificate = Certificate::findOrFail($certificateId);
        } else {
            $certificate = Certificate::examCompletion()->active()->first();
        }

        if (!$certificate) {
            throw new \Exception('No active exam completion certificate template found');
        }

        return $this->generateCertificate($user, $course, $certificate, 'exam_completion');
    }

    /**
     * Generate certificate with user and course data
     */
    private function generateCertificate($user, $course, $certificate, $type)
    {
        try {
            // Create certificate data
            $certificateData = [
                'user_name' => $user->name,
                'course_name' => $course->title,
                'completion_date' => now()->format('F j, Y'),
                'certificate_title' => $certificate->title ?? 'Certificate of Completion',
                'certificate_subtitle' => $certificate->subtitle ?? 'This is to certify that',
                'signature_text' => $certificate->signature_text ?? 'Director of Education',
                'type' => $type,
            ];

            // Generate certificate image
            $certificateImage = $this->createCertificateImage($certificate, $certificateData);

            // Save certificate file
            $fileName = 'certificate_' . $user->id . '_' . $course->id . '_' . time() . '.png';
            $filePath = 'certificates/generated/' . $fileName;

            Storage::disk('public')->put($filePath, $certificateImage);

            return [
                'success' => true,
                'file_path' => $filePath,
                'file_url' => asset('storage/' . $filePath),
                'certificate_data' => $certificateData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create certificate image using Intervention Image
     */
    private function createCertificateImage($certificate, $data)
    {
        // Default dimensions
        $width = 1200;
        $height = 800;

        // Create base image
        if ($certificate->background_image) {
            $image = Image::make(Storage::disk('public')->path($certificate->background_image));
            $image->resize($width, $height);
        } else {
            // Create default background
            $image = Image::canvas($width, $height, '#f8f9fa');

            // Add gradient background
            $image->rectangle(0, 0, $width, $height, static function ($draw): void {
                $draw->background('#667eea');
            });
        }

        // Add certificate title
        if ($data['certificate_title']) {
            $image->text($data['certificate_title'], $width / 2, 150, static function ($font): void {
                $font->file(public_path('fonts/arial.ttf')); // You may need to add font files
                $font->size(48);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('top');
            });
        }

        // Add subtitle
        if ($data['certificate_subtitle']) {
            $image->text($data['certificate_subtitle'], $width / 2, 220, static function ($font): void {
                $font->file(public_path('fonts/arial.ttf'));
                $font->size(24);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('top');
            });
        }

        // Add user name
        $image->text($data['user_name'], $width / 2, 350, static function ($font): void {
            $font->file(public_path('fonts/arial.ttf'));
            $font->size(36);
            $font->color('#ffffff');
            $font->align('center');
            $font->valign('top');
        });

        // Add course completion text
        $image->text('has successfully completed the course', $width / 2, 420, static function ($font): void {
            $font->file(public_path('fonts/arial.ttf'));
            $font->size(20);
            $font->color('#ffffff');
            $font->align('center');
            $font->valign('top');
        });

        // Add course name
        $image->text($data['course_name'], $width / 2, 480, static function ($font): void {
            $font->file(public_path('fonts/arial.ttf'));
            $font->size(28);
            $font->color('#ffffff');
            $font->align('center');
            $font->valign('top');
        });

        // Add completion date
        $image->text('on this day of ' . $data['completion_date'], $width / 2, 540, static function ($font): void {
            $font->file(public_path('fonts/arial.ttf'));
            $font->size(18);
            $font->color('#ffffff');
            $font->align('center');
            $font->valign('top');
        });

        // Add signature
        if ($certificate->signature_image) {
            $signature = Image::make(Storage::disk('public')->path($certificate->signature_image));
            $signature->resize(200, 100);
            $image->insert($signature, 'bottom-right', 100, 50);
        }

        // Add signature text
        if ($data['signature_text']) {
            $image->text($data['signature_text'], $width - 200, $height - 80, static function ($font): void {
                $font->file(public_path('fonts/arial.ttf'));
                $font->size(16);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('top');
            });
        }

        // Add date
        $image->text('Date: ' . $data['completion_date'], 100, $height - 50, static function ($font): void {
            $font->file(public_path('fonts/arial.ttf'));
            $font->size(16);
            $font->color('#ffffff');
            $font->align('left');
            $font->valign('top');
        });

        return $image->encode('png');
    }

    /**
     * Get available certificate templates
     */
    public function getAvailableTemplates($type = null)
    {
        $query = Certificate::active();

        if ($type) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * Delete generated certificate file
     */
    public function deleteCertificateFile($filePath)
    {
        if (Storage::disk('public')->exists($filePath)) {
            return Storage::disk('public')->delete($filePath);
        }

        return false;
    }
}
