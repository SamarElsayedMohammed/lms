<?php

namespace App\Models;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LectureResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'lecture_id',
        'title',
        'type',
        'file',
        'file_extension',
        'url',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the lecture that owns the resource.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }

    /**
     * Get File URL
     */
    public function getFileUrlAttribute()
    {
        if ($this->type == 'file' && !empty($this->file)) {
            return FileService::getFileUrl($this->file);
        }
        return $this->url;
    }
}
