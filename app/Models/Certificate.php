<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'background_image',
        'title',
        'subtitle',
        'signature_image',
        'signature_text',
        'template_settings',
        'is_active',
    ];

    protected $casts = [
        'template_settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the background image URL
     */
    public function getBackgroundImageUrlAttribute()
    {
        if ($this->background_image) {
            return FileService::getFileUrl($this->background_image);
        }
        return null;
    }

    /**
     * Get the signature image URL
     */
    public function getSignatureImageUrlAttribute()
    {
        if ($this->signature_image) {
            return FileService::getFileUrl($this->signature_image);
        }
        return null;
    }

    /**
     * Scope for active certificates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for course completion certificates
     */
    public function scopeCourseCompletion($query)
    {
        return $query->where('type', 'course_completion');
    }

    /**
     * Scope for exam completion certificates
     */
    public function scopeExamCompletion($query)
    {
        return $query->where('type', 'exam_completion');
    }

    /**
     * Scope for custom certificates
     */
    public function scopeCustom($query)
    {
        return $query->where('type', 'custom');
    }
}
