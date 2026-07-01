<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Course\Course;

class UserCourseProgress extends Model
{
    use HasFactory;

    protected $table = 'user_course_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'completed_items',
        'total_items',
        'progress_percentage',
        'last_accessed_at',
        'status',
    ];

    protected $casts = [
        'progress_percentage' => 'float',
        'last_accessed_at' => 'datetime',
        'completed_items' => 'integer',
        'total_items' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    public function scopeWithProgress($query)
    {
        return $query->where('progress_percentage', '>', 0);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function isCompleted(): bool
    {
        return $this->progress_percentage >= 100;
    }

    public function isStarted(): bool
    {
        return $this->progress_percentage > 0;
    }
}
