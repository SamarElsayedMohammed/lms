<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'language_id',
        'page_type',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'schema_markup',
        'og_image',
    ];

    protected $casts = [
        'meta_keywords' => 'array',
    ];

    /**
     * Get the language that owns the SEO setting.
     */
    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Get page type options
     */
    public static function getPageTypes()
    {
        return [
            'home' => 'Home',
            'courses' => 'Courses',
            'instructor' => 'Instructor',
            'help_and_support' => 'Help and Support',
            'all_categories' => 'All Categories',
            'search_page' => 'Search page',
            'contact_us' => 'Contact Us',
        ];
    }
}
