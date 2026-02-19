<?php

namespace App\Models\Course\CourseChapter\Quiz;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\User;
use App\Traits\HasChapterOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseChapterQuiz extends Model
{
    use HasFactory, SoftDeletes, HasChapterOrder;

    protected $fillable = [
        'user_id',
        'course_chapter_id',
        'title',
        'slug',
        'description',
        'time_limit',
        'total_points',
        'passing_score',
        'order',
        'chapter_order',
        'is_active',
        'can_skip',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'time_limit' => 'integer',
        'passing_score' => 'integer',
        'total_points' => 'integer',
        'can_skip' => 'boolean',
    ];

    public function chapter()
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class, 'course_chapter_quiz_id');
    }

    public function resources()
    {
        return $this->hasMany(QuizResource::class, 'quiz_id');
    }

    public function attempts()
    {
        return $this->hasMany(UserQuizAttempt::class, 'course_chapter_quiz_id');
    }

    public function getDurationAttribute()
    {
        return $this->time_limit;
    }
}
