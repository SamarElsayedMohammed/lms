<?php

namespace App\Models\Course;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseLanguage extends Model
{
    use SoftDeletes;

    protected $table = 'course_languages';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    /**
     * Get the courses for the language.
     */
    public function courses()
    {
        return $this->hasMany(Course::class, 'language_id');
    }
}
