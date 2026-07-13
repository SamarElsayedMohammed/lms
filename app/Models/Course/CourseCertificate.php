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
    ];

    protected $casts = [
        'issued_date' => 'date',
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
            $randomPart = strtoupper(substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6));
            $number = "CERT-{$year}-{$userPart}-{$randomPart}";
        } while (self::where('certificate_number', $number)->exists());

        return $number;
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
