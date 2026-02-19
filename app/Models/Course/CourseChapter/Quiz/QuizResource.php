<?php

namespace App\Models\Course\CourseChapter\Quiz;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quiz_id',
        'title',
        'slug',
        'type',
        'file',
        'file_extension',
        'url',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function quiz()
    {
        return $this->belongsTo(CourseChapterQuiz::class, 'course_chapter_quiz_id');
    }
}
