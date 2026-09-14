<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstructorRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'country',
        'nationality',
        'job_title',
        'company',
        'years_of_experience',
        'specialty',
        'experience_bio',
        'linkedin_url',
        'facebook_url',
        'website_url',
        'youtube_url',
        'intro_video_type',
        'intro_video_url',
        'intro_video_path',
        'cv_path',
        'cv_original_name',
        'cv_size',
        'profile_image_path',
        'status',
        'admin_notes',
        'applicant_feedback',
        'rejection_reason',
        'referral',
        'user_id',
        'reviewer_id',
        'reviewed_at',
    ];

    protected $casts = [
        'years_of_experience' => 'integer',
        'cv_size' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'reference_code',
        'status_label',
        'cv_url',
        'profile_image_url',
        'intro_video_url_resolved',
    ];

    /**
     * Get computed reference code (e.g. EXP-2026-0006)
     */
    public function getReferenceCodeAttribute(): string
    {
        $year = $this->created_at ? (int) $this->created_at->format('Y') : (int) date('Y');
        return sprintf('EXP-%d-%04d', $year, $this->id);
    }

    /**
     * Get the available status options
     */
    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'under_review' => 'Under Review',
            'changes_requested' => 'Changes Requested',
            'resubmitted' => 'Resubmitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'contacted' => 'Contacted',
            'ignored' => 'Ignored',
        ];
    }

    /**
     * Get the status label attribute
     */
    public function getStatusLabelAttribute(): string
    {
        $statuses = self::getStatusOptions();
        return $statuses[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Resolve public or storage URL for CV
     */
    public function getCvUrlAttribute(): ?string
    {
        if (empty($this->cv_path)) {
            return null;
        }
        return FileService::getFileUrl($this->cv_path);
    }

    /**
     * Resolve public or storage URL for Profile Image
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        if (empty($this->profile_image_path)) {
            return null;
        }
        return FileService::getFileUrl($this->profile_image_path);
    }

    /**
     * Resolve playback URL for intro video
     */
    public function getIntroVideoUrlResolvedAttribute(): ?string
    {
        if ($this->intro_video_type === 'file' && !empty($this->intro_video_path)) {
            return FileService::getFileUrl($this->intro_video_path);
        }

        if (!empty($this->intro_video_url)) {
            return $this->intro_video_url;
        }

        return null;
    }

    /**
     * Linked applicant user account (if submitted by or linked to registered user)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Admin reviewer user
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Immutable audit logs history associated with this application
     */
    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'target_id')
            ->where('target_type', 'InstructorRequest')
            ->orderBy('created_at', 'asc');
    }
}
