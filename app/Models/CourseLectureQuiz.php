<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLectureQuiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_chapter_lecture_id',
        'title',
        'description',
        'time_limit',
        'passing_score',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the lecture that owns the quiz.
     */
    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'course_chapter_lecture_id');
    }

    /**
     * Get the questions for the quiz.
     */
    public function questions()
    {
        return $this->hasMany(CourseLectureQuizQuestion::class, 'course_lecture_quiz_id')->orderBy('order');
    }

    /**
     * Get total points available
     */
    public function getTotalPointsAttribute()
    {
        return $this->questions()->sum('points');
    }

    /**
     * Get formatted time limit
     */
    public function getFormattedTimeLimitAttribute()
    {
        return $this->time_limit . ' minutes';
    }
}
