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

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'thumbnail',
        'intro_video',
        'intro_video_type',
        'user_id',
        'level',
        'course_type',
        'status',
        'price',
        'discount_price',
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sequential_access' => 'boolean',
        'certificate_enabled' => 'boolean',
        'is_free' => 'boolean',
        'is_free_until' => 'datetime',
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'certificate_fee' => 'decimal:2',
    ];

    protected $appends = ['total_tax_percentage', 'display_price', 'display_discount_price', 'tax_amount'];

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
        return !is_null($this->discount_price) && $this->discount_price < $this->price;
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
        // Tax is always exclusive - return base price from database
        $price = $this->price ?? 0;
        return round($price, 2);
    }

    public function getDisplayDiscountPriceAttribute()
    {
        // Tax is always exclusive - return base discount price from database
        $discountPrice = $this->discount_price ?? 0;
        return round($discountPrice, 2);
    }

    public function getTaxAmountAttribute()
    {
        // Tax is always exclusive - calculate tax on base price
        $totalTaxPercentage = $this->getTotalTaxPercentageAttribute();

        // Use discount_price if available, otherwise use price
        $basePrice = $this->discount_price ?? $this->price ?? 0;

        if ($totalTaxPercentage > 0 && $basePrice > 0) {
            // Calculate tax amount from base price (exclusive)
            return round(($basePrice * $totalTaxPercentage) / 100, 2);
        }

        return 0;
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function averageRating()
    {
        return $this->reviews()->avg('rating');
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
}