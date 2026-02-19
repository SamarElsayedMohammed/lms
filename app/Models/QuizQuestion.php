<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizQuestion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
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

    /**
     * Get the quiz that owns the question.
     */
    public function quiz()
    {
        return $this->belongsTo(CourseChapterQuiz::class, 'course_chapter_quiz_id');
    }

    /**
     * Get the options for the question.
     */
    public function options()
    {
        return $this->hasMany(\App\Models\Course\CourseChapter\Quiz\QuizOption::class, 'quiz_question_id')->orderBy(
            'order',
        );
    }
}
