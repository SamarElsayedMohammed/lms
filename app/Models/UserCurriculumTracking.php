<?php

namespace App\Models;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCurriculumTracking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_chapter_id',
        'model_id',
        'model_type',
        'status',
        'started_at',
        'completed_at',
        'time_spent',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chapter()
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    /**
     * Get the polymorphic relationship to the tracked item
     */
    public function trackable()
    {
        return $this->morphTo('trackable', 'model_type', 'model_id');
    }

    /**
     * Get the model type as a short name (e.g., 'lecture', 'quiz')
     */
    public function getModelTypeShortAttribute()
    {
        return match ($this->model_type) {
            \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class => 'lecture',
            \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class => 'quiz',
            \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::class => 'assignment',
            \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class => 'resource',
            default => 'unknown',
        };
    }

    /**
     * Get the model class name (e.g., 'CourseChapterLecture')
     */
    public function getModelClassNameAttribute()
    {
        return class_basename($this->model_type);
    }

    /**
     * Scope for completed items
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for in progress items
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope for specific model type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('model_type', $type);
    }
}
