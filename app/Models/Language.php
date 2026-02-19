<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Language extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_in_english',
        'code',
        'app_file',
        'panel_file',
        'web_file',
        'rtl',
        'image',
        'country_code',
        'is_default',
    ];

    public function getRtlAttribute($rtl)
    {
        return $rtl != 0;
    }

    public function getImageAttribute($value)
    {
        if (!empty($value)) {
            if ($this->code == 'en') {
                return asset('/assets/images/' . $value);
            }
            return url(Storage::url($value));
        }
        return '';
    }

    /**
     * Get the default language
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Set this language as default (and unset others)
     */
    public function setAsDefault()
    {
        // First, unset all other default languages
        static::where('is_default', true)->update(['is_default' => false]);

        // Set this language as default
        $this->update(['is_default' => true]);
    }

    /**
     * Boot method to ensure only one default language
     */
    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::saving(static function ($language): void {
            if ($language->is_default) {
                // Unset all other default languages
                static::where('is_default', true)->where('id', '!=', $language->id)->update(['is_default' => false]);
            }
        });
    }
}
