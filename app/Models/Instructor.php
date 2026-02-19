<?php

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Model;

class Instructor extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'status',
        'reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function personal_details()
    {
        return $this->hasOne(InstructorPersonalDetail::class, 'instructor_id', 'id');
    }

    public function social_medias()
    {
        return $this->hasMany(InstructorSocialMedia::class, 'instructor_id', 'id');
    }

    public function other_details()
    {
        return $this->hasMany(InstructorOtherDetail::class, 'instructor_id', 'id');
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'course_instructors', 'user_id', 'course_id');
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function averageRating()
    {
        return $this->reviews()->avg('rating');
    }
}
