<?php

namespace App\Models\Course;

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
        'issuance_source',
        'revoked_at',
        'revoked_reason',
        'revoked_by',
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
     * Check that a given user actually enrolled in or has access to this certificate's course.
     * Access = completed order, active enrollment record, or active subscription access.
     */
    public static function userIsEnrolled(int $userId, int $courseId, ?User $user = null): bool
    {
        $userObj = $user ?? User::find($userId);
        $course = Course::find($courseId);
        if ($userObj === null || $course === null) {
            return false;
        }

        return app(\App\Services\ContentAccessService::class)->canAccessCourse($userObj, $course);
    }

    /**
     * Generate a cryptographically random, non-sequential, non-predictable 18-digit numeric string.
     * Guaranteed to be 18 digits [0-9]{18} and unique in the database.
     * Example: 583104927641805273
     */
    public static function generateCertificateNumber(int $userId = 0): string
    {
        do {
            // Generate two 9-digit random numbers using cryptographically secure random_int
            $part1 = str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
            $part2 = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $number = $part1 . $part2;
        } while (self::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Normalize certificate number or code input by trimming whitespace,
     * removing spaces, hyphens, underscores, and normalizing Eastern Arabic numerals to standard digits.
     */
    public static function normalizeCertificateNumber(?string $code): string
    {
        if ($code === null) {
            return '';
        }
        $arabicNumerals = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishNumerals = ['0','1','2','3','4','5','6','7','8','9'];
        $normalized = str_replace($arabicNumerals, $englishNumerals, (string) $code);
        $normalized = preg_replace('/[\s\-\_]+/u', '', $normalized);
        return trim($normalized);
    }

    /**
     * Generate a cryptographically secure verification token (32 hex characters).
     */
    public static function generateVerificationToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16)); // 32 chars
        } while (self::where('verification_token', $token)->exists());

        return $token;
    }

    /**
     * Generate a verification code (10 alphanumeric characters).
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
