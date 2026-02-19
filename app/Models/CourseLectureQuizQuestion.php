<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureQuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_lecture_quiz_id',
        'question_text',
        'question_type',
        'points',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the quiz that owns the question.
     */
    public function quiz()
    {
        return $this->belongsTo(CourseLectureQuiz::class, 'course_lecture_quiz_id');
    }

    /**
     * Get the answers for the question.
     */
    public function answers()
    {
        return $this->hasMany(CourseLectureQuizAnswer::class, 'course_lecture_quiz_question_id')->orderBy('order');
    }

    /**
     * Get the correct answers for the question.
     */
    public function correctAnswers()
    {
        return $this->answers()->where('is_correct', true)->get();
    }

    /**
     * Check if question is multiple choice
     */
    public function getIsMultipleChoiceAttribute()
    {
        return $this->question_type === 'multiple_choice';
    }
}
