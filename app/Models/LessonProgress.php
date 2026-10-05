<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $lesson_id
 * @property int $course_id
 * @property int $last_position_seconds
 * @property int $max_position_seconds
 * @property int $watched_seconds
 * @property float $percent
 * @property bool $is_completed
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $last_heartbeat_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 * @property-read CourseChapterLecture $lesson
 * @property-read Course $course
 */
final class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'last_position_seconds',
        'max_position_seconds',
        'watched_seconds',
        'percent',
        'is_completed',
        'watched_intervals',
        'completed_at',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'last_position_seconds' => 'integer',
        'max_position_seconds'  => 'integer',
        'watched_seconds'       => 'integer',
        'percent'               => 'float',
        'is_completed'          => 'boolean',
        'watched_intervals'     => 'array',
        'completed_at'          => 'datetime',
        'last_heartbeat_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lesson_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForLesson(Builder $query, int $lessonId): Builder
    {
        return $query->where('lesson_id', $lessonId);
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }
}
