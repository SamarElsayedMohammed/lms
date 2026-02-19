<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HelpdeskReply extends Model
{
    use SoftDeletes;

    protected $fillable = ['question_id', 'user_id', 'reply', 'parent_id'];

    public function question()
    {
        return $this->belongsTo(HelpdeskQuestion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(HelpdeskReply::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(HelpdeskReply::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(HelpdeskReply::class, 'question_id');
    }
}
