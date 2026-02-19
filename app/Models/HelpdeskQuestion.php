<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HelpdeskQuestion extends Model
{
    use SoftDeletes;

    protected $fillable = ['group_id', 'user_id', 'title', 'slug', 'description', 'views', 'is_private'];

    public function group()
    {
        return $this->belongsTo(HelpdeskGroup::class);
    }

    public function replies()
    {
        return $this->hasMany(HelpdeskReply::class, 'question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Boot the model and add slug generation
     */
    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::creating(static function ($question): void {
            if (empty($question->slug)) {
                $question->slug = $question->generateUniqueSlug($question->title);
            }
        });

        static::updating(static function ($question): void {
            if ($question->isDirty('title') && empty($question->slug)) {
                $question->slug = $question->generateUniqueSlug($question->title);
            }
        });
    }

    /**
     * Generate a unique slug from title
     */
    public function generateUniqueSlug($title)
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while (static::where('slug', $slug)->where('id', '!=', $this->id ?? 0)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the route key for the model
     */
    #[\Override]
    public function getRouteKeyName()
    {
        return 'slug';
    }
}
