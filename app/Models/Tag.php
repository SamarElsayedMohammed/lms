<?php

namespace App\Models;

use App\Models\Course\Course;
use App\Services\HelperService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = ['tag', 'slug', 'is_active'];

    #[\Override]
    protected static function boot()
    {
        parent::boot();
        static::creating(static function ($model): void {
            $model->slug = HelperService::generateUniqueSlug(Tag::class, $model->tag);
        });
        static::updating(static function ($model): void {
            $model->slug = HelperService::generateUniqueSlug(Tag::class, $model->tag, $model->id);
        });
    }

    /**
     * Get the courses for the tag.
     */
    public function courses()
    {
        return $this->belongsToMany(Course::class, 'course_tags', 'tag_id', 'course_id');
    }
}
