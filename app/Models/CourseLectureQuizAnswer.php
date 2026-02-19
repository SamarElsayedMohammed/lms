<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureQuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_lecture_quiz_question_id',
        'answer_text',
        'is_correct',
        'order',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    /**
     * Get the question that owns the answer.
     */
    public function question()
    {
        return $this->belongsTo(CourseLectureQuizQuestion::class, 'course_lecture_quiz_question_id');
    }
}
