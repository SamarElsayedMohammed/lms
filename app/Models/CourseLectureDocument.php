<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_chapter_lecture_id',
        'title',
        'url',
        'duration',
        'description',
        'is_active',
        'order',
    ];

    protected $casts = [
        'url' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the lecture that owns the document.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'course_chapter_lecture_id');
    }

    /**
     * Get document file count
     */
    public function getFileCountAttribute()
    {
        return count($this->url);
    }
}
