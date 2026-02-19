<?php

namespace App\Models\Course;

use Illuminate\Database\Eloquent\Model;

class CourseRequirement extends Model
{
    protected $fillable = ['course_id', 'requirement', 'created_at', 'updated_at'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
