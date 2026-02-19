<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'promo_code',
        'message',
        'start_date',
        'end_date',
        'no_of_users',
        'discount',
        'discount_type',
        'repeat_usage',
        'no_of_repeat_usage',
        'status',
    ];

    protected $casts = [
        'applies_to_all_courses' => 'boolean',
        'repeat_usage' => 'boolean',
        'status' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function courses()
    {
        return $this->belongsToMany(Course\Course::class, 'promo_code_course');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'promo_code_id');
    }
}
