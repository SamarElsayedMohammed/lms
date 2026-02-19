<?php

namespace App\Models\Course\CourseChapter\Quiz;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizQuestion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'quiz_questions';

    protected $fillable = [
        'id',
        'user_id',
        'course_chapter_quiz_id',
        'question',
        'points',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'points' => 'decimal:2',
    ];

    #[\Override]
    protected static function boot()
    {
        parent::boot();
        static::creating(static function ($model): void {
            $model->order =
                QuizQuestion::where('course_chapter_quiz_id', $model->course_chapter_quiz_id)->max('order') + 1;
        });
    }

    public function quiz()
    {
        return $this->belongsTo(CourseChapterQuiz::class, 'course_chapter_quiz_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function options()
    {
        return $this->hasMany(QuizOption::class, 'quiz_question_id', 'id');
    }

    public function answers()
    {
        return $this->hasMany(UserQuizAnswer::class);
    }
}
