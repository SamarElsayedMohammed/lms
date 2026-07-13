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
        return $this->checkCourseCompletionFromTracking($userId, $courseId);
    }

    /**
     * Auto generate certificate upon 100% completion or payment.
     * Evaluates course completeness, checks purchase requirement, and creates DB record.
     */
    public function autoGenerateCertificate(int $userId, int $courseId): ?CourseCertificate
    {
        try {
            $user = User::findOrFail($userId);
            $course = Course::findOrFail($courseId);

            // 1. Check if already generated
            $existing = CourseCertificate::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();
                
            if ($existing) {
                return $existing;
            }

            // 2. Check if certificate is enabled for course
            if (!$course->certificate_enabled) {
                return null;
            }

            // 3. Check if user actually completed the course (includes video watch time and assignments)
            if (!$this->checkCourseCompletionFromTracking($userId, $courseId)) {
                return null;
            }

            $videoProgress = app(VideoProgressService::class)->getCourseProgress($user, $course);
            if ($videoProgress < VideoProgressService::COMPLETION_THRESHOLD) {
                return null;
            }

            // 4. Check if course requires a fee for the certificate
            if ($course->certificate_fee > 0) {
                // Must check if user purchased it
                $purchased = OrderCourse::where('course_id', $courseId)
                    ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
                    ->where('certificate_purchased', true)
                    ->exists();

                if (!$purchased) {
                    return null; // Awaiting purchase
                }
            }

            // 5. Create Certificate Record
            $certificate = CourseCertificate::create([
                'user_id'            => $userId,
                'course_id'          => $courseId,
                'certificate_number' => CourseCertificate::generateCertificateNumber($userId),
                'student_name'       => $user->name,
                'arabic_title'       => $course->title,
                'english_title'      => $course->title,
                'instructor_name'    => $course->user->name ?? '',
                'issued_date'        => now()->toDateString(),
                'status'             => 'active',
            ]);

            // 6. Notify User
            $this->notifyUserOfCertificate($user, $course, $certificate);

            return $certificate;

        } catch (\Exception $e) {
            Log::error('Auto Generate Certificate Error: ' . $e->getMessage());
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
     * Check if user has completed course using user_curriculum_trackings table
     */
    private function checkCourseCompletionFromTracking($userId, $courseId)
    {
        // Get course with chapters and curriculum items
        $course = Course::with([
            'chapters' => static function ($query): void {
                $query->where('is_active', 1)->orderBy('chapter_order');
            },
            'chapters.lectures' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.quizzes' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.assignments' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.resources' => static function ($query): void {
                $query->where('is_active', 1);
            },
        ])->find($courseId);

        if (!$course) {
            return false;
        }

        // Count total curriculum items (excluding assignments)
        $totalLectures = 0;
        $totalQuizzes = 0;
        $totalResources = 0;

        foreach ($course->chapters as $chapter) {
            $totalLectures += $chapter->lectures->count();
            $totalQuizzes += $chapter->quizzes->count();
            $totalResources += $chapter->resources->count();
        }

        // Check completed items from user_curriculum_trackings
        $completedTracking = \App\Models\UserCurriculumTracking::where('user_id', $userId)
            ->whereIn('course_chapter_id', $course->chapters->pluck('id'))
            ->where('status', 'completed')
            ->get();

        $completedLectures = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class)
            ->count();
        $completedQuizzes = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class)
            ->count();
        $completedResources = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class)
            ->count();

        // Check if all curriculum items are completed
        $curriculumItemsTotal = $totalLectures + $totalQuizzes + $totalResources;
        $curriculumItemsCompleted = $completedLectures + $completedQuizzes + $completedResources;
        $allCurriculumCompleted = $curriculumItemsTotal == 0 || $curriculumItemsCompleted >= $curriculumItemsTotal;

        // Check assignment submissions (must be submitted or accepted, or can_skip = 1)
        $assignmentIds = [];
        $skippableAssignmentIds = [];
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->assignments as $assignment) {
                $assignmentIds[] = $assignment->id;
                if ($assignment->can_skip) {
                    $skippableAssignmentIds[] = $assignment->id;
                }
            }
        }

        $totalAssignments = count($assignmentIds);
        $skippableAssignments = count($skippableAssignmentIds);
        $submittedAssignments = 0;

        if (!empty($assignmentIds)) {
            // Count assignments that have been submitted/accepted (excluding skippable ones)
            $nonSkippableAssignmentIds = array_diff($assignmentIds, $skippableAssignmentIds);
            if (!empty($nonSkippableAssignmentIds)) {
                $submittedAssignments = \App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission::where(
                    'user_id',
                    $userId,
                )
                    ->whereIn('course_chapter_assignment_id', $nonSkippableAssignmentIds)
                    ->whereIn('status', ['submitted', 'accepted'])
                    ->count();
            }
        }

        $allAssignmentsSubmitted = CourseCompletionService::allAssignmentsSubmitted(
            $totalAssignments,
            $skippableAssignments,
            $submittedAssignments,
        );

        // Course is completed only if both conditions are met
        return $allCurriculumCompleted && $allAssignmentsSubmitted;
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
