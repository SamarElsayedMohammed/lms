<?php

namespace App\Models\Course\CourseChapter\Assignment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignmentResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'assignment_id',
        'title',
        'slug',
        'type',
        'file',
        'file_extension',
        'url',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function assignment()
    {
        return $this->belongsTo(CourseChapterAssignment::class, 'assignment_id');
    }
}
