<?php

namespace App\Models\Course\CourseChapter\Quiz;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserQuizAttempt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_chapter_quiz_id',
        'total_time',
        'time_taken',
        'score',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'total_time' => 'integer',
        'time_taken' => 'integer',
        'score' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quiz()
    {
        return $this->belongsTo(CourseChapterQuiz::class, 'course_chapter_quiz_id');
    }

    public function answers()
    {
        return $this->hasMany(UserQuizAnswer::class, 'user_quiz_attempt_id');
    }
}
