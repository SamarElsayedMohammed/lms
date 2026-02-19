<?php

namespace App\Models\Course\CourseChapter\Resource;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Services\FileService;
use App\Traits\HasChapterOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseChapterResource extends Model
{
    use HasChapterOrder, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_chapter_id',
        'title',
        'slug',
        'type',
        'file',
        'file_extension',
        'url',
        'description',
        'duration',
        'order',
        'chapter_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function courseChapter()
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    public function chapter()
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
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
