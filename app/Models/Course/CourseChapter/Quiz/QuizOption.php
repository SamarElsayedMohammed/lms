<?php

namespace App\Models\Course\CourseChapter\Quiz;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'quiz_options';

    protected $fillable = [
        'id',
        'user_id',
        'quiz_question_id',
        'option',
        'is_correct',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_correct' => 'boolean',
    ];

    #[\Override]
    protected static function boot()
    {
        parent::boot();
        static::creating(static function ($model): void {
            $model->order = QuizOption::where('quiz_question_id', $model->quiz_question_id)->max('order') + 1;
        });
    }

    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function answers()
    {
        return $this->hasMany(UserQuizAnswer::class);
    }
}
