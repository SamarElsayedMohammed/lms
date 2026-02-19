<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $fillable = ['instructor_id', 'user_id', 'status', 'invitation_token'];

    /**
     * Relationships
     */
    public function instructor()
    {
        return $this->belongsTo(Instructor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
