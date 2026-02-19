<?php

namespace App\Models;

use App\Models\Course\Course;
use App\Services\FileService;
use App\Services\HelperService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'status',
        'parent_category_id',
        'sequence',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $appends = ['has_subcategory', 'has_parent_category'];

    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::creating(static function ($category): void {
            // If slug is not provided or empty, generate from name
            if (empty($category->slug)) {
                $category->slug = HelperService::generateSlug($category->name);
            } else {
                // Use HelperService to handle Unicode in provided slug
                $category->slug = HelperService::generateSlug($category->slug);
            }
        });

        static::updating(static function ($category): void {
            if ($category->isDirty('name')) {
                // If slug is also being updated, use the provided slug, otherwise generate from name
                if ($category->isDirty('slug') && !empty($category->slug)) {
                    $category->slug = HelperService::generateSlug($category->slug);
                } else {
                    $category->slug = HelperService::generateSlug($category->name);
                }
            } elseif ($category->isDirty('slug') && !empty($category->slug)) {
                // If only slug is being updated
                $category->slug = HelperService::generateSlug($category->slug);
            }

            // Delete old image if new image is uploaded
            if ($category->isDirty('image') && $category->getOriginal('image')) {
                Storage::disk('public')->delete($category->getOriginal('image'));
            }
        });

        static::deleting(static function ($category): void {
            // Delete image when category is deleted
            if ($category->isForceDeleting() && $category->image) {
                Storage::disk('public')->delete($category->image);
            }
        });
    }

    public function parent_category()
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    public function subcategories()
    {
        return $this->hasMany(Category::class, 'parent_category_id')
            ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sequence', 'ASC');
    }

    public function getHasSubcategoryAttribute()
    {
        return $this->subcategories()->where('status', 1)->count() > 0 ? true : false;
    }

    public function getHasParentCategoryAttribute()
    {
        return $this->parent_category_id ? true : false;
    }

    public function getImageAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }

    public function courses()
    {
        return $this->hasMany(Course::class, 'category_id');
    }

    // public function allChildren()
    // {
    //     return $this->children()->with('parent_category_id');
    // }
    // public function subcategories() {
    //     return $this->hasMany(self::class, 'parent_category_id');
    // }
    // public function custom_fields() {
    //     return $this->hasMany(CustomFieldCategory::class);
    // }
}
