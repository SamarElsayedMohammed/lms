<?php

namespace App\Models\Course;

use Illuminate\Database\Eloquent\Model;

class CourseTag extends Model
{
    protected $fillable = ['course_id', 'tag'];

    /**
     * Get the course for the course tag.
     */
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /*
     * Get the tag for the course tag.
     */
    public function tag()
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
