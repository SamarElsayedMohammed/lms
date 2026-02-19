<?php

namespace App\Models\Course;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CourseDiscussion extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'message',
        'parent_id',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function replies()
    {
        return $this->hasMany(CourseDiscussion::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(CourseDiscussion::class, 'parent_id');
    }
}
