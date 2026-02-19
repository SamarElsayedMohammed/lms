<?php

namespace App\Models\Course\CourseChapter\Quiz;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserQuizAnswer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'user_quiz_attempt_id',
        'quiz_question_id',
        'quiz_option_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    public function option()
    {
        return $this->belongsTo(QuizOption::class, 'quiz_option_id');
    }

    public function attempt()
    {
        return $this->belongsTo(UserQuizAttempt::class, 'user_quiz_attempt_id');
    }
}
