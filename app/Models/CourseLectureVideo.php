<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureVideo extends Model
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
        'is_active' => 'boolean',
    ];

    /**
     * Get the lecture that owns the video.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'course_chapter_lecture_id');
    }

    /**
     * Format duration to human readable
     */
    public function getFormattedDurationAttribute()
    {
        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
