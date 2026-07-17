<?php

namespace App\Models\Course;

use App\Models\OrderCourse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'certificate_number',
        'student_name',
        'arabic_title',
        'english_title',
        'instructor_name',
        'issued_date',
        'status',
        'revoked_at',
        'revoked_reason',
        'verification_code',
        'verification_token',
        'enrollment_id',
        'completed_at',
        'certificate_template_id',
        'verification_url',
        'qr_code_path',
        'pdf_path',
        'issuer_id',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'completed_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    // -------------------------------------------------------------------------
    // Helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /**
     * Check that a given user actually enrolled in this certificate's course.
     * Enrollment = completed order that contains this course.
     */
    public static function userIsEnrolled(int $userId, int $courseId): bool
    {
        return OrderCourse::whereHas('order', fn ($q) => $q
            ->where('user_id', $userId)
            ->where('status', 'completed')
        )
            ->where('course_id', $courseId)
            ->exists();
    }

    /**
     * Generate a cryptographically unique certificate number matching PRD specs.
     * Format: CERT-{YEAR}-{USERID-5digits}-{RANDOM-6chars}
     * Example: CERT-2026-00042-AB3X7K
     */
    public static function generateCertificateNumber(int $userId = 0): string
    {
        $year = date('Y');
        $userPart = str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
        
        do {
            // Use cryptographically secure random bytes for the random part
            $randomBytes = random_bytes(4); // 8 hex characters
            $randomPart = strtoupper(substr(bin2hex($randomBytes), 0, 8));
            $number = "CERT-{$year}-{$userPart}-{$randomPart}";
        } while (self::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Generate a cryptographically secure verification token.
     */
    public static function generateVerificationToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16)); // 32 chars
        } while (self::where('verification_token', $token)->exists());

        return $token;
    }

    /**
     * Generate a verification code.
     */
    public static function generateVerificationCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        } while (self::where('verification_code', $code)->exists());

        return $code;
    }

    // -------------------------------------------------------------------------
    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRevoked($query)
    {
        return $query->where('status', 'revoked');
    }
}
