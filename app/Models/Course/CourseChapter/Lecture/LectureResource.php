<?php

namespace App\Models\Course\CourseChapter\Lecture;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LectureResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lecture_id',
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
     * Get the course chapter that owns the resource.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }

    /**
     * Get document file count
     */
    public function getFileCountAttribute()
    {
        return count($this->url);
    }

    /**
     * Get File URl
     */
    public function getFileAttribute($value)
    {
        if ($this->type == 'file') {
            return FileService::getFileUrl($value);
        }
        return $value;
    }
}
