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
        return is_array($this->url) ? count($this->url) : 0;
    }

    /**
     * Get the full URLs for the documents
     */
    public function getUrlAttribute($value)
    {
        $urls = json_decode((string)$value, true);
        if (!is_array($urls)) {
            return [];
        }

        return array_map(function($url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
            return \App\Services\FileService::getFileUrl($url);
        }, $urls);
    }
}
