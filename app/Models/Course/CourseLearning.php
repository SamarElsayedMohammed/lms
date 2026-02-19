<?php

namespace App\Models\Course;

use Illuminate\Database\Eloquent\Model;

class CourseLearning extends Model
{
    protected $fillable = ['course_id', 'title', 'created_at', 'updated_at'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
