<?php

namespace App\Models;

use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_quiz_attempt_id',
        'certificate_number',
        'issued_date',
    ];

    public function attempt()
    {
        return $this->belongsTo(UserQuizAttempt::class, 'user_quiz_attempt_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
