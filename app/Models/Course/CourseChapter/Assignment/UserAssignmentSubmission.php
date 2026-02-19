<?php

namespace App\Models\Course\CourseChapter\Assignment;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAssignmentSubmission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_chapter_assignment_id',
        'status',
        'feedback',
        'points',
        'comment',
    ];

    protected $casts = [
        'points' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignment()
    {
        return $this->belongsTo(CourseChapterAssignment::class, 'course_chapter_assignment_id');
    }

    public function files()
    {
        return $this->hasMany(UserAssignmentFile::class, 'user_assignment_submission_id');
    }
}
