<?php

namespace App\Models;

use App\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'language_id',
        'title',
        'page_type',
        'slug',
        'page_content',
        'page_icon',
        'og_image',
        'schema_markup',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'is_custom',
        'is_termspolicy',
        'is_privacypolicy',
        'status',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Get the language that owns the page.
     */
    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
