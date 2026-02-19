<?php

namespace App\Models;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProgress extends Model
{
    protected $table = 'video_progress';

    protected $fillable = [
        'user_id',
        'lecture_id',
        'watched_seconds',
        'total_seconds',
        'last_position',
        'watch_percentage',
        'is_completed',
        'completed_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'watch_percentage' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForLecture($query, int $lectureId)
    {
        return $query->where('lecture_id', $lectureId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }
}
