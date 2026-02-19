<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_chapter_lecture_id',
        'title',
        'description',
        'instructions',
        'due_days',
        'max_file_size',
        'allowed_file_types',
        'points',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the lecture that owns the assignment.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'course_chapter_lecture_id');
    }

    /**
     * Get allowed file types as array
     */
    public function getAllowedFileTypesArrayAttribute()
    {
        return explode(',', $this->allowed_file_types);
    }

    /**
     * Get formatted max file size (in MB)
     */
    public function getFormattedMaxFileSizeAttribute()
    {
        return $this->max_file_size . ' MB';
    }
}
