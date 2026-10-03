<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LectureWatchSegment extends Model
{
    protected $fillable = [
        'user_id',
        'lecture_id',
        'start_second',
        'end_second',
    ];

    protected $casts = [
        'start_second' => 'integer',
        'end_second' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }
}
