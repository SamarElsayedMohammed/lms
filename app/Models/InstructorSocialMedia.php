<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorSocialMedia extends Model
{
    protected $table = 'instructor_social_medias';
    protected $fillable = [
        'instructor_id',
        'social_media_id',
        'title',
        'url',
    ];

    public function instructor()
    {
        return $this->belongsTo(Instructor::class, 'instructor_id', 'id');
    }

    public function social_media()
    {
        return $this->belongsTo(SocialMedia::class, 'social_media_id', 'id');
    }
}
