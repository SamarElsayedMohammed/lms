<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'quiz_question_id',
        'option',
        'is_correct',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the question that owns the option.
     */
    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
