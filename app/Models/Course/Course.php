<?php

namespace App\Models\Course;

use App\Models\Category;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\PromoCode;
use App\Models\Rating;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\User;
use App\Services\FileService;
use App\Services\HelperService;
use App\Traits\ProtectsDemoData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes, ProtectsDemoData;

    protected static function newFactory()
    {
        return \Database\Factories\CourseFactory::new();
    }

    protected $fillable = [
        'title',
        'import_code',
        'slug',
        'short_description',
        'thumbnail',
        'intro_video',
        'intro_video_type',
        'user_id',
        'level',
        'course_type',
        'status',
        'category_id',
        'is_active',
        'sequential_access',
        'content_structure',
        'certificate_enabled',
        'certificate_fee',
        'approval_status',
        'is_free',
        'is_free_until',
        'language_id',
        'meta_title',
        'meta_image',
        'meta_description',
        'meta_keywords',
        'is_featured',
        'ai_knowledge_file',
        'ai_knowledge_content',
        'chatbot_enabled',
        'chatbot_name',
        'chatbot_welcome_message',
        'chatbot_system_prompt',
        'chatbot_max_tokens',
        'ai_processing_status',
        'ai_chunk_count',
        'ai_indexed_at',
        'ai_failed_at',
        'ai_failure_reason',
        'initial_views',
        'initial_students',
        'initial_rating',
        'duration_seconds',
        'lectures_count',
    ];

    /**
     * Default values for model attributes.
     */
    protected $attributes = [
        'certificate_enabled' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sequential_access' => 'boolean',
        'certificate_enabled' => 'boolean',
        'is_free' => 'boolean',
        'is_free_until' => 'datetime',
        'certificate_fee' => 'decimal:2',
        'is_featured' => 'boolean',
        'chatbot_enabled' => 'boolean',
        'chatbot_max_tokens' => 'integer',
        'initial_views' => 'integer',
        'initial_students' => 'integer',
        'initial_rating' => 'decimal:1',
    ];

    protected $appends = ['total_tax_percentage', 'tax_amount'];

    protected $with = ['taxes'];

    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::forceDeleting(static function ($course): void {
            FileService::delete($course->thumbnail);
            FileService::delete($course->intro_video);
            FileService::delete($course->meta_image);
            $course->learnings()->delete();
            $course->requirements()->delete();
            $course->chapters()->delete();
            $course->tags()->detach();
            $course->instructors()->detach();
        });
    }

    /**
     * Get the user who owns the course.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the category that owns the course.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the chapters for the course.
     */
    public function chapters()
    {
        return $this->hasMany(CourseChapter::class)->orderBy('chapter_order');
    }

    /**
     * Get the learnings for the course.
     */
    public function learnings()
    {
        return $this->hasMany(CourseLearning::class, 'course_id', 'id');
    }

    /**
     * Get the requirements for the course.
     */
    public function requirements()
    {
        return $this->hasMany(CourseRequirement::class, 'course_id', 'id');
    }

    /**
     * Get the tags for the course.
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'course_tags', 'course_id', 'tag_id');
    }

    /**
     * Get the instructors for the course.
     */
    public function instructors()
    {
        return $this->belongsToMany(User::class, 'course_instructors', 'course_id', 'user_id');
    }

    /**
     * Get the team members for the course.
     */
    public function team_members()
    {
        return $this->hasManyThrough(
            \App\Models\TeamMember::class,
            \App\Models\Instructor::class,
            'user_id', // Foreign key on instructors table
            'instructor_id', // Foreign key on team_members table
            'user_id', // Local key on courses table
            'id', // Local key on instructors table
        )->where('team_members.status', 'approved');
    }

    /**
     * Get all team members for the course (regardless of status).
     */
    public function all_team_members()
    {
        return $this->hasManyThrough(
            \App\Models\TeamMember::class,
            \App\Models\Instructor::class,
            'user_id', // Foreign key on instructors table
            'instructor_id', // Foreign key on team_members table
            'user_id', // Local key on courses table
            'id', // Local key on instructors table
        );
    }

    /**
     * Get the language for the course.
     */
    public function language()
    {
        return $this->belongsTo(CourseLanguage::class, 'language_id');
    }

    /**
     * Check if course has a discount
     */
    public function getHasDiscountAttribute()
    {
        return false;
    }

    public function getThumbnailAttribute($value)
    {
        // Return full URL for course thumbnail if it exists
        // Don't fall back to default logo - return null if course has no thumbnail
        if (!empty($value)) {
            // Always return full URL, regardless of file existence
            // This ensures API responses always have full URLs
            return FileService::getFileUrl($value);
        }
        // Return null if course has no thumbnail (don't use default logo)
        return null;
    }

    public function getMetaImageAttribute($value)
    {
        if (!empty($value)) {
            // Always return full URL, regardless of file existence
            // This ensures API responses always have full URLs
            return FileService::getFileUrl($value);
        }
        // Always return full URL for default logo
        $defaultLogo = HelperService::getDefaultLogo();
        // Ensure default logo is always a full URL
        if ($defaultLogo && !filter_var($defaultLogo, FILTER_VALIDATE_URL)) {
            return FileService::getFileUrl($defaultLogo);
        }
        return $defaultLogo;
    }

    public function getIntroVideoAttribute($value)
    {
        if (!$value) {
            return null;
        }
        // If type is 'url', return the raw URL directly
        if ($this->attributes['intro_video_type'] === 'url') {
            return $value;
        }
        return FileService::getFileUrl($value);
    }

    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'course_tax')->withTimestamps();
    }

    public function getTotalTaxPercentageAttribute()
    {
        // Try to get country code from authenticated user (for API requests)
        $countryCode = null;
        try {
            if (auth('sanctum')->check()) {
                $countryCode = auth('sanctum')->user()->country_code ?? null;
            } elseif (auth('web')->check()) {
                $countryCode = auth('web')->user()->country_code ?? null;
            }
        } catch (\Exception) {
            // If auth fails, continue with null country code
        }

        // Use Tax model's method to get tax percentage by country
        return Tax::getTotalTaxPercentageByCountry($countryCode);
    }

    public function getDisplayPriceAttribute()
    {
        return 0;
    }

    public function getDisplayDiscountPriceAttribute()
    {
        return 0;
    }

    public function getTaxAmountAttribute()
    {
        return 0;
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function averageRating()
    {
        return $this->ratings()->where('status', 'approved')->avg('rating');
    }

    public function promoCodes()
    {
        return $this->belongsToMany(PromoCode::class, 'promo_code_course');
    }

    public function wishlistedByUsers()
    {
        return $this->belongsToMany(User::class, 'wishlists', 'course_id', 'user_id')->withTimestamps();
    }

    public function wishlists()
    {
        return $this->hasMany(\App\Models\Wishlist::class, 'course_id', 'id');
    }

    public function orderCourses()
    {
        return $this->hasMany(\App\Models\OrderCourse::class, 'course_id', 'id');
    }

    public function getEnrolledStudents()
    {
        return User::whereHas('orders.orderCourses', function ($query): void {
            $query->where('course_id', $this->id)->whereHas('order', static function ($orderQuery): void {
                $orderQuery->where('status', 'completed');
            });
        })->get();
    }

    public function getActiveStudentsQuery()
    {
        return User::where(function ($query) {
            $query->whereHas('orders.orderCourses', function ($q) {
                $q->where('course_id', $this->id)->whereHas('order', function ($oq) {
                    $oq->where('status', 'completed');
                });
            })->orWhereHas('courseProgress', function ($q) {
                $q->where('course_id', $this->id);
            });
        });
    }

    public function views()
    {
        return $this->hasMany(\App\Models\CourseView::class);
    }



    public function getViewCountAttribute()
    {
        return $this->views()->count();
    }

    public function getUniqueViewCountAttribute()
    {
        return $this->views()->distinct('ip_address')->count();
    }

    /**
     * Check if course is free for access (permanently or temporarily).
     */
    public function isFreeNow(): bool
    {
        if ($this->is_free) {
            return true;
        }

        return $this->is_free_until !== null && now()->lt($this->is_free_until);
    }

    public function hasContent(): bool
    {
        if ($this->relationLoaded('chapters')) {
            foreach ($this->chapters as $chapter) {
                if (!(bool) ($chapter->is_active ?? true)) {
                    continue;
                }
                if ($chapter->relationLoaded('lectures') && $chapter->lectures->isNotEmpty()) {
                    return true;
                }
                if ($chapter->relationLoaded('resources') && $chapter->resources->isNotEmpty()) {
                    return true;
                }
            }
        }

        return $this->chapters()
            ->where('is_active', true)
            ->where(static function ($chapterQuery): void {
                $chapterQuery
                    ->whereHas('lectures', static function ($lectureQuery): void {
                        $lectureQuery->where('is_active', true);
                    })
                    ->orWhereHas('quizzes', static function ($quizQuery): void {
                        $quizQuery->where('is_active', true);
                    })
                    ->orWhereHas('assignments', static function ($assignmentQuery): void {
                        $assignmentQuery->where('is_active', true);
                    })
                    ->orWhereHas('resources', static function ($resourceQuery): void {
                        $resourceQuery->where('is_active', true);
                    });
            })
            ->exists();
    }

    /**
     * Scope to filter courses that have at least one active chapter with content.
     */
    public function scopeWhereHasContent($query)
    {
        return $query->whereHas('chapters', static function ($chapterQuery): void {
            $chapterQuery
                ->where('is_active', true)
                ->where(static function ($q): void {
                    $q->whereHas('lectures', static function ($lectureQuery): void {
                        $lectureQuery->where('is_active', true);
                    })
                    ->orWhereHas('quizzes', static function ($quizQuery): void {
                        $quizQuery->where('is_active', true);
                    })
                    ->orWhereHas('assignments', static function ($assignmentQuery): void {
                        $assignmentQuery->where('is_active', true);
                    })
                    ->orWhereHas('resources', static function ($resourceQuery): void {
                        $resourceQuery->where('is_active', true);
                    });
                });
        });
    }

    /**
     * Recalculate duration and lecture count for the course.
     */
    public function recalculateDuration(): void
    {
        $chapters = $this->chapters()->get();
        $totalDuration = 0;
        $totalLectures = 0;

        foreach ($chapters as $chapter) {
            $totalDuration += $chapter->duration_seconds;
            $totalLectures += $chapter->lectures()->count();
        }

        // updateQuietly prevents any course-level observers re-triggering this method.
        $this->updateQuietly([
            'duration_seconds' => $totalDuration,
            'lectures_count' => $totalLectures,
        ]);
    }

    /**
     * Authoritative course entitlement check for subscriber course assistant and course access.
     */
    public function isUserEntitled(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return app(\App\Services\ContentAccessService::class)->canAccessCourse($user, $this);
    }
}