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
     * Generate a cryptographically unique certificate number.
     */
    public static function generateCertificateNumber(): string
    {
        do {
            $number = 'CERT-' . strtoupper(bin2hex(random_bytes(6)));
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
