<?php

namespace App\Models;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LectureAttachment extends Model
{
    protected $fillable = [
        'lecture_id',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
        'sort_order',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }
}
