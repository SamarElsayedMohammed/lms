<?php

declare(strict_types=1);

namespace App\Models\Course\CourseChapter\Lecture;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLectureNote extends Model
{
    use HasFactory;

    protected $table = 'course_lecture_notes';

    protected $fillable = [
        'user_id',
        'course_id',
        'lecture_id',
        'video_timestamp_seconds',
        'note_text',
    ];

    protected $casts = [
        'video_timestamp_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }
}
