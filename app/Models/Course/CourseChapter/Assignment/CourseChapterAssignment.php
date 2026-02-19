<?php

namespace App\Models\Course\CourseChapter\Assignment;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\User;
use App\Services\HelperService;
use App\Traits\HasChapterOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseChapterAssignment extends Model
{
    use HasFactory, SoftDeletes, HasChapterOrder;

    protected $fillable = [
        'user_id',
        'course_chapter_id',
        'title',
        'slug',
        'description',
        'instructions',
        'media',
        'media_extension',
        'max_file_size',
        'allowed_file_types',
        'points',
        'order',
        'chapter_order',
        'is_active',
        'can_skip',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'points' => 'decimal:2',
        'max_file_size' => 'integer',
        'can_skip' => 'boolean',
    ];

    #[\Override]
    protected static function boot()
    {
        parent::boot();
        static::creating(static function ($model): void {
            $model->slug = HelperService::generateUniqueSlug(CourseChapterAssignment::class, $model->title);
        });
    }

    public function chapter()
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resources()
    {
        return $this->hasMany(AssignmentResource::class, 'assignment_id');
    }

    public function submissions()
    {
        return $this->hasMany(UserAssignmentSubmission::class, 'course_chapter_assignment_id');
    }

    public function getAllowedFileTypesAttribute($value)
    {
        return explode(',', (string) $value);
    }
}
