<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialMedia extends Model
{
    use SoftDeletes;

    protected $table = 'social_medias';
    protected $fillable = [
        'instructor_id',
        'name',
        'icon',
        'icon_extension',
        'url',
    ];

    #[\Override]
    protected static function boot()
    {
        parent::boot();
        static::forceDeleting(static function ($model): void {
            if (!empty($model->getRawOriginal('icon'))) {
                FileService::delete($model->getRawOriginal('icon'));
            }
        });
    }

    public function instructorSocialMedias()
    {
        return $this->hasMany(InstructorSocialMedia::class, 'social_media_id', 'id');
    }

    public function getIconAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
