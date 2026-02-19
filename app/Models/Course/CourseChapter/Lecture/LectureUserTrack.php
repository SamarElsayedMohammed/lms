<?php

namespace App\Models\Course\CourseChapter\Lecture;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LectureUserTrack extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'lecture_id',
        'total_duration',
        'duration',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lecture()
    {
        return $this->belongsTo(CourseChapterLecture::class, 'lecture_id');
    }
}
