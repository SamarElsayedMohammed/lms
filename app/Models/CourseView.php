<?php

namespace App\Models;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseView extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'ip_address',
        'user_agent',
        'session_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
