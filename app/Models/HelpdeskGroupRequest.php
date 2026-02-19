<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpdeskGroupRequest extends Model
{
    protected $fillable = ['group_id', 'user_id', 'status'];

    public function group()
    {
        return $this->belongsTo(HelpdeskGroup::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
