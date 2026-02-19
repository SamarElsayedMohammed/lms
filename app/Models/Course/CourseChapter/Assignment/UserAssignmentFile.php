<?php

namespace App\Models\Course\CourseChapter\Assignment;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAssignmentFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'user_assignment_submission_id',
        'type',
        'file',
        'file_extension',
        'url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submission()
    {
        return $this->belongsTo(UserAssignmentSubmission::class, 'user_assignment_submission_id');
    }
}
